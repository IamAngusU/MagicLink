<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Exception\RateLimited;
use IamAngusU\MagicLink\MagicLinkService;
use IamAngusU\MagicLink\MaintenanceService;
use IamAngusU\MagicLink\Tuning;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$options = getopt('', [
    'runs::',
    'requests::',
    'reads::',
    'batches::',
    'capacity-probes::',
    'capacity-existing-probes::',
    'maintenance-checks::',
    'burst-workers::',
    'burst-per-worker::',
    'burst-worker',
    'root:',
    'worker-id:',
    'start-file:',
    'per-worker:',
]);

if (isset($options['burst-worker'])) {
    runBurstWorker(
        requiredOption($options, 'root'),
        requiredOption($options, 'start-file'),
        boundedCount(requiredOption($options, 'worker-id'), 0, 31, 'worker-id'),
        boundedCount(requiredOption($options, 'per-worker'), 1, 100, 'per-worker'),
    );
    exit(0);
}

$runCount = boundedCount($options['runs'] ?? 5, 1, 20, 'runs');
$requestCount = boundedCount($options['requests'] ?? 400, 32, 490, 'requests');
$readCount = boundedCount($options['reads'] ?? 5000, 10, 100000, 'reads');
$batchCount = boundedCount($options['batches'] ?? 1000, 10, 20000, 'batches');
$capacityProbes = boundedCount($options['capacity-probes'] ?? 100, 10, 500, 'capacity-probes');
$capacityExistingProbes = boundedCount($options['capacity-existing-probes'] ?? 100, 10, 500, 'capacity-existing-probes');
$maintenanceChecks = boundedCount($options['maintenance-checks'] ?? 5000, 10, 100000, 'maintenance-checks');
$burstWorkers = boundedCount($options['burst-workers'] ?? 4, 1, 8, 'burst-workers');
$burstPerWorker = boundedCount($options['burst-per-worker'] ?? 25, 1, 100, 'burst-per-worker');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException('The benchmark requires pdo_sqlite. Enable the extension or use the project CI runtime.');
}
if (!function_exists('sodium_crypto_secretbox') && !function_exists('openssl_encrypt')) {
    throw new RuntimeException('The benchmark requires ext-sodium or ext-openssl.');
}

$temporary = sys_get_temp_dir() . '/magiclink-benchmark-' . bin2hex(random_bytes(6));
if (!mkdir($temporary, 0700, true) && !is_dir($temporary)) {
    throw new RuntimeException('Benchmark directory could not be created.');
}

