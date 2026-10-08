<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Exception\BadRequest;
use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Exception\RateLimited;
use IamAngusU\MagicLink\Exception\PayloadTooLarge;
use IamAngusU\MagicLink\Http\Request;
use IamAngusU\MagicLink\MagicLinkService;
use IamAngusU\MagicLink\MaintenanceService;
use IamAngusU\MagicLink\Session;
use IamAngusU\MagicLink\Tuning;

$root = dirname(__DIR__);
require_once $root . '/autoload.php';

function platformExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string,string|int|bool> $overrides */
function platformConfig(string $path, array $overrides = []): Config
{
    return Config::fromArray($path, $overrides + [
        'APP_ENV' => 'test',
        'APP_URL' => 'http://127.0.0.1:8080',
        'APP_NAME' => 'Platform test',
        'APP_LOCALE' => 'en',
        'APP_KEY' => base64_encode(str_repeat('p', 32)),
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => 'storage/platform.sqlite',
        'MAIL_TRANSPORT' => 'log',
        'MAIL_FROM_ADDRESS' => 'test@example.com',
        'MAGICLINK_ALLOWED_DOMAINS' => 'example.com',
        'MAGICLINK_IP_LIMIT' => '100',
        'MAGICLINK_EMAIL_LIMIT' => '100',
        'MAGICLINK_GLOBAL_LIMIT' => '1000',
        'MAIL_PENDING_MAX' => '10',
    ]);
}

