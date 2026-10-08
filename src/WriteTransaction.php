<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use PDO;

/**
 * Starts an eager SQLite write transaction without relying on PDO's historical
 * transaction-state tracking for transactions opened through exec().
 */
final class WriteTransaction
{
    private bool $active = false;
    private bool $manualSqlite = false;

    public function __construct(private PDO $pdo) {}

    public function begin(): void
    {
        if ($this->active) {
            throw new \LogicException('A write transaction is already active.');
        }

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
            $this->manualSqlite = true;
        } else {
            $this->pdo->beginTransaction();
        }
        $this->active = true;
    }

    public function commit(): void
    {
        if (!$this->active) {
            throw new \LogicException('No write transaction is active.');
        }

        if ($this->manualSqlite) {
            $this->pdo->exec('COMMIT');
        } else {
            $this->pdo->commit();
        }
        $this->active = false;
        $this->manualSqlite = false;
    }

    public function rollback(): void
    {
        if (!$this->active) {
            return;
        }

        try {
            if ($this->manualSqlite) {
                $this->pdo->exec('ROLLBACK');
            } elseif ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } finally {
            $this->active = false;
            $this->manualSqlite = false;
        }
    }
}
