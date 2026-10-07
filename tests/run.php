<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Mail\Mailer;
use IamAngusU\MagicLink\MagicLinkService;
use IamAngusU\MagicLink\MagicLinkState;

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'IamAngusU\\MagicLink\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }
});

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
    if (!$condition) throw new RuntimeException($message);
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

$temporary = sys_get_temp_dir() . '/magic-link-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$config = Config::fromArray($temporary, [
    'APP_ENV' => 'local',
    'APP_URL' => 'http://127.0.0.1:8080',
    'APP_NAME' => 'Test Link',
    'APP_LOCALE' => 'en',
    'DB_DRIVER' => 'sqlite',
    'DB_PATH' => 'storage/test.sqlite',
    'MAIL_TRANSPORT' => 'log',
    'MAIL_FROM_ADDRESS' => 'test@example.com',
    'MAGICLINK_ALLOWED_EMAILS' => 'owner@example.com',
    'MAGICLINK_IP_LIMIT' => '50',
    'MAGICLINK_EMAIL_LIMIT' => '50',
]);
$database = Database::connect($config);
$database->migrate();
$crypto = new Crypto($config->appKey());
$mailer = new TestMailer();
$service = new MagicLinkService($database->pdo(), $config, $crypto, $mailer);

$request = $service->request('Owner@Example.com', 'browser-a', '127.0.0.1');
expect($request['state'] === MagicLinkState::Waiting->value, 'New request must wait.');
expect(count($mailer->messages) === 1, 'Allowed address must receive one message.');
expect($mailer->messages[0]['to'] === 'owner@example.com', 'Email must be normalized.');
expect((bool) preg_match('~id=(ml_[a-f0-9]{24})#token=([A-Za-z0-9_-]+)~', $mailer->messages[0]['plain'], $match), 'Message must contain selector and fragment token.');
[$unused, $selector, $token] = $match;

$state = $service->state($selector, 'browser-a');
expect($state['state'] === MagicLinkState::Waiting->value, 'Original browser must see waiting state.');
expectInvalid(fn () => $service->state($selector, 'browser-b'), 'A different browser must not poll another request.');

$verified = $service->exchange($selector, $token, '127.0.0.2');
expect($verified['email'] === 'owner@example.com', 'Exchange must return the normalized identity.');
expect($service->state($selector, 'browser-a')['verified'] === true, 'Original browser must observe cross-device verification.');
expectInvalid(fn () => $service->exchange($selector, $token, '127.0.0.2'), 'Token replay must fail.');

$denied = $service->request('stranger@example.net', 'browser-c', '127.0.0.3');
expect(count($mailer->messages) === 1, 'Denied address must not receive mail.');
expect($service->state($denied['selector'], 'browser-c')['state'] === MagicLinkState::Waiting->value, 'Denied address must receive an enumeration-safe waiting state.');

$older = $service->request('owner@example.com', 'browser-d', '127.0.0.4');
preg_match('~id=(ml_[a-f0-9]{24})#token=([A-Za-z0-9_-]+)~', $mailer->messages[1]['plain'], $olderMatch);
$newer = $service->request('owner@example.com', 'browser-e', '127.0.0.5');
expectInvalid(fn () => $service->exchange($olderMatch[1], $olderMatch[2], '127.0.0.4'), 'A newer request must invalidate the previous link.');
expect($service->state($olderMatch[1], 'browser-d')['state'] === MagicLinkState::Expired->value, 'Invalidated link must not look verified to the waiting browser.');
expect($newer['selector'] !== $older['selector'], 'Selectors must be unique.');

$latestMessage = $mailer->messages[array_key_last($mailer->messages)];
preg_match('~id=(ml_[a-f0-9]{24})#token=([A-Za-z0-9_-]+)~', $latestMessage['plain'], $latestMatch);
$expire = $database->pdo()->prepare('UPDATE magic_links SET expires_at = ? WHERE selector = ?');
$expire->execute([time() - 1, $latestMatch[1]]);
expectInvalid(fn () => $service->exchange($latestMatch[1], $latestMatch[2], '127.0.0.5'), 'Expired token must fail.');

$auditCount = (int) $database->pdo()->query('SELECT COUNT(*) FROM audit_events')->fetchColumn();
expect($auditCount >= 6, 'Security-relevant events must be audited.');

echo "All MagicLink tests passed.\n";
