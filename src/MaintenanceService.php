<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use PDO;
use Throwable;

final class MaintenanceService
{
    private WriteTransaction $transaction;

    public function __construct(private PDO $pdo, private Config $config)
    {
        $this->transaction = new WriteTransaction($pdo);
    }

    /** @return array<string,int> */
    public function runIfDue(int $batch, bool $force = false): array
    {
        $now = time();
        $interval = $this->config->int('MAINTENANCE_INTERVAL_SECONDS', 900);
        if (!$force) {
            $check = $this->pdo->prepare('SELECT last_run FROM maintenance_state WHERE name = ?');
            $check->execute(['cleanup']);
            if ((int) $check->fetchColumn() > $now - $interval) {
                return ['links' => 0, 'rates' => 0, 'legacy_rates' => 0, 'audit' => 0, 'outbox' => 0];
            }
        }
        $this->beginWrite();
        try {
            $claim = $this->pdo->prepare('UPDATE maintenance_state SET last_run = ? WHERE name = ? AND last_run <= ?');
            $claim->execute([$now, 'cleanup', $force ? PHP_INT_MAX : $now - $interval]);
            if ($claim->rowCount() !== 1) {
                $this->transaction->commit();
                return ['links' => 0, 'rates' => 0, 'legacy_rates' => 0, 'audit' => 0, 'outbox' => 0];
            }
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }

        $batch = max(10, min(5000, $batch));
        $result = [];
        $result['links'] = $this->deleteBatch('magic_links', 'expires_at < ?', [$now - $this->config->int('MAGICLINK_RETENTION_SECONDS', 604800)], $batch);
        $result['rates'] = $this->deleteBatch('rate_limit_counters', 'expires_at < ?', [$now], $batch);
        $result['legacy_rates'] = $this->deleteBatch('rate_limits', 'created_at < ?', [$now - 86400], $batch);
        $result['audit'] = $this->deleteBatch('audit_events', 'created_at < ?', [$now - $this->config->int('AUDIT_RETENTION_SECONDS', 2592000)], $batch);
        $result['outbox'] = $this->deleteBatch('mail_outbox', "status IN ('sent','failed','decoy','cancelled') AND created_at < ?", [$now - $this->config->int('MAIL_RETENTION_SECONDS', 604800)], $batch);
        return $result;
    }

    /** @param list<int|string> $parameters */
    private function deleteBatch(string $table, string $where, array $parameters, int $limit): int
    {
        $statement = $this->pdo->prepare("DELETE FROM {$table} WHERE id IN (SELECT id FROM (SELECT id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT {$limit}) AS expired_rows)");
        $statement->execute($parameters);
        return $statement->rowCount();
    }

    private function beginWrite(): void
    {
        $this->transaction->begin();
    }
}
