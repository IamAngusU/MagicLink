<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Kernel;

$root = dirname(__DIR__);
require $root . '/autoload.php';
$options = getopt('', ['all', 'batch::']);

try {
    $kernel = Kernel::boot($root);
    $configuredBatch = $options['batch'] ?? 'auto';
    $batch = $configuredBatch === 'auto' || $configuredBatch === false
        ? $kernel->tuning->maintenanceBatch()
        : max(10, min(5000, (int) $configuredBatch));
    $totals = ['links' => 0, 'handoffs' => 0, 'rates' => 0, 'legacy_rates' => 0, 'audit' => 0, 'outbox' => 0];
    $cycles = 0;
    do {
        $result = $kernel->maintenance->runIfDue($batch, true);
        foreach ($totals as $key => $value) {
            $totals[$key] += $result[$key];
        }
        $cycles++;
    } while (array_key_exists('all', $options) && array_sum($result) > 0 && $cycles < 100);
    fwrite(STDOUT, json_encode($totals + ['batch' => $batch, 'cycles' => $cycles, 'time' => gmdate(DATE_ATOM)], JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, '[maintenance] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
