<?php
declare(strict_types=1);

use IamAngusU\MagicLink\App;
use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Exception\InvalidHandoff;
use IamAngusU\MagicLink\HandoffService;
use IamAngusU\MagicLink\Http\Request;
use IamAngusU\MagicLink\Http\Response;
use IamAngusU\MagicLink\MagicLinkService;
use IamAngusU\MagicLink\Mail\MagicLinkMessage;
use IamAngusU\MagicLink\Mail\Mailer;
use IamAngusU\MagicLink\Mail\OutboxWorker;
use IamAngusU\MagicLink\MaintenanceService;
use IamAngusU\MagicLink\Session;
use IamAngusU\MagicLink\StateCatalog;
use IamAngusU\MagicLink\Tuning;

$root = dirname(__DIR__);
require $root . '/autoload.php';

function handoffExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function handoffExpectInvalid(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (InvalidHandoff) {
        return;
    }
    throw new RuntimeException($message);
}

/** @return array{request:string} */
function handoffRequestFromUrl(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    handoffExpect(isset($query['request']) && is_string($query['request']), 'Authorize URL must contain an opaque request.');
    return ['request' => $query['request']];
}

/** @return array{code:string,state:string} */
function handoffCallbackFromUrl(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    handoffExpect(isset($query['code'], $query['state']) && is_string($query['code']) && is_string($query['state']), 'Callback must contain code and state.');
    return ['code' => $query['code'], 'state' => $query['state']];
}

function handoffChallenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

/** @param array<string,mixed> $post @param array<string,string> $headers */
function handoffDispatch(App $app, string $method, string $uri, array $post = [], array $headers = []): Response
{
    $_GET = [];
    $_POST = $post;
    $parts = parse_url($uri);
    parse_str((string) ($parts['query'] ?? ''), $_GET);
    $_SERVER = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'REMOTE_ADDR' => '127.0.0.45',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ];
    foreach ($headers as $name => $value) {
        $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $route = new ReflectionMethod($app, 'route');
    return $route->invoke($app, new Request());
}

/** @return array<string,mixed> */
function handoffJson(Response $response): array
{
    $payload = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR);
    handoffExpect(is_array($payload), 'Expected a JSON response.');
    return $payload;
}

final class HandoffNullMailer implements Mailer
{
    public function send(string $to, string $subject, string $html, string $plain): void {}
}

$temporary = sys_get_temp_dir() . '/magic-link-handoff-' . bin2hex(random_bytes(6));
mkdir($temporary . '/storage', 0700, true);
$clientSecret = base64_encode(random_bytes(32));
$redirectUri = 'https://rp.example.test/auth/magic-link';
$config = Config::fromArray($temporary, [
    'APP_ENV' => 'test',
    'APP_URL' => 'http://127.0.0.1:8080',
    'APP_NAME' => 'Handoff Test',
    'APP_LOCALE' => 'en',
    'DB_DRIVER' => 'sqlite',
    'DB_PATH' => 'storage/test.sqlite',
    'MAIL_TRANSPORT' => 'log',
    'MAIL_FROM_ADDRESS' => 'test@example.com',
    'MAGICLINK_ALLOWED_DOMAINS' => 'example.com',
    'MAGICLINK_IP_LIMIT' => '100',
    'MAGICLINK_EMAIL_LIMIT' => '100',
    'MAGICLINK_GLOBAL_LIMIT' => '1000',
    'HANDOFF_REDIRECT_URL' => $redirectUri,
    'HANDOFF_CLIENT_SECRET' => $clientSecret,
    'HANDOFF_TRANSACTION_TTL_SECONDS' => '900',
    'HANDOFF_CODE_TTL_SECONDS' => '60',
    'HANDOFF_INIT_LIMIT' => '100',
]);
$database = Database::connect($config);
$database->migrate();
$pdo = $database->pdo();
$crypto = new Crypto($config->appKey());
$handoffs = new HandoffService($pdo, $config, $crypto);

handoffExpect($handoffs->clientAuthenticated('Bearer ' . $clientSecret), 'The configured confidential client must authenticate.');
handoffExpect(!$handoffs->clientAuthenticated('Bearer ' . base64_encode(random_bytes(32))), 'A wrong client secret must fail closed.');

