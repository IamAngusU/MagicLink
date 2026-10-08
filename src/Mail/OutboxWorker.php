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

    /** @return array{claimed:int,sent:int,retried:int,failed:int,lost:int} */
    public function run(int $limit): array
    {
        $limit = max(1, min(250, $limit));
        $rows = $this->claim($limit);
        $result = ['claimed' => count($rows), 'sent' => 0, 'retried' => 0, 'failed' => 0, 'lost' => 0];

        try {
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $lockToken = (string) $row['lock_token'];
                if (!$this->renewLease($id, $lockToken)) {
                    $this->recordOwnershipLoss($row, $result);
                    continue;
                }

                try {
                    $payload = $this->payload((string) $row['payload_cipher']);
                    if ($payload['expires_at'] <= time()) {
                        if ($this->discardExpired($id, $lockToken)) {
                            $this->audit('magic_link.delivery_expired', (string) $row['subject_hash'], (string) $row['request_ip_hash'], [
                                'selector' => (string) $row['selector'],
                            ]);
                            $result['failed']++;
                        } else {
                            $this->recordOwnershipLoss($row, $result);
                        }
                        continue;
                    }

                    $context = $this->deliveryContext((string) $row['selector'], $id, $lockToken);
                    $this->message->send($payload['email'], $payload['url'], $payload['expires_at'], $context);
                } catch (DeliveryOwnershipLost) {
                    $this->recordOwnershipLoss($row, $result);
                    continue;
                } catch (Throwable $error) {
                    $this->recordFailure($row, $error, $result);
                    continue;
                }

                if (!$this->finishSent($id, $lockToken)) {
                    $this->recordOwnershipLoss($row, $result);
                    continue;
                }
                $this->audit('magic_link.delivered', (string) $row['subject_hash'], (string) $row['request_ip_hash'], [
                    'selector' => (string) $row['selector'],
                    'message_id' => $context->messageId,
                ]);
                $result['sent']++;
            }
        } finally {
            $this->message->finishBatch();
        }

        return $result;
    }

    /** @return list<array<string,mixed>> */
    private function claim(int $limit): array
    {
        $now = time();
        $lockTimeout = $this->config->int('MAIL_LOCK_TIMEOUT_SECONDS', 300);
        $ready = $this->pdo->prepare("SELECT 1 FROM mail_outbox WHERE (status = 'pending' AND available_at <= ?) OR (status = 'sending' AND locked_at < ?) LIMIT 1");
        $ready->execute([$now, $now - $lockTimeout]);
        if ($ready->fetchColumn() === false) {
            return [];
        }

        $lockToken = bin2hex(random_bytes(16));
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->beginWrite();
        try {
            $recover = $this->pdo->prepare("UPDATE mail_outbox SET status = 'pending', lock_token = NULL, locked_at = NULL WHERE status = 'sending' AND locked_at < ?");
            $recover->execute([$now - $lockTimeout]);
            $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $select = $this->pdo->prepare("SELECT id FROM mail_outbox WHERE status = 'pending' AND available_at <= ? ORDER BY id ASC LIMIT {$limit}{$suffix}");
            $select->execute([$now]);
            $ids = array_map('intval', array_column($select->fetchAll(), 'id'));
            if ($ids !== []) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $claim = $this->pdo->prepare("UPDATE mail_outbox SET status = 'sending', lock_token = ?, locked_at = ?, attempts = attempts + 1 WHERE status = 'pending' AND id IN ({$placeholders})");
                $claim->execute([$lockToken, $now, ...$ids]);
                if ($claim->rowCount() !== count($ids)) {
                    throw new \RuntimeException('Outbox batch ownership changed while it was being claimed.');
                }
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

    /** @return array{email:string,url:string,expires_at:int} */
    private function payload(string $ciphertext): array
    {
        try {
            $payload = json_decode($this->crypto->decrypt($ciphertext), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new MailTransportException('outbox_payload', false, null, $error);
        }
        if (
            !is_array($payload)
            || !is_string($payload['email'] ?? null)
            || !is_string($payload['url'] ?? null)
            || !is_int($payload['expires_at'] ?? null)
        ) {
            throw new MailTransportException('outbox_payload', false);
        }
        return [
            'email' => $payload['email'],
            'url' => $payload['url'],
            'expires_at' => $payload['expires_at'],
        ];
    }

    private function deliveryContext(string $selector, int $id, string $lockToken): DeliveryContext
    {
        $host = strtolower((string) parse_url($this->config->baseUrl(), PHP_URL_HOST));
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host)) {
            $host = 'magic-link.local';
        }
        $digest = $this->crypto->hmac('mail-message-id', $selector);
        $interval = max(1.0, $this->config->int('MAIL_LOCK_TIMEOUT_SECONDS', 300) / 3);
        $nextHeartbeat = microtime(true) + $interval;

        return new DeliveryContext(
            'ml-' . substr($digest, 0, 48) . '@' . $host,
            function () use ($id, $lockToken, $interval, &$nextHeartbeat): void {
                $now = microtime(true);
                if ($now < $nextHeartbeat) {
                    return;
                }
                if (!$this->renewLease($id, $lockToken)) {
                    throw new DeliveryOwnershipLost();
                }
                $nextHeartbeat = $now + $interval;
            },
        );
    }

    private function renewLease(int $id, string $lockToken): bool
    {
        $statement = $this->pdo->prepare("UPDATE mail_outbox SET locked_at = ? WHERE id = ? AND lock_token = ? AND status = 'sending'");
        // One second in the future guarantees a changed value for MySQL rowCount()
        // when a freshly claimed row is renewed within the same wall-clock second.
        $statement->execute([time() + 1, $id, $lockToken]);
        return $statement->rowCount() === 1;
    }

    private function finishSent(int $id, string $lockToken): bool
    {
        $statement = $this->pdo->prepare("UPDATE mail_outbox SET status = 'sent', sent_at = ?, lock_token = NULL, locked_at = NULL WHERE id = ? AND lock_token = ? AND status = 'sending'");
        $statement->execute([time(), $id, $lockToken]);
        return $statement->rowCount() === 1;
    }

    private function finishFailure(int $id, string $lockToken, int $attempts, Throwable $error, bool $terminal): bool
    {
        $delay = $terminal ? 0 : $this->retryDelay($attempts);
        $diagnostic = $this->diagnostic($error);
        $errorHash = $this->crypto->hmac('mail-error', implode('|', [
            $diagnostic['category'],
            (string) $diagnostic['code'],
            $error::class,
        ]));
        $statement = $this->pdo->prepare("UPDATE mail_outbox SET status = ?, available_at = ?, last_error_hash = ?, lock_token = NULL, locked_at = NULL WHERE id = ? AND lock_token = ? AND status = 'sending'");
        $statement->execute([$terminal ? 'failed' : 'pending', time() + $delay, $errorHash, $id, $lockToken]);
        return $statement->rowCount() === 1;
    }

    private function discardExpired(int $id, string $lockToken): bool
    {
        $errorHash = $this->crypto->hmac('mail-error', 'expired-before-delivery');
        $statement = $this->pdo->prepare("UPDATE mail_outbox SET status = 'failed', last_error_hash = ?, lock_token = NULL, locked_at = NULL WHERE id = ? AND lock_token = ? AND status = 'sending'");
        $statement->execute([$errorHash, $id, $lockToken]);
        return $statement->rowCount() === 1;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{claimed:int,sent:int,retried:int,failed:int,lost:int} $result
     */
    private function recordFailure(array $row, Throwable $error, array &$result): void
    {
        $diagnostic = $this->diagnostic($error);
        $attempts = (int) $row['attempts'];
        $terminal = !$diagnostic['retryable'] || $attempts >= $this->config->int('MAIL_MAX_ATTEMPTS', 5);
        if (!$this->finishFailure((int) $row['id'], (string) $row['lock_token'], $attempts, $error, $terminal)) {
            $this->recordOwnershipLoss($row, $result);
            return;
        }
        $this->audit('magic_link.delivery_failed', (string) $row['subject_hash'], (string) $row['request_ip_hash'], [
            'selector' => (string) $row['selector'],
            'attempt' => $attempts,
            'terminal' => $terminal,
            'retryable' => $diagnostic['retryable'],
            'error_category' => $diagnostic['category'],
            'error_code' => $diagnostic['code'],
        ]);
        $result[$terminal ? 'failed' : 'retried']++;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{claimed:int,sent:int,retried:int,failed:int,lost:int} $result
     */
    private function recordOwnershipLoss(array $row, array &$result): void
    {
        $this->audit('magic_link.delivery_ownership_lost', (string) $row['subject_hash'], (string) $row['request_ip_hash'], [
            'selector' => (string) $row['selector'],
            'attempt' => (int) $row['attempts'],
        ]);
        $result['lost']++;
    }

    /** @return array{category:string,code:string|int,retryable:bool} */
    private function diagnostic(Throwable $error): array
    {
        if ($error instanceof MailTransportException) {
            return [
                'category' => $error->category,
                'code' => $error->transportCode ?? 'none',
                'retryable' => $error->retryable,
            ];
        }
        $parts = explode('\\', $error::class);
        return [
            'category' => 'internal',
            'code' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) end($parts)) ?: 'Throwable',
            'retryable' => true,
        ];
    }

    private function retryDelay(int $attempts): int
    {
        $base = min(3600, 30 * (2 ** min(7, max(0, $attempts - 1))));
        return min(3600, $base + random_int(0, max(1, intdiv($base, 4))));
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
