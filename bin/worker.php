<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Kernel;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$options = getopt('', ['once', 'loop', 'batch::', 'sleep::']);
$loop = array_key_exists('loop', $options);
$sleep = max(1, min(60, (int) ($options['sleep'] ?? 2)));

try {
    $kernel = Kernel::boot($root);
    $configuredBatch = $options['batch'] ?? 'auto';
    $batch = $configuredBatch === 'auto' || $configuredBatch === false
        ? $kernel->tuning->workerBatch()
        : max(1, min(250, (int) $configuredBatch));

    do {
        $result = $kernel->outbox->run($batch);
        fwrite(STDOUT, json_encode($result + ['batch' => $batch, 'time' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . PHP_EOL);
        if ($loop && $result['claimed'] === 0) {
            sleep($sleep);
        }
    } while ($loop);
} catch (Throwable $error) {
    fwrite(STDERR, '[worker] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