// An RP-initiated transaction is bound once to one browser session. Another
// session cannot steal it, authorize it, or replace its state.
$verifier = $crypto->token();
$state = $crypto->token();
$transaction = $handoffs->initiate($redirectUri, $state, handoffChallenge($verifier), 'S256');
$request = handoffRequestFromUrl($transaction['authorize_url'])['request'];
$duplicateTransaction = $handoffs->initiate($redirectUri, $crypto->token(), handoffChallenge($crypto->token()), 'S256');
$duplicateRequest = handoffRequestFromUrl($duplicateTransaction['authorize_url'])['request'];
$uniqueRejected = false;
try {
    $pdo->prepare('UPDATE auth_handoffs SET request_hash = ? WHERE request_hash = ?')->execute([
        $crypto->hmac('handoff-request', $request),
        $crypto->hmac('handoff-request', $duplicateRequest),
    ]);
} catch (PDOException) {
    $uniqueRejected = true;
}
handoffExpect($uniqueRejected, 'request_hash uniqueness must be enforced by the database.');
$handoffs->bind($request, 'victim-browser-binding');
handoffExpectInvalid(
    static fn () => $handoffs->bind($request, 'attacker-browser-binding'),
    'A transaction must not be rebound to an attacker session.',
);
handoffExpectInvalid(
    static fn () => $handoffs->authorize($request, 'attacker-browser-binding', 'attacker@example.com'),
    'A different browser binding must not authorize the transaction.',
);
$authorization = $handoffs->authorize($request, 'victim-browser-binding', 'victim@example.com');
$callback = handoffCallbackFromUrl($authorization['redirect']);
handoffExpect(hash_equals($state, $callback['state']), 'The RP state must round-trip exactly for browser-session verification.');
$authorizationRetry = $handoffs->authorize($request, 'victim-browser-binding', 'victim@example.com');
handoffExpect(
    hash_equals($authorization['redirect'], $authorizationRetry['redirect']),
    'Retrying an already-successful authorization must recover the same encrypted code instead of minting another.',
);

handoffExpectInvalid(
    static fn () => $handoffs->exchange($callback['code'], $redirectUri, $crypto->token()),
    'A wrong PKCE verifier must not consume the code.',
);
handoffExpectInvalid(
    static fn () => $handoffs->exchange($callback['code'], 'https://evil.example.test/callback', $verifier),
    'A code must be bound to the exact registered redirect_uri.',
);
$identity = $handoffs->exchange($callback['code'], $redirectUri, $verifier);
handoffExpect($identity['email'] === 'victim@example.com', 'The valid RP exchange must return the authenticated identity.');
handoffExpectInvalid(
    static fn () => $handoffs->exchange($callback['code'], $redirectUri, $verifier),
    'An authorization code must be one-time use.',
);

// Code expiry and transaction expiry are independent and fail closed.
$expiredVerifier = $crypto->token();
$expired = $handoffs->initiate($redirectUri, $crypto->token(), handoffChallenge($expiredVerifier), 'S256');
$expiredRequest = handoffRequestFromUrl($expired['authorize_url'])['request'];
$handoffs->bind($expiredRequest, 'expiry-browser');
$expiredAuthorization = $handoffs->authorize($expiredRequest, 'expiry-browser', 'expired@example.com');
$expiredCallback = handoffCallbackFromUrl($expiredAuthorization['redirect']);
$pdo->prepare('UPDATE auth_handoffs SET code_expires_at = ? WHERE code_hash = ?')->execute([
    time() - 1,
    $crypto->hmac('handoff-code', $expiredCallback['code']),
]);
handoffExpectInvalid(
    static fn () => $handoffs->exchange($expiredCallback['code'], $redirectUri, $expiredVerifier),
    'An expired authorization code must fail.',
);

$stale = $handoffs->initiate($redirectUri, $crypto->token(), handoffChallenge($crypto->token()), 'S256');
$staleRequest = handoffRequestFromUrl($stale['authorize_url'])['request'];
$pdo->prepare('UPDATE auth_handoffs SET expires_at = ? WHERE request_hash = ?')->execute([
    time() - 1,
    $crypto->hmac('handoff-request', $staleRequest),
]);
handoffExpectInvalid(
    static fn () => $handoffs->bind($staleRequest, 'late-browser'),
    'An expired browser authorization request must fail.',
);

// Exercise the App failure boundary: the magic link remains a successful,
// authenticated exchange even when handoff authorization fails afterward. The
// returned retry URL deterministically recovers the same transaction.
Session::start($config);
$appVerifier = $crypto->token();
$appState = $crypto->token();
$appTransaction = $handoffs->initiate($redirectUri, $appState, handoffChallenge($appVerifier), 'S256');
$appRequest = handoffRequestFromUrl($appTransaction['authorize_url'])['request'];
$handoffs->bind($appRequest, Session::binding());
Session::bindHandoffRequest($appRequest);