function expectPlatformConfigFailure(string $path, array $overrides, string $message): void
{
    try {
        platformConfig($path, $overrides);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

$temporary = sys_get_temp_dir() . '/magic-link-platform-' . bin2hex(random_bytes(6));
mkdir($temporary . '/storage', 0700, true);
mkdir($temporary . '/public', 0755, true);

// Every shipped web path must cap bodies before PHP's automatic form parser.
platformExpect(str_contains((string) file_get_contents($root . '/public/.htaccess'), 'LimitRequestBody 16384'), 'Public Apache config must enforce the pre-parser body cap.');
platformExpect(str_contains((string) file_get_contents($root . '/.htaccess'), 'LimitRequestBody 16384'), 'Root-fallback Apache config must enforce the pre-parser body cap.');
platformExpect(str_contains((string) file_get_contents($root . '/deploy/nginx.conf.example'), 'client_max_body_size 16k'), 'Nginx example must enforce the pre-parser body cap.');
platformExpect(str_contains((string) file_get_contents($root . '/public/.user.ini'), 'post_max_size=16K'), 'Shared-hosting PHP config must enforce the pre-parser body cap.');

// Remote production credentials must never silently fall back to plaintext.
$remoteMysql = [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://login.example.com',
    'DB_DRIVER' => 'mysql',
    'DB_HOST' => 'db.example.com',
    'DB_DATABASE' => 'magic_link',
    'DB_USERNAME' => 'magic_link',
    'DB_PASSWORD' => 'secret',
    'MAIL_TRANSPORT' => 'mail',
];
expectPlatformConfigFailure($temporary, $remoteMysql, 'Remote production MySQL must not use implicit plaintext.');
expectPlatformConfigFailure($temporary, $remoteMysql + ['DB_SSL_MODE' => 'disabled'], 'Remote production MySQL must reject explicitly disabled TLS.');
expectPlatformConfigFailure($temporary, $remoteMysql + ['DB_SSL_MODE' => 'required', 'DB_SSL_CA' => 'storage/mysql-ca.pem'], 'Remote production MySQL must reject TLS without server identity verification.');
$verified = platformConfig($temporary, $remoteMysql + ['DB_SSL_MODE' => 'verify_identity', 'DB_SSL_CA' => 'storage/mysql-ca.pem']);
platformExpect($verified->string('DB_SSL_MODE') === 'verify_identity', 'Verified MySQL TLS config must be accepted before the connection-time CA check.');

expectPlatformConfigFailure($temporary, ['SESSION_STORAGE' => 'unknown'], 'Unknown session storage modes must fail closed.');
expectPlatformConfigFailure($temporary, ['MAIL_FROM_ADDRESS' => "sender@example.com\r\nBcc: victim@example.com"], 'Mail sender headers must reject control characters.');
expectPlatformConfigFailure($temporary, ['HANDOFF_REDIRECT_URL' => 'https://rp.example/callback'], 'Partial handoff configuration must fail closed.');
$handoffSecret = base64_encode(str_repeat('h', 32));
expectPlatformConfigFailure($temporary, [
    'HANDOFF_REDIRECT_URL' => 'https://rp.example/callback?state=reserved',
    'HANDOFF_CLIENT_SECRET' => $handoffSecret,
], 'The registered callback must reserve the state query key.');
expectPlatformConfigFailure($temporary, [
    'HANDOFF_REDIRECT_URL' => 'https://rp.example/callback?code=reserved',
    'HANDOFF_CLIENT_SECRET' => $handoffSecret,
], 'The registered callback must reserve the code query key.');
expectPlatformConfigFailure($temporary, [
    'HANDOFF_REDIRECT_URL' => 'https://rp.example/callback?STATE=reserved',
    'HANDOFF_CLIENT_SECRET' => $handoffSecret,
], 'Reserved callback query keys must be rejected case-insensitively.');

// Form bodies are bounded even when a proxy omits Content-Length and PHP has
// already populated $_POST.
$_SERVER = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'];
$_POST = ['value' => str_repeat('x', 2048)];
try {
    (new Request(1024))->input();
    throw new RuntimeException('Oversized parsed form input must be rejected.');
} catch (PayloadTooLarge) {
    // Expected.
}
$_POST = [];

$_SERVER = ['CONTENT_TYPE' => 'multipart/form-data; boundary=test'];
$_POST = ['email' => 'owner@example.com'];
try {
    (new Request(1024))->input();
    throw new RuntimeException('Multipart input must be rejected.');
} catch (BadRequest) {
    // Expected: MagicLink has no upload surface.
}
$_POST = [];

// Capacity rejection still consumes rate counters, bounding the expensive path.
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $config = platformConfig($temporary);
    $database = Database::connect($config);
    $database->migrate();
    $crypto = new Crypto($config->appKey());
    $service = new MagicLinkService($database->pdo(), $config, $crypto);
    for ($index = 0; $index < 10; $index++) {
        $service->request('queue-' . $index . '@example.com', 'fill-' . $index, '192.0.2.' . ($index + 1));
    }
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            $service->request('blocked-' . $attempt . '@example.com', 'blocked-' . $attempt, '198.51.100.10');
            throw new RuntimeException('A full queue must reject new identities.');
        } catch (RateLimited) {
            // Expected capacity rejection.
        }
    }
    $ipHash = $crypto->hmac('ip', '198.51.100.10');
    $bucket = $crypto->hmac('rate', 'request|ip|' . $ipHash);
    $counter = $database->pdo()->prepare('SELECT hits FROM rate_limit_counters WHERE bucket = ? ORDER BY window_started DESC LIMIT 1');
    $counter->execute([$bucket]);
    platformExpect((int) $counter->fetchColumn() === 3, 'Every capacity-rejected request must persist its IP budget hit.');
    platformExpect((int) $database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE status IN ('pending','sending')")->fetchColumn() === 10, 'Capacity rejection must not grow the queue.');

    // Capacity responses must not reveal whether an identity already has a
    // pending row. Existing and fresh identities are rejected identically.
    try {
        $service->request('queue-0@example.com', 'existing', '203.0.113.99');
        throw new RuntimeException('A full queue must reject an existing pending identity too.');
    } catch (RateLimited $error) {
        platformExpect($error->retryAfter === 60, 'Uniform capacity rejection must use the same retry interval.');
    }
    platformExpect((int) $database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE status IN ('pending','sending')")->fetchColumn() === 10, 'Uniform capacity rejection must leave the queue unchanged.');
    platformExpect((new Tuning($config, $database->pdo()))->maintenanceBatch() === 1563, 'Automatic maintenance must keep headroom over every admitted default row rate.');

    $exchangeConfig = platformConfig($temporary, ['MAGICLINK_EXCHANGE_GLOBAL_LIMIT' => '2']);
    $exchangeService = new MagicLinkService($database->pdo(), $exchangeConfig, $crypto);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $exchangeService->exchange('ml_' . str_repeat((string) $attempt, 24), str_repeat('x', 43), '203.0.113.' . ($attempt + 10));
            throw new RuntimeException('An unknown selector must not exchange.');
        } catch (InvalidLink) {
            // Expected, while still consuming the global exchange budget.
        }
    }
    try {
        $exchangeService->exchange('ml_' . str_repeat('a', 24), str_repeat('x', 43), '203.0.113.12');
        throw new RuntimeException('The global exchange budget must bound distributed selector churn.');
    } catch (RateLimited) {
        // Expected.
    }

    $expired = $database->pdo()->prepare('INSERT INTO rate_limit_counters(bucket,window_started,hits,expires_at) VALUES(?,?,?,?)');
    for ($index = 0; $index < 11; $index++) {
        $expired->execute(['expired-' . $index, 0, 1, 0]);
    }
    $database->pdo()->exec("UPDATE maintenance_state SET last_run = 0 WHERE name = 'cleanup'");
    $maintenance = new MaintenanceService($database->pdo(), $config);
    $firstCleanup = $maintenance->runIfDue(10);
    $secondCleanup = $maintenance->runIfDue(10);
    platformExpect($firstCleanup['rates'] === 10 && $secondCleanup['rates'] >= 1, 'A full maintenance batch must continue on the next request without waiting another interval.');
}

// File sessions contain identity and CSRF material and must stay outside public/.
$unsafeSessions = platformConfig($temporary, ['SESSION_SAVE_PATH' => 'public/sessions']);
try {
    Session::start($unsafeSessions);
    throw new RuntimeException('A session directory under public/ must be rejected.');
} catch (RuntimeException $error) {
    platformExpect(str_contains($error->getMessage(), 'public web root'), 'Unsafe session paths must fail for the expected reason.');
}

$safeSessions = platformConfig($temporary, ['SESSION_SAVE_PATH' => 'storage/sessions']);
Session::start($safeSessions);
platformExpect((string) ini_get('session.gc_maxlifetime') === '28800', 'File sessions must enforce configured idle retention.');
platformExpect((string) ini_get('session.gc_probability') === '1', 'File sessions must enable application-owned garbage collection.');
platformExpect((string) ini_get('session.gc_divisor') === '100', 'File session garbage collection cadence must be bounded.');
session_write_close();

echo "Platform hardening tests passed.\n";
