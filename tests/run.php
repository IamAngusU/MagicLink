<?php
declare(strict_types=1);

use IamAngusU\MagicLink\App;
use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Exception\PayloadTooLarge;
use IamAngusU\MagicLink\Exception\RateLimited;
use IamAngusU\MagicLink\HandoffService;
use IamAngusU\MagicLink\Http\Request;
use IamAngusU\MagicLink\Http\Response;
use IamAngusU\MagicLink\Mail\MagicLinkMessage;
use IamAngusU\MagicLink\Mail\Mailer;
use IamAngusU\MagicLink\Mail\OutboxWorker;
use IamAngusU\MagicLink\MagicLinkService;
use IamAngusU\MagicLink\MagicLinkState;
use IamAngusU\MagicLink\MaintenanceService;
use IamAngusU\MagicLink\Session;
use IamAngusU\MagicLink\StateCatalog;
use IamAngusU\MagicLink\Tuning;

$root = dirname(__DIR__);
require $root . '/autoload.php';

final class TestMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,html:string,plain:string}> */
    public array $messages = [];

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        $this->messages[] = compact('to', 'subject', 'html', 'plain');
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidLink) {
        return;
    }
    throw new RuntimeException($message);
}

function expectRateLimited(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RateLimited $error) {
        expect($error->retryAfter > 0, 'Rate limit must advertise a retry delay.');
        return;
    }
    throw new RuntimeException($message);
}

/** @return array{0:string,1:string} */
function messageCredentials(array $message): array
{
    expect((bool) preg_match('~id=(ml_[a-f0-9]{24})#token=([A-Za-z0-9_-]+)~', (string) $message['plain'], $match), 'Message must contain selector and fragment token.');
    return [$match[1], $match[2]];
}

/** @param array<string,mixed> $post @param array<string,string> $headers */
function dispatch(App $app, string $method, string $uri, array $post = [], array $headers = []): Response
{
    $_GET = [];
    $_POST = $post;
    $parts = parse_url($uri);
    parse_str((string) ($parts['query'] ?? ''), $_GET);
    $_SERVER = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'REMOTE_ADDR' => '127.0.0.9',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ];
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $route = new ReflectionMethod($app, 'route');
    return $route->invoke($app, new Request());
}

/** @return array<string,mixed> */
function responseJson(Response $response): array
{
    $payload = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
    expect(is_array($payload), 'Response must contain a JSON object.');
    return $payload;
}

$temporary = sys_get_temp_dir() . '/magic-link-test-' . bin2hex(random_bytes(6));
mkdir($temporary . '/storage', 0700, true);
mkdir($temporary . '/sessions', 0700, true);
session_save_path($temporary . '/sessions');

$databaseDriver = strtolower(trim((string) (getenv('MAGICLINK_TEST_DB_DRIVER') ?: 'sqlite')));
$databaseSettings = $databaseDriver === 'mysql' ? [
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => (string) (getenv('MAGICLINK_TEST_DB_HOST') ?: '127.0.0.1'),
    'DB_PORT' => (string) (getenv('MAGICLINK_TEST_DB_PORT') ?: '3306'),
    'DB_DATABASE' => (string) (getenv('MAGICLINK_TEST_DB_DATABASE') ?: 'magic_link_test'),
    'DB_USERNAME' => (string) (getenv('MAGICLINK_TEST_DB_USERNAME') ?: 'magic_link'),
    'DB_PASSWORD' => (string) (getenv('MAGICLINK_TEST_DB_PASSWORD') ?: ''),
] : [
    'DB_DRIVER' => 'sqlite',
    'DB_PATH' => 'storage/test.sqlite',
];

$config = Config::fromArray($temporary, $databaseSettings + [
    'APP_ENV' => 'test',
    'APP_URL' => 'http://127.0.0.1:8080',
    'APP_NAME' => 'Test Link',
    'APP_LOCALE' => 'en',
    'MAIL_TRANSPORT' => 'log',
    'MAIL_FROM_ADDRESS' => 'test@example.com',
    'MAGICLINK_ALLOWED_EMAILS' => 'owner@example.com',
    'MAGICLINK_ALLOWED_DOMAINS' => 'example.com',
    'MAGICLINK_IP_LIMIT' => '100',
    'MAGICLINK_EMAIL_LIMIT' => '100',
    'MAGICLINK_EXCHANGE_IP_LIMIT' => '100',
    'MAGICLINK_EXCHANGE_SELECTOR_LIMIT' => '3',
    'MAGICLINK_EXCHANGE_WINDOW' => '900',
]);
$database = Database::connect($config);
$database->migrate();
$crypto = new Crypto($config->appKey());
$mailer = new TestMailer();
$service = new MagicLinkService($database->pdo(), $config, $crypto);
$handoffService = new HandoffService($database->pdo(), $config, $crypto);
$outbox = new OutboxWorker($database->pdo(), $config, $crypto, new MagicLinkMessage($config, $mailer));
$maintenance = new MaintenanceService($database->pdo(), $config);
$tuning = new Tuning($config, $database->pdo());
$app = new App($config, $service, $handoffService, new StateCatalog($root, 'en'), $outbox, $maintenance, $tuning);
Session::start($config);