$magicLinks = new MagicLinkService($pdo, $config, $crypto);
$magic = $magicLinks->request('owner@example.com', Session::binding(), '127.0.0.45');
$payloadStatement = $pdo->prepare('SELECT payload_cipher FROM mail_outbox WHERE selector = ?');
$payloadStatement->execute([$magic['selector']]);
$mailPayload = json_decode($crypto->decrypt((string) $payloadStatement->fetchColumn()), true, 8, JSON_THROW_ON_ERROR);
parse_str((string) parse_url((string) $mailPayload['url'], PHP_URL_FRAGMENT), $fragment);
$magicToken = (string) ($fragment['token'] ?? '');
handoffExpect($magicToken !== '', 'The queued test link must contain its fragment token.');

$outbox = new OutboxWorker($pdo, $config, $crypto, new MagicLinkMessage($config, new HandoffNullMailer()));
$app = new App(
    $config,
    $magicLinks,
    $handoffs,
    new StateCatalog($root, 'en'),
    $outbox,
    new MaintenanceService($pdo, $config),
    new Tuning($config, $pdo),
);
$wrongSecretResponse = handoffDispatch(
    $app,
    'POST',
    '/api/v1/handoffs/transactions',
    [
        'redirect_uri' => $redirectUri,
        'state' => $crypto->token(),
        'code_challenge' => handoffChallenge($crypto->token()),
        'code_challenge_method' => 'S256',
    ],
    ['Authorization' => 'Bearer ' . base64_encode(random_bytes(32))],
);
$wrongSecretPayload = handoffJson($wrongSecretResponse);
handoffExpect(
    $wrongSecretResponse->status() === 401 && ($wrongSecretPayload['code'] ?? null) === 'handoff.unauthorized',
    'The transaction API must reject a wrong confidential-client secret before processing input.',
);
$pdo->exec("CREATE TRIGGER fail_handoff_authorize BEFORE UPDATE OF status ON auth_handoffs WHEN NEW.status = 'authorized' BEGIN SELECT RAISE(FAIL, 'injected handoff failure'); END");
$exchangeResponse = handoffDispatch($app, 'POST', '/api/v1/exchange', [
    'id' => $magic['selector'],
    'token' => $magicToken,
    '_csrf' => Session::csrf(),
]);
$exchangePayload = handoffJson($exchangeResponse);
handoffExpect($exchangeResponse->status() === 200 && $exchangePayload['ok'] === true, 'A post-consumption handoff failure must not turn authentication into an error.');
handoffExpect(($exchangePayload['data']['handoff']['status'] ?? null) === 'retryable', 'The successful exchange must advertise a retryable handoff.');
handoffExpect(Session::email() === 'owner@example.com', 'The local authenticated session must remain active after handoff failure.');
$consumed = $pdo->prepare('SELECT consumed_at FROM magic_links WHERE selector = ?');
$consumed->execute([$magic['selector']]);
handoffExpect((int) $consumed->fetchColumn() > 0, 'The response must accurately reflect the already-consumed magic link.');

$pdo->exec('DROP TRIGGER fail_handoff_authorize');
$retryUrl = (string) $exchangePayload['data']['handoff']['retry_url'];
$retryResponse = handoffDispatch($app, 'GET', $retryUrl);
handoffExpect($retryResponse->status() === 303, 'The deterministic browser retry must authorize after the transient failure clears.');
$retryLocation = $retryResponse->headers()['Location'] ?? '';
$retryCallback = handoffCallbackFromUrl($retryLocation);
handoffExpect(hash_equals($appState, $retryCallback['state']), 'The retry must preserve the original RP state.');
$retryIdentity = $handoffs->exchange($retryCallback['code'], $redirectUri, $appVerifier);
handoffExpect($retryIdentity['email'] === 'owner@example.com', 'The recovered handoff must exchange normally.');

// The old user-minted endpoint is deliberately gone.
$legacyResponse = handoffDispatch($app, 'POST', '/api/v1/handoffs', ['_csrf' => Session::csrf()]);
$legacyPayload = handoffJson($legacyResponse);
handoffExpect($legacyResponse->status() === 404 && ($legacyPayload['code'] ?? null) === 'route.not_found', 'The legacy generic mint endpoint must fail closed.');

echo "Handoff security tests passed.\n";