try {
    $runs = [];
    $queryPlans = [];
    $schemaVersion = null;
    $sqliteVersion = null;
    for ($run = 1; $run <= $runCount; $run++) {
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $result = runCleanBenchmark(
            $temporary . '/run-' . $run,
            $requestCount,
            $readCount,
            $batchCount,
            $capacityProbes,
            $capacityExistingProbes,
            $maintenanceChecks,
            $burstWorkers,
            $burstPerWorker,
        );
        $runs[] = $result['measurements'];
        if ($queryPlans === []) {
            $queryPlans = $result['query_plans'];
            $schemaVersion = $result['schema_version'];
            $sqliteVersion = $result['sqlite_version'];
        }
    }

    $result = [
        'benchmark' => 'magiclink-sqlite-service-v3',
        'measured_at' => gmdate(DATE_ATOM),
        'runtime' => [
            'php' => PHP_VERSION,
            'os' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'crypto' => function_exists('sodium_crypto_secretbox') ? 'sodium-secretbox' : 'openssl-aes-256-gcm',
            'sqlite' => $sqliteVersion,
            'schema_version' => $schemaVersion,
        ],
        'parameters' => [
            'clean_runs' => $runCount,
            'successful_requests_per_run' => $requestCount,
            'single_state_reads_per_run' => $readCount,
            'batch_state_reads_per_run' => $batchCount,
            'queue_capacity_probes_per_run' => $capacityProbes,
            'queue_capacity_existing_identity_probes_per_run' => $capacityExistingProbes,
            'maintenance_due_checks_per_run' => $maintenanceChecks,
            'burst_workers' => $burstWorkers,
            'burst_requests_per_worker' => $burstPerWorker,
        ],
        'summary' => aggregateRuns($runs),
        'query_plans' => $queryPlans,
        'runs' => $runs,
        'scope' => 'In-process service and SQLite only, plus a bounded local multi-process SQLite burst; excludes HTTP, TLS, SMTP and network latency.',
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    removeBenchmarkDirectory($temporary);
}

/**
 * @return array{
 *   measurements:array<string,mixed>,
 *   query_plans:array<string,list<string>>,
 *   schema_version:int,
 *   sqlite_version:string
 * }
 */
function runCleanBenchmark(
    string $runRoot,
    int $requestCount,
    int $readCount,
    int $batchCount,
    int $capacityProbes,
    int $capacityExistingProbes,
    int $maintenanceChecks,
    int $burstWorkers,
    int $burstPerWorker,
): array {
    if (!mkdir($runRoot . '/storage', 0700, true) && !is_dir($runRoot . '/storage')) {
        throw new RuntimeException('Run directory could not be created.');
    }

    $config = benchmarkConfig($runRoot);
    $database = Database::connect($config);
    $database->migrate();
    $pdo = $database->pdo();
    $service = new MagicLinkService($pdo, $config, new Crypto($config->appKey()));
    $tuning = new Tuning($config, $pdo);
    $pendingMax = $tuning->pendingMailMax();
    if ($requestCount >= $pendingMax) {
        throw new InvalidArgumentException(sprintf('--requests must be lower than the effective SQLite queue cap (%d).', $pendingMax));
    }

    $requestLatencies = [];
    $selectors = [];
    for ($index = 0; $index < $requestCount; $index++) {
        $started = hrtime(true);
        $request = $service->request(
            sprintf('benchmark+%d@example.com', $index),
            'benchmark-browser',
            '127.0.0.1',
        );
        $requestLatencies[] = elapsedMs($started);
        if ($index >= $requestCount - 32) {
            $selectors[] = $request['selector'];
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

    for ($index = $requestCount; $index < $pendingMax; $index++) {
        $service->request(
            sprintf('benchmark+%d@example.com', $index),
            'benchmark-capacity-fill',
            '127.0.0.1',
        );
    }
    $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE status IN ('pending','sending')")->fetchColumn();
    if ($activeCount !== $pendingMax) {
        throw new RuntimeException(sprintf('Queue fill produced %d active rows; expected %d.', $activeCount, $pendingMax));
    }

    $rejectionLatencies = [];
    for ($index = 0; $index < $capacityProbes; $index++) {
        $started = hrtime(true);
        try {
            $service->request('capacity-probe@example.com', 'benchmark-capacity-probe', '127.0.0.2');
            throw new RuntimeException('A new identity was admitted while the active queue was full.');
        } catch (RateLimited $error) {
            if ($error->retryAfter !== 60) {
                throw new RuntimeException('The capacity rejection did not use the expected retry interval.', 0, $error);
            }
            $rejectionLatencies[] = elapsedMs($started);
        }
    }

    $existingIdentityLatencies = [];
    for ($index = 0; $index < $capacityExistingProbes; $index++) {
        $started = hrtime(true);
        try {
            $service->request('benchmark+0@example.com', 'benchmark-capacity-existing', '127.0.0.3');
            throw new RuntimeException('An existing identity was admitted while the active queue was full.');
        } catch (RateLimited $error) {
            if ($error->retryAfter !== 60) {
                throw new RuntimeException('The existing-identity rejection did not use the expected retry interval.', 0, $error);
            }
            $existingIdentityLatencies[] = elapsedMs($started);
        }
    }
    $activeAfterExistingProbes = (int) $pdo->query("SELECT COUNT(*) FROM mail_outbox WHERE status IN ('pending','sending')")->fetchColumn();
    if ($activeAfterExistingProbes !== $pendingMax) {
        throw new RuntimeException(sprintf('Existing-identity probes changed the active queue to %d rows.', $activeAfterExistingProbes));
    }

    $maintenance = new MaintenanceService($pdo, $config);
    $maintenance->runIfDue($tuning->maintenanceBatch(), true);
    $maintenanceLatencies = [];
    for ($index = 0; $index < $maintenanceChecks; $index++) {
        $started = hrtime(true);
        $maintenance->runIfDue($tuning->maintenanceBatch());
        $maintenanceLatencies[] = elapsedMs($started);
    }

    $plans = queryPlans($pdo, $pendingMax);
    $schemaVersion = (int) $pdo->query("SELECT value FROM schema_meta WHERE name = 'schema_version' LIMIT 1")->fetchColumn();
    $burst = runBurstBenchmark($runRoot . '/burst', $burstWorkers, $burstPerWorker);

    return [
        'measurements' => [
            'request' => summarize($requestLatencies, 1),
            'state_single' => summarize($readLatencies, 1),
            'state_batch_32' => summarize($batchLatencies, count($selectors)),
            'queue_full_reject' => summarize($rejectionLatencies, 1),
            'queue_full_existing_identity_reject' => summarize($existingIdentityLatencies, 1),
            'maintenance_not_due' => summarize($maintenanceLatencies, 1),
            'sqlite_burst' => $burst,
            'peak_memory_mib' => round(memory_get_peak_usage(true) / 1048576, 2),
        ],
        'query_plans' => $plans,
        'schema_version' => $schemaVersion,
        'sqlite_version' => (string) $pdo->query('SELECT sqlite_version()')->fetchColumn(),
    ];
}

/** @return array<string,string|int|bool> */
function benchmarkValues(): array
{
    return [
        'APP_ENV' => 'test',
        'APP_URL' => 'http://127.0.0.1:8080',
        'APP_LOCALE' => 'en',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => 'storage/benchmark.sqlite',
        'MAIL_TRANSPORT' => 'log',
        'MAIL_FROM_ADDRESS' => 'benchmark@example.com',
        'MAIL_AUTO_DISPATCH' => 'false',
        'MAGICLINK_ALLOW_ANY_EMAIL' => 'true',
        'MAGICLINK_IP_LIMIT' => '1000',
        'MAGICLINK_EMAIL_LIMIT' => '1000',
        'MAGICLINK_GLOBAL_LIMIT' => '1000000',
        'MAIL_PENDING_MAX' => 'auto',
    ];
}

function benchmarkConfig(string $benchmarkRoot): Config
{
    return Config::fromArray($benchmarkRoot, benchmarkValues());
}

/** @return array<string,list<string>> */
function queryPlans(PDO $pdo, int $pendingMax): array
{
    return [
        'active_queue_count' => explainPlan(
            $pdo,
            "SELECT COUNT(*) FROM (SELECT id FROM mail_outbox WHERE status IN ('pending','sending') LIMIT {$pendingMax}) AS active_mail",
        ),
        'handoff_by_code' => explainPlan(
            $pdo,
            "SELECT id,email_cipher,subject_hash,redirect_uri_hash,pkce_challenge,status,code_expires_at,consumed_at FROM auth_handoffs WHERE code_hash = '{$zeroHash}' LIMIT 1",
        ),
        'handoff_by_request' => explainPlan(
            $pdo,
            "SELECT id,status,expires_at,session_binding FROM auth_handoffs WHERE request_hash = '{$zeroHash}' LIMIT 1",
        ),
    ];
}

/** @return list<string> */
function explainPlan(PDO $pdo, string $sql): array
{
    $rows = $pdo->query('EXPLAIN QUERY PLAN ' . $sql)->fetchAll();
    return array_values(array_map(
        static fn (array $row): string => (string) ($row['detail'] ?? ''),
        $rows,
    ));
}

/** @return array<string,int|float> */
function runBurstBenchmark(string $burstRoot, int $workers, int $perWorker): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('The bounded burst benchmark requires proc_open.');
    }
    if (!mkdir($burstRoot . '/storage', 0700, true) && !is_dir($burstRoot . '/storage')) {
        throw new RuntimeException('Burst benchmark directory could not be created.');
    }

    $config = benchmarkConfig($burstRoot);
    $config->appKey();
    $database = Database::connect($config);
    $database->migrate();
    unset($database);

    $startFile = $burstRoot . '/start.signal';
    $processes = [];
    for ($worker = 0; $worker < $workers; $worker++) {
        $command = childPhpCommand();
        array_push(
            $command,
            __FILE__,
            '--burst-worker',
            '--root=' . $burstRoot,
            '--start-file=' . $startFile,
            '--worker-id=' . $worker,
            '--per-worker=' . $perWorker,
        );
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a burst benchmark worker.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    $started = hrtime(true);
    if (!touch($startFile)) {
        throw new RuntimeException('Could not release the burst benchmark workers.');
    }

    $latencies = [];
    $successful = 0;
    foreach ($processes as $worker => $entry) {
        $stdout = stream_get_contents($entry['stdout']);
        $stderr = stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exitCode = proc_close($entry['process']);
        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                'Burst worker %d failed with exit code %d: %s',
                $worker,
                $exitCode,
                trim((string) $stderr),
            ));
        }
        $decoded = json_decode((string) $stdout, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !isset($decoded['latencies']) || !is_array($decoded['latencies'])) {
            throw new RuntimeException(sprintf('Burst worker %d returned an invalid result.', $worker));
        }
        $successful += (int) ($decoded['successful'] ?? 0);
        foreach ($decoded['latencies'] as $latency) {
            $latencies[] = (float) $latency;
        }
    }
    $wallMs = elapsedMs($started);
    $expected = $workers * $perWorker;
    if ($successful !== $expected || count($latencies) !== $expected) {
        throw new RuntimeException(sprintf('Burst completed %d of %d requests.', $successful, $expected));
    }

    $summary = summarize($latencies, 1);
    $summary['workers'] = $workers;
    $summary['requests_per_worker'] = $perWorker;
    $summary['successful'] = $successful;
    $summary['wall_ms'] = round($wallMs, 3);
    $summary['wall_calls_per_second'] = round($successful / max($wallMs / 1000, 0.000001), 1);
    return $summary;
}

