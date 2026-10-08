<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Kernel;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$options = getopt('', ['json']);

try {
    $kernel = Kernel::boot($root);
    $pdo = $kernel->database->pdo();
    $now = time();
    $queue = ['pending' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'decoy' => 0];
    foreach ($pdo->query('SELECT status,COUNT(*) AS total FROM mail_outbox GROUP BY status')->fetchAll() as $row) {
        $queue[(string) $row['status']] = (int) $row['total'];
    }

    $oldest = $pdo->query("SELECT MIN(created_at) FROM mail_outbox WHERE status = 'pending'")->fetchColumn();
    $stale = $pdo->prepare("SELECT COUNT(*) FROM mail_outbox WHERE status = 'sending' AND locked_at < ?");
    $stale->execute([$now - $kernel->config->int('MAIL_LOCK_TIMEOUT_SECONDS', 300)]);
    $lastDelivery = $pdo->query("SELECT MAX(created_at) FROM audit_events WHERE event_type = 'magic_link.delivered'")->fetchColumn();
    $lastMaintenance = $pdo->query("SELECT last_run FROM maintenance_state WHERE name = 'cleanup'")->fetchColumn();
    $activeHandoffs = $pdo->prepare(
        "SELECT COUNT(*) FROM auth_handoffs WHERE consumed_at IS NULL AND "
        . "((status = 'pending' AND expires_at > ?) OR (status = 'authorized' AND code_expires_at > ?))"
    );
    $activeHandoffs->execute([$now, $now]);

    $status = [
        'time' => gmdate(DATE_ATOM, $now),
        'queue' => $queue,
        'oldest_pending_age_seconds' => $oldest === false || $oldest === null ? null : max(0, $now - (int) $oldest),
        'stale_claims' => (int) $stale->fetchColumn(),
        'last_delivery_at' => $lastDelivery === false || $lastDelivery === null ? null : gmdate(DATE_ATOM, (int) $lastDelivery),
        'last_maintenance_at' => $lastMaintenance === false || (int) $lastMaintenance === 0 ? null : gmdate(DATE_ATOM, (int) $lastMaintenance),
        'active_handoffs' => (int) $activeHandoffs->fetchColumn(),
        'deployment' => [
            'database_driver' => $kernel->config->string('DB_DRIVER'),
            'database_tls_mode' => $kernel->config->string('DB_SSL_MODE', 'auto'),
            'session_storage' => $kernel->config->string('SESSION_STORAGE', 'files'),
            'session_handler' => (string) ini_get('session.save_handler'),
            'session_gc_maxlifetime' => $kernel->config->string('SESSION_STORAGE', 'files') === 'files'
                ? min($kernel->config->int('SESSION_IDLE_SECONDS', 28800), $kernel->config->int('SESSION_ABSOLUTE_SECONDS', 604800))
                : null,
        ],
        'effective' => [
            'worker_batch' => $kernel->tuning->workerBatch(),
            'pending_mail_max' => $kernel->tuning->pendingMailMax(),
            'state_batch' => $kernel->tuning->stateBatchMax(),
            'maintenance_batch' => $kernel->tuning->maintenanceBatch(),
            'maintenance_interval_seconds' => $kernel->config->int('MAINTENANCE_INTERVAL_SECONDS', 300),
            'http_body_bytes' => $kernel->config->int('HTTP_MAX_BODY_BYTES', 16384),
        ],
    ];

    if (array_key_exists('json', $options)) {
        echo json_encode($status, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    echo 'MagicLink status ' . $status['time'] . PHP_EOL;
    echo 'Queue: ' . implode(', ', array_map(
        static fn (string $name, int $count): string => $name . '=' . $count,
        array_keys($queue),
        array_values($queue),
    )) . PHP_EOL;
    echo 'Oldest pending: ' . ($status['oldest_pending_age_seconds'] === null ? 'none' : $status['oldest_pending_age_seconds'] . 's') . PHP_EOL;
    echo 'Stale claims: ' . $status['stale_claims'] . PHP_EOL;
    echo 'Last delivery: ' . ($status['last_delivery_at'] ?? 'none') . PHP_EOL;
    echo 'Last maintenance: ' . ($status['last_maintenance_at'] ?? 'never') . PHP_EOL;
    echo 'Active handoffs: ' . $status['active_handoffs'] . PHP_EOL;
    echo sprintf(
        'Deployment: db=%s (TLS %s), sessions=%s/%s%s',
        $status['deployment']['database_driver'],
        $status['deployment']['database_tls_mode'],
        $status['deployment']['session_storage'],
        $status['deployment']['session_handler'],
        PHP_EOL,
    );
} catch (Throwable $error) {
    fwrite(STDERR, '[status] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