// Queue boundary: public requests do no network mail work and denied identities look identical.
$request = $service->request('Owner@Example.com', 'browser-a', '127.0.0.1');
expect($request['state'] === MagicLinkState::Waiting->value, 'New request must wait.');
expect(count($mailer->messages) === 0, 'Request path must not send mail synchronously.');
expect((int) $database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE status = 'pending'")->fetchColumn() === 1, 'Allowed request must enter the outbox.');
$delivery = $outbox->run(1);
expect($delivery['sent'] === 1 && count($mailer->messages) === 1, 'Worker must deliver one queued message.');
expect($mailer->messages[0]['to'] === 'owner@example.com', 'Email must be normalized.');
[$selector, $token] = messageCredentials($mailer->messages[0]);

$state = $service->state($selector, 'browser-a');
expect($state['state'] === MagicLinkState::Waiting->value, 'Original browser must see waiting state.');
expect(!array_key_exists('email', $state), 'Polling must never expose identity data.');
expectInvalid(fn () => $service->state($selector, 'browser-b'), 'A different browser must not poll another request.');

$verified = $service->exchange($selector, $token, '127.0.0.2');
expect($verified['email'] === 'owner@example.com', 'Token holder must receive the normalized identity.');
$verifiedState = $service->state($selector, 'browser-a');
expect($verifiedState['verified'] === true && !array_key_exists('email', $verifiedState), 'Requester may observe verification but must not receive identity.');
expectInvalid(fn () => $service->exchange($selector, $token, '127.0.0.2'), 'Token replay must fail.');

$denied = $service->request('stranger@example.net', 'browser-c', '127.0.0.3');
expect(count($mailer->messages) === 1, 'Denied request must not send mail synchronously.');
expect($outbox->run(10)['claimed'] === 0, 'Decoy outbox rows must never be delivered.');
expect($service->state($denied['selector'], 'browser-c')['state'] === MagicLinkState::Waiting->value, 'Denied identity must receive the enumeration-safe waiting state.');

// Abuse boundary: a known selector cannot create unbounded rejection audit rows.
$abuse = $service->request('owner@example.com', 'browser-abuse', '127.0.0.4');
$outbox->run(10);
$auditBefore = (int) $database->pdo()->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
for ($attempt = 0; $attempt < 3; $attempt++) {
    expectInvalid(fn () => $service->exchange($abuse['selector'], str_repeat('x', 43), '127.0.0.44'), 'Incorrect token must fail.');
}
expectRateLimited(fn () => $service->exchange($abuse['selector'], str_repeat('y', 43), '127.0.0.44'), 'Selector attempts must be bounded.');
$auditAfter = (int) $database->pdo()->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
expect($auditAfter - $auditBefore === 3, 'Rate-limited selector must not append another rejection audit row.');

// Cross-device invariant: only the browser presenting the secret becomes authenticated.
$handoff = $service->request('owner@example.com', 'browser-requester', '127.0.0.5');
$outbox->run(10);
[$handoffSelector, $handoffToken] = messageCredentials($mailer->messages[array_key_last($mailer->messages)]);
expect($handoffSelector === $handoff['selector'], 'Delivered selector must match the queued request.');

$_SESSION = ['_binding' => 'browser-clicker', '_csrf' => 'csrf-clicker', '_created_at' => time(), '_last_seen_at' => time()];
$exchangeResponse = dispatch($app, 'POST', '/api/v1/exchange', [
    'id' => $handoffSelector,
    'token' => $handoffToken,
    '_csrf' => 'csrf-clicker',
], ['Origin' => 'http://127.0.0.1:8080']);
expect($exchangeResponse->status() === 202, 'A different browser must require identity confirmation.');
$confirmationPayload = responseJson($exchangeResponse);
expect(($confirmationPayload['code'] ?? '') === 'magic_link.confirmation_required', 'The API must distinguish confirmation from authentication.');
expect($confirmationPayload['data']['email'] === 'owner@example.com' && $confirmationPayload['data']['account_switch'] === false, 'Confirmation must identify the actual account.');
expect(Session::email() === null && !$service->state($handoffSelector, 'browser-requester')['verified'], 'Preview must not authenticate or consume the link.');
$confirmation = $confirmationPayload['data']['confirmation'];
$exchangeResponse = dispatch($app, 'POST', '/api/v1/exchange', [
    'id' => $handoffSelector,
    'token' => $handoffToken,
    'confirmation' => $confirmation,
    '_csrf' => 'csrf-clicker',
], ['Origin' => 'http://127.0.0.1:8080']);
expect($exchangeResponse->status() === 200, 'Deliberately confirmed cross-device exchange must succeed.');
expect(Session::email() === 'owner@example.com', 'Link-presenting browser must become authenticated.');
expect(!Session::consumeLinkConfirmation($confirmation, $handoffSelector, $handoffToken), 'A confirmation must be single-use.');

// Consent is enforced server-side for account switches, even in the issuing
// browser. A client-supplied boolean cannot skip the exact-link challenge.
$queuedCredentials = static function (string $email, string $binding) use ($service, $database, $crypto): array {
    $link = $service->request($email, $binding, '192.0.2.45');
    $query = $database->pdo()->prepare('SELECT payload_cipher FROM mail_outbox WHERE selector = ?');
    $query->execute([$link['selector']]);
    $mail = json_decode($crypto->decrypt((string) $query->fetchColumn()), true, 8, JSON_THROW_ON_ERROR);
    parse_str((string) parse_url($mail['url'], PHP_URL_FRAGMENT), $fragment);
    return [$link['selector'], $fragment['token']];
};
$exchangeInput = static fn (string $id, string $secret, array $extra = []): array => $extra + ['id' => $id, 'token' => $secret, '_csrf' => Session::csrf()];
[$switchId, $switchToken] = $queuedCredentials('attacker@example.com', Session::binding());
$switchResponse = dispatch($app, 'POST', '/auth/exchange', $exchangeInput($switchId, $switchToken, ['confirmed' => 'true']));
$switchPayload = responseJson($switchResponse);
expect($switchResponse->status() === 202 && $switchPayload['data']['account_switch'] === true, 'An issuing browser must explicitly confirm a different identity.');
expect(Session::email() === 'owner@example.com', 'A preview must preserve the existing identity.');
$switchConfirmation = $switchPayload['data']['confirmation'];
$foreignSession = $_SESSION;
$_SESSION = ['_binding' => 'foreign-browser', '_csrf' => 'foreign-csrf'];
$foreignResponse = dispatch($app, 'POST', '/api/v1/exchange', $exchangeInput($switchId, $switchToken, ['confirmation' => $switchConfirmation]));
expect($foreignResponse->status() === 409 && Session::email() === null, 'A copied challenge must not work in another browser.');
$_SESSION = $foreignSession;
$csrfFailure = dispatch($app, 'POST', '/api/v1/exchange', $exchangeInput($switchId, $switchToken, ['confirmation' => $switchConfirmation, '_csrf' => 'wrong']));
expect($csrfFailure->status() === 419 && Session::email() === 'owner@example.com', 'Confirmation must retain CSRF protection.');
$switchResult = dispatch($app, 'POST', '/api/v1/exchange', $exchangeInput($switchId, $switchToken, ['confirmation' => $switchConfirmation]));
expect($switchResult->status() === 200 && Session::email() === 'attacker@example.com', 'An explicit account switch must remain possible.');

// Same-browser normal login remains automatic when no identity is replaced.
unset($_SESSION['auth_email']);
[$sameId, $sameToken] = $queuedCredentials('same-browser@example.com', Session::binding());
$sameResponse = dispatch($app, 'POST', '/api/v1/exchange', $exchangeInput($sameId, $sameToken));
expect($sameResponse->status() === 200 && Session::email() === 'same-browser@example.com', 'A matching requester binding must retain the automatic flow.');

// Challenge scope includes link, secret, browser binding, identity and expiry.
$challengeCases = [
    ['selector' => 'ml_' . str_repeat('a', 24)],
    ['token_hash' => hash('sha256', 'different-secret')],
    ['binding' => 'another-binding'],
    ['identity' => 'another-identity@example.com'],
    ['expires_at' => time() - 1],
];
foreach ($challengeCases as $changed) {
    $challenge = Session::beginLinkConfirmation($sameId, $sameToken, time() + 900);
    $_SESSION['_link_confirmations'][$challenge] = $changed + $_SESSION['_link_confirmations'][$challenge];
    expect(!Session::consumeLinkConfirmation($challenge, $sameId, $sameToken), 'A changed or expired confirmation must fail closed.');
}
for ($index = 0; $index < 12; $index++) {
    Session::beginLinkConfirmation($sameId, $sameToken, time() + 900);
}
expect(count($_SESSION['_link_confirmations']) === 8, 'Pending confirmations must have a fixed session-memory bound.');

[$expiresId, $expiresToken] = $queuedCredentials('expires-before-confirm@example.com', 'another-browser');
$expiresPreview = dispatch($app, 'POST', '/api/v1/exchange', $exchangeInput($expiresId, $expiresToken));
$expiresPayload = responseJson($expiresPreview);
$expireLink = $database->pdo()->prepare('UPDATE magic_links SET expires_at = ? WHERE selector = ?');
$expireLink->execute([time() - 1, $expiresId]);
$expiresResponse = dispatch($app, 'POST', '/api/v1/exchange', $exchangeInput($expiresId, $expiresToken, ['confirmation' => $expiresPayload['data']['confirmation']]));
expect($expiresResponse->status() === 410 && Session::email() === 'same-browser@example.com', 'Final exchange must revalidate token expiry without replacing the current identity.');

$_SESSION = [
    '_binding' => 'browser-requester',
    '_csrf' => 'csrf-requester',
    '_magic_requests' => [$handoffSelector => time()],
    '_created_at' => time(),
    '_last_seen_at' => time(),
];
$stateResponse = dispatch($app, 'GET', '/api/v1/state?id=' . rawurlencode($handoffSelector));
$statePayload = responseJson($stateResponse);
expect($stateResponse->status() === 200 && $statePayload['data']['verified'] === true, 'Requester must observe successful confirmation.');
expect($statePayload['data']['authenticated'] === false && Session::email() === null, 'Polling browser must remain anonymous.');
expect(!str_contains($stateResponse->body(), 'owner@example.com'), 'State response must not leak the identity.');
$crossOriginState = dispatch($app, 'GET', '/api/v1/state?id=' . rawurlencode($handoffSelector), [], ['Origin' => 'https://attacker.example']);
expect($crossOriginState->status() === 403, 'Disallowed origins must be rejected before API state work.');

// Headless batch endpoint and auto-tuning stay bounded.
$batchA = $service->request('owner@example.com', 'browser-requester', '127.0.0.6');
$batchB = $service->request('owner@example.com', 'browser-requester', '127.0.0.7');
$cancelled = $database->pdo()->prepare('SELECT status FROM mail_outbox WHERE selector = ?');
$cancelled->execute([$batchA['selector']]);
expect($cancelled->fetchColumn() === 'cancelled', 'A newer request must cancel an older queued email.');
Session::rememberRequest($batchA['selector']);
Session::rememberRequest($batchB['selector']);
unset($_SESSION['_last_state_poll_ms']);
$batchResponse = dispatch($app, 'POST', '/api/v1/states', [
    'ids' => [$batchA['selector'], $batchB['selector']],
    '_csrf' => 'csrf-requester',
], ['Origin' => 'http://127.0.0.1:8080']);
$batchPayload = responseJson($batchResponse);
expect($batchResponse->status() === 200 && count($batchPayload['data']['items']) === 2, 'Batch endpoint must return every owned state.');
$expectedStateBatch = $databaseDriver === 'mysql' ? 100 : 32;
$expectedWorkerBatch = $databaseDriver === 'mysql' ? 100 : 25;
expect($tuning->stateBatchMax() === $expectedStateBatch && $tuning->workerBatch() === $expectedWorkerBatch, 'Database auto-tuning must use the documented defaults.');
$oversizedBatch = dispatch($app, 'POST', '/api/v1/states', [
    'ids' => array_fill(0, $expectedStateBatch + 1, $batchB['selector']),
    '_csrf' => 'csrf-requester',
], ['Origin' => 'http://127.0.0.1:8080']);
expect($oversizedBatch->status() === 422, 'Batch endpoint must enforce its advertised limit before querying.');

// A worker claims the selected IDs with one bounded batch update.
$service->request('queue-one@example.com', 'worker-batch', '127.0.0.8');
$service->request('queue-two@example.com', 'worker-batch', '127.0.0.8');
$deliveredBeforeBatch = count($mailer->messages);
$workerBatch = $outbox->run(2);
expect($workerBatch['claimed'] === 2 && $workerBatch['sent'] === 2, 'Worker must claim and deliver a multi-row batch.');
expect(count($mailer->messages) === $deliveredBeforeBatch + 2, 'Every claimed batch row must be delivered exactly once.');

// Proxy headers are ignored unless the immediate peer is trusted.
$_SERVER = ['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.2'];
expect((new Request(16384, ['10.0.0.0/8']))->ip() === '198.51.100.7', 'Trusted proxy chain must resolve the first untrusted client.');
expect((new Request())->ip() === '10.0.0.3', 'Untrusted forwarding headers must be ignored.');
$_SERVER = ['CONTENT_LENGTH' => '2048'];
try {
    (new Request(1024))->input();
    throw new RuntimeException('Oversized request body must fail.');
} catch (PayloadTooLarge) {
    // Expected at the request boundary, before parsing.
}

// Retention is bounded and removes expired data in batches.
$old = time() - 40000000;
$database->pdo()->exec('UPDATE magic_links SET expires_at = ' . $old);
$database->pdo()->exec('UPDATE audit_events SET created_at = ' . $old);
$cleanup = $maintenance->runIfDue(250, true);
expect($cleanup['links'] > 0 && $cleanup['audit'] > 0, 'Maintenance must remove retained link and audit rows.');

// Admission reservations must hide the candidate -> next-probe transition,
// independently of allowlist outcome and delivery/consumption state. Run these
// cases on the selected test database, including the MySQL CI job.
$capacityConfig = Config::fromArray($temporary, $databaseSettings + [
    'APP_ENV' => 'test', 'APP_URL' => 'http://127.0.0.1:8080',
    'MAIL_TRANSPORT' => 'log', 'MAIL_FROM_ADDRESS' => 'test@example.com',
    'MAGICLINK_ALLOWED_DOMAINS' => 'example.com',
    'MAGICLINK_IP_LIMIT' => '100', 'MAGICLINK_EMAIL_LIMIT' => '100',
    'MAIL_PENDING_MAX' => '10',
]);
$capacityService = new MagicLinkService($database->pdo(), $capacityConfig, $crypto);
foreach (['candidate@example.com', 'candidate@example.net'] as $candidate) {
    $database->pdo()->exec('DELETE FROM mail_outbox');
    $database->pdo()->exec('DELETE FROM magic_links');
    $database->pdo()->exec('DELETE FROM rate_limit_counters');
    for ($index = 0; $index < 9; $index++) {
        $capacityService->request('padding-' . $index . '@example.com', 'padding', '192.0.2.80');
    }
    $capacityService->request($candidate, 'candidate', '192.0.2.81');
    expectRateLimited(fn () => $capacityService->request('probe@example.com', 'probe', '192.0.2.82'), 'The next probe must be rejected for both allowed and denied candidates.');
    $outbox->run(25);
    expectRateLimited(fn () => $capacityService->request('padding-0@example.com', 'repeat', '192.0.2.82'), 'Delivery or replacement must not free a reservation before fixed expiry.');
}

// Expired/orphaned pending and leased rows must not accumulate when fixed
// reservations are reused while the delivery worker remains stopped.
$database->pdo()->exec('DELETE FROM mail_outbox');
$database->pdo()->exec('DELETE FROM magic_links');
$database->pdo()->exec('DELETE FROM rate_limit_counters');
for ($index = 0; $index < 10; $index++) {
    $capacityService->request('stale-' . $index . '@example.com', 'stale', '192.0.2.83');
}
$database->pdo()->exec("UPDATE mail_outbox SET status = 'sending', lock_token = 'old-owner', locked_at = 1");
$database->pdo()->exec('UPDATE magic_links SET expires_at = ' . (time() - 1));
for ($cycle = 0; $cycle < 2; $cycle++) {
    for ($index = 0; $index < 10; $index++) {
        $capacityService->request('recycled-' . $cycle . '-' . $index . '@example.com', 'recycled', '192.0.2.84');
        expect((int) $database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE status IN ('pending','sending')")->fetchColumn() <= 10, 'Reusing capacity must preserve the physical active-mail bound.');
    }
    // Simulate retention deleting expired link rows before a stopped worker
    // reaches their outbox rows. The next cycle must retire those orphans too.
    $database->pdo()->exec('DELETE FROM magic_links');
}
expect((int) $database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE lock_token = 'old-owner'")->fetchColumn() === 0, 'Retired expired leases must lose ownership.');

echo "All MagicLink tests passed.\n";
