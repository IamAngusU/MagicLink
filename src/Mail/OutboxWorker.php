<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\WriteTransaction;
use PDO;
use Throwable;

final class OutboxWorker
{
    private WriteTransaction $transaction;

    public function __construct(
        private PDO $pdo,
        private Config $config,
        private Crypto $crypto,
        private MagicLinkMessage $message,
    ) {
        $this->transaction = new WriteTransaction($pdo);
    }

    /** @return array{claimed:int,sent:int,retried:int,failed:int} */
    public function run(int $limit): array
    {
        $limit = max(1, min(250, $limit));
        $rows = $this->claim($limit);
        $result = ['claimed' => count($rows), 'sent' => 0, 'retried' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            try {
                $payload = json_decode($this->crypto->decrypt((string) $row['payload_cipher']), true, 8, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \RuntimeException('Outbox payload is invalid.');
                }
                $expiresAt = (int) ($payload['expires_at'] ?? 0);
                if ($expiresAt <= time()) {
                    $this->discardExpired((int) $row['id'], (string) $row['lock_token']);
                    $this->audit('magic_link.delivery_expired', (string) $row['subject_hash'], (string) $row['request_ip_hash'], ['selector' => (string) $row['selector']]);
                    $result['failed']++;
                    continue;
                }
                $this->message->send((string) ($payload['email'] ?? ''), (string) ($payload['url'] ?? ''), $expiresAt);
                $this->finish((int) $row['id'], (string) $row['lock_token'], true, (int) $row['attempts'], null);
                $this->audit('magic_link.delivered', (string) $row['subject_hash'], (string) $row['request_ip_hash'], ['selector' => (string) $row['selector']]);
                $result['sent']++;
            } catch (Throwable $error) {
                $terminal = (int) $row['attempts'] >= $this->config->int('MAIL_MAX_ATTEMPTS', 5);
                $this->finish((int) $row['id'], (string) $row['lock_token'], false, (int) $row['attempts'], $error);
                $this->audit('magic_link.delivery_failed', (string) $row['subject_hash'], (string) $row['request_ip_hash'], [
                    'selector' => (string) $row['selector'],
                    'attempt' => (int) $row['attempts'],
                    'terminal' => $terminal,
                ]);
                $result[$terminal ? 'failed' : 'retried']++;
            }
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function claim(int $limit): array
    {
        $now = time();
        $ready = $this->pdo->prepare("SELECT 1 FROM mail_outbox WHERE (status = 'pending' AND available_at <= ?) OR (status = 'sending' AND locked_at < ?) LIMIT 1");
        $ready->execute([$now, $now - $this->config->int('MAIL_LOCK_TIMEOUT_SECONDS', 300)]);
        if ($ready->fetchColumn() === false) {
            return [];
        }
        $lockToken = bin2hex(random_bytes(16));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->beginWrite();
        try {
            $recover = $this->pdo->prepare("UPDATE mail_outbox SET status = 'pending', lock_token = NULL, locked_at = NULL WHERE status = 'sending' AND locked_at < ?");
            $recover->execute([$now - $this->config->int('MAIL_LOCK_TIMEOUT_SECONDS', 300)]);
            $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $select = $this->pdo->prepare("SELECT id FROM mail_outbox WHERE status = 'pending' AND available_at <= ? ORDER BY id ASC LIMIT {$limit}{$suffix}");
            $select->execute([$now]);
            $ids = array_map('intval', array_column($select->fetchAll(), 'id'));
            $claim = $this->pdo->prepare("UPDATE mail_outbox SET status = 'sending', lock_token = ?, locked_at = ?, attempts = attempts + 1 WHERE id = ? AND status = 'pending'");
            foreach ($ids as $id) {
                $claim->execute([$lockToken, $now, $id]);
            }
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }

        $statement = $this->pdo->prepare("SELECT id,selector,payload_cipher,attempts,lock_token,subject_hash,request_ip_hash FROM mail_outbox WHERE lock_token = ? AND status = 'sending' ORDER BY id ASC");
        $statement->execute([$lockToken]);
        return $statement->fetchAll();
    }

    private function finish(int $id, string $lockToken, bool $sent, int $attempts, ?Throwable $error): void
    {
        if ($sent) {
            $statement = $this->pdo->prepare("UPDATE mail_outbox SET status = 'sent', sent_at = ?, lock_token = NULL, locked_at = NULL WHERE id = ? AND lock_token = ?");
            $statement->execute([time(), $id, $lockToken]);
            return;
        }

        $terminal = $attempts >= $this->config->int('MAIL_MAX_ATTEMPTS', 5);
        $delay = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
        $errorHash = $this->crypto->hmac('mail-error', ($error?->getMessage() ?? 'unknown') . '|' . ($error ? $error::class : 'unknown'));
        $statement = $this->pdo->prepare("UPDATE mail_outbox SET status = ?, available_at = ?, last_error_hash = ?, lock_token = NULL, locked_at = NULL WHERE id = ? AND lock_token = ?");
        $statement->execute([$terminal ? 'failed' : 'pending', time() + $delay, $errorHash, $id, $lockToken]);
    }

    private function discardExpired(int $id, string $lockToken): void
    {
        $errorHash = $this->crypto->hmac('mail-error', 'expired-before-delivery');
        $statement = $this->pdo->prepare("UPDATE mail_outbox SET status = 'failed', last_error_hash = ?, lock_token = NULL, locked_at = NULL WHERE id = ? AND lock_token = ?");
        $statement->execute([$errorHash, $id, $lockToken]);
    }

    /** @param array<string,string|int|bool> $metadata */
    private function audit(string $event, string $subjectHash, string $ipHash, array $metadata): void
    {
        $statement = $this->pdo->prepare('INSERT INTO audit_events(event_type,subject_hash,ip_hash,metadata_json,created_at) VALUES(?,?,?,?,?)');
        $statement->execute([$event, $subjectHash, $ipHash, json_encode($metadata, JSON_THROW_ON_ERROR), time()]);
    }

    private function beginWrite(): void
    {
        $this->transaction->begin();
    }
}
