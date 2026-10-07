<?php
declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'IamAngusU\\MagicLink\\';
    if (str_starts_with($class, $prefix)) {
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) require $path;
    }
});

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
    $database = IamAngusU\MagicLink\Database::connect($config);
    $database->migrate();
    $config->appKey();
    echo "[ok]   database migration and app key\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[fail] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