function runBurstWorker(string $benchmarkRoot, string $startFile, int $workerId, int $perWorker): void
{
    $deadline = microtime(true) + 15;
    while (!is_file($startFile)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for the burst start signal.');
        }
        usleep(1000);
    }

    $config = benchmarkConfig($benchmarkRoot);
    $database = Database::connect($config);
    $database->ensureSchema();
    $service = new MagicLinkService($database->pdo(), $config, new Crypto($config->appKey()));
    $latencies = [];
    for ($index = 0; $index < $perWorker; $index++) {
        $started = hrtime(true);
        $service->request(
            sprintf('burst-%d-%d@example.com', $workerId, $index),
            sprintf('burst-browser-%d', $workerId),
            sprintf('127.0.1.%d', $workerId + 1),
        );
        $latencies[] = elapsedMs($started);
    }

    echo json_encode(
        ['successful' => count($latencies), 'latencies' => $latencies],
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
}

/** @return list<string> */
function childPhpCommand(): array
{
    $command = [PHP_BINARY];
    if (PHP_OS_FAMILY === 'Windows' && php_ini_loaded_file() === false) {
        $command[] = '-d';
        $command[] = 'extension_dir=' . (string) ini_get('extension_dir');
        foreach (['pdo_sqlite', 'sodium', 'openssl'] as $extension) {
            if (extension_loaded($extension)) {
                $command[] = '-d';
                $command[] = 'extension=' . $extension;
            }
        }
    }
    return $command;
}

