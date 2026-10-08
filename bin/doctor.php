<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/autoload.php';

try {
    $config = IamAngusU\MagicLink\Config::load($root);
    $checks = [
        'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
        'PDO driver ' . $config->string('DB_DRIVER') => in_array($config->string('DB_DRIVER'), PDO::getAvailableDrivers(), true),
        'Authenticated encryption' => function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt'),
        'storage writable' => is_writable($root . '/storage') || is_writable($root),
        'HTTPS production URL' => $config->string('APP_ENV') !== 'production' || parse_url($config->baseUrl(), PHP_URL_SCHEME) === 'https',
    ];
    foreach ($checks as $label => $passed) echo ($passed ? '[ok]   ' : '[fail] ') . $label . PHP_EOL;
    if (in_array(false, $checks, true)) exit(1);
    $kernel = IamAngusU\MagicLink\Kernel::boot($root);
    $config->appKey();
    $pending = (int) $kernel->database->pdo()->query("SELECT COUNT(*) FROM mail_outbox WHERE status = 'pending'")->fetchColumn();
    echo "[ok]   database migration and app key\n";
    echo sprintf("[info] queue pending=%d, worker batch=%d, state batch=%d, maintenance batch=%d\n", $pending, $kernel->tuning->workerBatch(), $kernel->tuning->stateBatchMax(), $kernel->tuning->maintenanceBatch());
    if (!$config->bool('MAIL_AUTO_DISPATCH', true)) {
        echo "[info] MAIL_AUTO_DISPATCH is off; run bin/worker.php from cron or a service.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[fail] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
