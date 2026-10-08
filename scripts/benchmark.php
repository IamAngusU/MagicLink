<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\MagicLinkService;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$options = getopt('', ['requests::', 'reads::', 'batches::']);
$requestCount = boundedCount($options['requests'] ?? 500, 10, 900, 'requests');
$readCount = boundedCount($options['reads'] ?? 5000, 10, 100000, 'reads');
$batchCount = boundedCount($options['batches'] ?? 1000, 10, 20000, 'batches');
$temporary = sys_get_temp_dir() . '/magiclink-benchmark-' . bin2hex(random_bytes(6));

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('The benchmark requires pdo_sqlite. Enable the extension or use the project CI runtime.');
}
if (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) {
    throw new RuntimeException('The benchmark requires ext-sodium or ext-openssl.');
}

if (!mkdir($temporary . '/storage', 0700, true) && !is_dir($temporary . '/storage')) {
    throw new RuntimeException('Benchmark directory could not be created.');
}

try {
    $config = Config::fromArray($temporary, [
        'APP_ENV' => 'test',
        'APP_URL' => 'http://127.0.0.1:8080',
        'APP_LOCALE' => 'en',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => 'storage/benchmark.sqlite',
        'MAIL_TRANSPORT' => 'log',
        'MAIL_FROM_ADDRESS' => 'benchmark@example.com',
        'MAGICLINK_ALLOW_ANY_EMAIL' => 'true',
        'MAGICLINK_IP_LIMIT' => '1000',
        'MAGICLINK_EMAIL_LIMIT' => '1000',
    ]);
    $database = Database::connect($config);
    $database->migrate();
    $service = new MagicLinkService($database->pdo(), $config, new Crypto($config->appKey()));

    $requestLatencies = [];
    $selectors = [];
    for ($index = 0; $index < $requestCount; $index++) {
        $started = hrtime(true);
        $result = $service->request(
            sprintf('benchmark+%d@example.com', $index),
            'benchmark-browser',
            '127.0.0.1',
        );
        $requestLatencies[] = elapsedMs($started);
        if ($index >= $requestCount - 32) {
            $selectors[] = $result['selector'];
        }
    }

    $singleSelector = $selectors[array_key_last($selectors)];
    $readLatencies = [];
    for ($index = 0; $index < $readCount; $index++) {
        $started = hrtime(true);
        $service->state($singleSelector, 'benchmark-browser');
        $readLatencies[] = elapsedMs($started);
    }

    $batchLatencies = [];
    for ($index = 0; $index < $batchCount; $index++) {
        $started = hrtime(true);
        $service->states($selectors, 'benchmark-browser');
        $batchLatencies[] = elapsedMs($started);
    }

    $result = [
        'benchmark' => 'magiclink-sqlite-service-v1',
        'measured_at' => gmdate(DATE_ATOM),
        'runtime' => [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'crypto' => function_exists('sodium_crypto_secretbox') ? 'sodium-secretbox' : 'openssl-aes-256-gcm',
            'sqlite' => $database->pdo()->query('SELECT sqlite_version()')->fetchColumn(),
        ],
        'request' => summarize($requestLatencies, 1),
        'state_single' => summarize($readLatencies, 1),
        'state_batch_32' => summarize($batchLatencies, count($selectors)),
        'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
        'scope' => 'In-process service and SQLite only; excludes HTTP, TLS, SMTP and network latency.',
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    removeBenchmarkDirectory($temporary);
}

function boundedCount(mixed $value, int $minimum, int $maximum, string $name): int
{
    $parsed = filter_var($value, FILTER_VALIDATE_INT);
    if ($parsed === false || $parsed < $minimum || $parsed > $maximum) {
        throw new InvalidArgumentException(sprintf('--%s must be between %d and %d.', $name, $minimum, $maximum));
    }
    return $parsed;
}

function elapsedMs(int $started): float
{
    return (hrtime(true) - $started) / 1_000_000;
}

/** @param list<float> $latencies @return array<string,int|float> */
function summarize(array $latencies, int $itemsPerCall): array
{
    sort($latencies, SORT_NUMERIC);
    $count = count($latencies);
    $totalMs = array_sum($latencies);
    return [
        'calls' => $count,
        'items_per_call' => $itemsPerCall,
        'p50_ms' => percentile($latencies, 0.50),
        'p95_ms' => percentile($latencies, 0.95),
        'p99_ms' => percentile($latencies, 0.99),
        'calls_per_second' => round($count / max($totalMs / 1000, 0.000001), 1),
        'items_per_second' => round(($count * $itemsPerCall) / max($totalMs / 1000, 0.000001), 1),
    ];
}

/** @param list<float> $sorted */
function percentile(array $sorted, float $quantile): float
{
    $index = (int) ceil(count($sorted) * $quantile) - 1;
    return round($sorted[max(0, min(count($sorted) - 1, $index))], 3);
}

function removeBenchmarkDirectory(string $directory): void
{
    $prefix = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'magiclink-benchmark-';
    if (!str_starts_with($directory, $prefix) || !is_dir($directory)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($directory);
}
