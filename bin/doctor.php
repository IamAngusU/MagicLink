<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/autoload.php';

try {
    $config = IamAngusU\MagicLink\Config::load($root);
    $sessionStorage = $config->string('SESSION_STORAGE', 'files');
    $sessionPath = $config->string('SESSION_SAVE_PATH', 'storage/sessions');
    if ($sessionStorage === 'files' && !preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $sessionPath)) {
        $sessionPath = $root . '/' . ltrim($sessionPath, '/\\');
    }
    IamAngusU\MagicLink\Session::start($config);
    if (!session_write_close()) {
        throw new RuntimeException('The configured session store could not persist a test session.');
    }
    $checks = [
        'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
        'PDO driver ' . $config->string('DB_DRIVER') => in_array($config->string('DB_DRIVER'), PDO::getAvailableDrivers(), true),
        'MySQL client fails closed' => $config->string('DB_DRIVER') !== 'mysql' || extension_loaded('mysqlnd'),
        'Authenticated encryption' => function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt'),
        'storage writable' => is_writable($root . '/storage') || is_writable($root),
        'session storage configured' => $sessionStorage === 'configured'
            ? trim((string) ini_get('session.save_handler')) !== ''
            : is_writable($sessionPath) || (!file_exists($sessionPath) && is_writable(dirname($sessionPath))),
        'file-session retention policy' => $sessionStorage !== 'files'
            || ((string) ini_get('session.gc_maxlifetime') === (string) min($config->int('SESSION_IDLE_SECONDS', 28800), $config->int('SESSION_ABSOLUTE_SECONDS', 604800))
                && (string) ini_get('session.gc_probability') === '1'
                && (string) ini_get('session.gc_divisor') === '100'),
        'HTTPS production URL' => $config->string('APP_ENV') !== 'production' || parse_url($config->baseUrl(), PHP_URL_SCHEME) === 'https',
    ];
    foreach ($checks as $label => $passed) echo ($passed ? '[ok]   ' : '[fail] ') . $label . PHP_EOL;
    if (in_array(false, $checks, true)) exit(1);
    (new IamAngusU\MagicLink\Mail\MailTemplateRenderer($config))->render(
        'preview@example.com',
        $config->url('/auth/check?id=ml_doctor#token=preview-not-a-real-secret'),
        time() + $config->int('MAGICLINK_TTL_SECONDS', 900),
    );
    echo "[ok]   mail templates\n";
    $kernel = IamAngusU\MagicLink\Kernel::boot($root);
    $config->appKey();
    $pending = (int) $kernel->database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE status = 'pending'")->fetchColumn();
    echo "[ok]   database migration and app key\n";
    echo sprintf("[info] session storage=%s, handler=%s\n", $sessionStorage, (string) ini_get('session.save_handler'));
    if ($sessionStorage === 'files') {
        echo sprintf("[info] session GC lifetime=%ss, probability=%s/%s\n", (string) ini_get('session.gc_maxlifetime'), (string) ini_get('session.gc_probability'), (string) ini_get('session.gc_divisor'));
    }
    echo sprintf("[info] queue pending=%d/%d, worker batch=%d, state batch=%d, maintenance batch=%d\n", $pending, $kernel->tuning->pendingMailMax(), $kernel->tuning->workerBatch(), $kernel->tuning->stateBatchMax(), $kernel->tuning->maintenanceBatch());
    if (!$config->bool('MAIL_AUTO_DISPATCH', true)) {
        echo "[info] MAIL_AUTO_DISPATCH is off; run bin/worker.php from cron or a service.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[fail] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
