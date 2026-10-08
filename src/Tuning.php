<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use PDO;
use RuntimeException;

final class Tuning
{
    private string $driver;

    public function __construct(private Config $config, PDO $pdo)
    {
        $this->driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function stateBatchMax(): int
    {
        return $this->automatic('MAGICLINK_STATE_BATCH_MAX', $this->driver === 'sqlite' ? 32 : 100, 1, 100);
    }

    public function workerBatch(): int
    {
        return $this->automatic('MAIL_WORKER_BATCH', $this->driver === 'sqlite' ? 25 : 100, 1, 250);
    }

    public function pendingMailMax(): int
    {
        return $this->automatic('MAIL_PENDING_MAX', $this->driver === 'sqlite' ? 500 : 5000, 10, 100000);
    }

    public function maintenanceBatch(): int
    {
        $interval = $this->config->int('MAINTENANCE_INTERVAL_SECONDS', 300);
        $rateRows = $this->rowsPerInterval(
            $this->config->int('MAGICLINK_GLOBAL_LIMIT', 1000) * 3,
            $this->config->int('MAGICLINK_RATE_WINDOW', 3600),
            $interval,
        ) + $this->rowsPerInterval(
            $this->config->int('MAGICLINK_EXCHANGE_GLOBAL_LIMIT', 1000) * 3,
            $this->config->int('MAGICLINK_EXCHANGE_WINDOW', 900),
            $interval,
        );
        $handoffRows = $this->rowsPerInterval(
            $this->config->int('HANDOFF_INIT_LIMIT', 1000),
            $this->config->int('HANDOFF_INIT_WINDOW', 600),
            $interval,
        );
        // Keep 25% headroom over the highest admitted row rate. The floor
        // avoids tiny batches on quiet installations; the hard cap still
        // bounds lock duration and remains operator-overridable.
        $automatic = min(5000, max(
            $this->driver === 'sqlite' ? 250 : 1000,
            (int) ceil(max($rateRows, $handoffRows) * 1.25),
        ));
        return $this->automatic('MAINTENANCE_BATCH', $automatic, 10, 5000);
    }

    public function pollAfterMs(int $itemCount = 1): int
    {
        $base = $this->config->int('MAGICLINK_POLL_AFTER_MS', $this->driver === 'sqlite' ? 2500 : 1800);
        $groups = max(1, (int) ceil(max(1, $itemCount) / 10));
        return min(15000, $base * $groups);
    }

    private function automatic(string $key, int $automatic, int $minimum, int $maximum): int
    {
        $configured = strtolower($this->config->string($key, 'auto'));
        if ($configured === '' || $configured === 'auto') {
            return $automatic;
        }
        if (!preg_match('/^[0-9]+$/D', $configured)) {
            throw new RuntimeException($key . ' must be auto or an integer.');
        }
        $value = (int) $configured;
        if ($value < $minimum || $value > $maximum) {
            throw new RuntimeException(sprintf('%s must be between %d and %d.', $key, $minimum, $maximum));
        }
        return $value;
    }

    private function rowsPerInterval(int $limit, int $window, int $interval): int
    {
        return (int) ceil(($limit * $interval) / max(1, $window));
    }
}