/** @param list<array<string,mixed>> $runs @return array<string,mixed> */
function aggregateRuns(array $runs): array
{
    $summary = [];
    foreach (['request', 'state_single', 'state_batch_32', 'queue_full_reject', 'queue_full_existing_identity_reject', 'maintenance_not_due'] as $metric) {
        $summary[$metric] = aggregateMetric($runs, $metric, [
            'p50_ms' => 3,
            'p95_ms' => 3,
            'p99_ms' => 3,
            'calls_per_second' => 1,
            'items_per_second' => 1,
        ]);
    }
    $summary['sqlite_burst'] = aggregateMetric($runs, 'sqlite_burst', [
        'p50_ms' => 3,
        'p95_ms' => 3,
        'p99_ms' => 3,
        'wall_ms' => 3,
        'wall_calls_per_second' => 1,
    ]);
    $memory = array_map(static fn (array $run): float => (float) $run['peak_memory_mib'], $runs);
    $summary['peak_memory_mib'] = observation($memory, 2);
    return $summary;
}

/**
 * @param list<array<string,mixed>> $runs
 * @param array<string,int> $fields
 * @return array<string,array{median:float,min:float,max:float}>
 */
function aggregateMetric(array $runs, string $metric, array $fields): array
{
    $result = [];
    foreach ($fields as $field => $precision) {
        $values = [];
        foreach ($runs as $run) {
            $values[] = (float) $run[$metric][$field];
        }
        $result[$field] = observation($values, $precision);
    }
    return $result;
}

/** @param list<float> $values @return array{median:float,min:float,max:float} */
function observation(array $values, int $precision): array
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);
    $median = $count % 2 === 1
        ? $values[$middle]
        : ($values[$middle - 1] + $values[$middle]) / 2;
    return [
        'median' => round($median, $precision),
        'min' => round($values[0], $precision),
        'max' => round($values[$count - 1], $precision),
    ];
}

/** @param array<string,mixed> $options */
function requiredOption(array $options, string $name): string
{
    $value = $options[$name] ?? null;
    if (!is_string($value) || $value === '') {
        throw new InvalidArgumentException(sprintf('--%s is required in worker mode.', $name));
    }
    return $value;
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
