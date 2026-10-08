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

    public function maintenanceBatch(): int
    {
        return $this->automatic('MAINTENANCE_BATCH', $this->driver === 'sqlite' ? 250 : 1000, 10, 5000);
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
}

