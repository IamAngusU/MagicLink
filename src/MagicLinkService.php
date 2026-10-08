<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Exception\RateLimited;
use PDO;
use Throwable;

final class MagicLinkService
{
    public function __construct(private PDO $pdo, private Config $config, private Crypto $crypto) {}

    /** @return array{selector:string,state:string,expires_at:int,masked_email:string} */
    public function request(string $email, string $sessionBinding, string $ipAddress): array
    {
        $email = strtolower(trim($email));
        $now = time();
        $ipHash = $this->crypto->hmac('ip', $ipAddress);
        $emailLookup = $this->crypto->hmac('email', substr($email, 0, 254));

        $this->beginWrite();
        try {
            $this->rateLimit('request|ip|' . $ipHash, $this->config->int('MAGICLINK_IP_LIMIT', 10), $this->config->int('MAGICLINK_RATE_WINDOW', 3600), $now);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                $this->audit('magic_link.invalid_request', $emailLookup, $ipHash, []);
                $this->pdo->commit();
                throw new \InvalidArgumentException('Please enter a valid email address.');
            }

            $this->rateLimit('request|email|' . $emailLookup, $this->config->int('MAGICLINK_EMAIL_LIMIT', 5), $this->config->int('MAGICLINK_RATE_WINDOW', 3600), $now);
            $deliverable = $this->emailAllowed($email);
            $selector = 'ml_' . bin2hex(random_bytes(12));
            $token = $this->crypto->token();
            $expiresAt = $now + $this->config->int('MAGICLINK_TTL_SECONDS', 900);
            $sessionHash = $this->crypto->hmac('session', $sessionBinding);

            // Keep the public path structurally identical for allowed and denied identities.
            $invalidate = $this->pdo->prepare('UPDATE magic_links SET consumed_at = ? WHERE email_lookup = ? AND consumed_at IS NULL');
            $invalidate->execute([$now, $emailLookup]);
            $cancelMail = $this->pdo->prepare("UPDATE mail_outbox SET status = 'cancelled', lock_token = NULL, locked_at = NULL WHERE subject_hash = ? AND status = 'pending'");
            $cancelMail->execute([$emailLookup]);
            $insert = $this->pdo->prepare('INSERT INTO magic_links(selector,token_hash,email_lookup,email_cipher,session_binding,request_ip_hash,deliverable,expires_at,consumed_at,verified_at,created_at) VALUES(?,?,?,?,?,?,?,?,NULL,NULL,?)');
            $insert->execute([
                $selector,
                $this->crypto->hmac('token', $token),
                $emailLookup,
                $this->crypto->encrypt($deliverable ? $email : ''),
                $sessionHash,
                $ipHash,
                $deliverable ? 1 : 0,
                $expiresAt,
                $now,
            ]);

            $url = $this->config->url('/auth/check') . '?id=' . rawurlencode($selector) . '#token=' . rawurlencode($token);
            $payload = $deliverable
                ? json_encode(['email' => $email, 'url' => $url, 'expires_at' => $expiresAt], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                : '{}';
            $outbox = $this->pdo->prepare('INSERT INTO mail_outbox(selector,deliverable,payload_cipher,subject_hash,request_ip_hash,status,attempts,available_at,created_at) VALUES(?,?,?,?,?,?,0,?,?)');
            $outbox->execute([
                $selector,
                $deliverable ? 1 : 0,
                $this->crypto->encrypt($payload),
                $emailLookup,
                $ipHash,
                $deliverable ? 'pending' : 'decoy',
                $now,
                $now,
            ]);
            $this->audit($deliverable ? 'magic_link.requested' : 'magic_link.denied', $emailLookup, $ipHash, ['selector' => $selector]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }

        return [
            'selector' => $selector,
            'state' => MagicLinkState::Waiting->value,
            'expires_at' => $expiresAt,
            'masked_email' => $this->maskEmail($email),
        ];
    }

    /** @return array{state:string,expires_at:int,verified:bool,terminal:bool} */
    public function state(string $selector, string $sessionBinding): array
    {
        $states = $this->states([$selector], $sessionBinding);
        if (!isset($states[$selector])) {
            throw new InvalidLink('Request not found.');
        }
        return $states[$selector];
    }

    /**
     * @param list<string> $selectors
     * @return array<string,array{state:string,expires_at:int,verified:bool,terminal:bool}>
     */
    public function states(array $selectors, string $sessionBinding): array
    {
        $selectors = array_values(array_unique($selectors));
        if ($selectors === []) {
            return [];
        }
        foreach ($selectors as $selector) {
            if (!preg_match('/^ml_[a-f0-9]{24}$/D', $selector)) {
                throw new InvalidLink('Request not found.');
            }
        }

        $placeholders = implode(',', array_fill(0, count($selectors), '?'));
        $statement = $this->pdo->prepare("SELECT selector,session_binding,deliverable,expires_at,consumed_at,verified_at FROM magic_links WHERE selector IN ({$placeholders})");
        $statement->execute($selectors);
        $binding = $this->crypto->hmac('session', $sessionBinding);
        $rows = [];
        foreach ($statement->fetchAll() as $row) {
            if (!hash_equals((string) $row['session_binding'], $binding)) {
                continue;
            }
            $selector = (string) $row['selector'];
            if ($row['verified_at'] !== null && (int) $row['deliverable'] === 1) {
                $rows[$selector] = ['state' => MagicLinkState::Verified->value, 'expires_at' => (int) $row['expires_at'], 'verified' => true, 'terminal' => true];
                continue;
            }
            $expired = $row['consumed_at'] !== null || (int) $row['expires_at'] <= time();
            $rows[$selector] = [
                'state' => $expired ? MagicLinkState::Expired->value : MagicLinkState::Waiting->value,
                'expires_at' => (int) $row['expires_at'],
                'verified' => false,
                'terminal' => $expired,
            ];
        }

        if (count($rows) !== count($selectors)) {
            throw new InvalidLink('Request not found.');
        }
        return $rows;
    }

    /** @return array{state:string,email:string} */
    public function exchange(string $selector, string $token, string $ipAddress): array
    {
        $now = time();
        $ipHash = $this->crypto->hmac('ip', $ipAddress);
        $selectorHash = $this->crypto->hmac('selector', substr($selector, 0, 64));
        $this->beginWrite();
        try {
            $window = $this->config->int('MAGICLINK_EXCHANGE_WINDOW', 900);
            $this->rateLimit('exchange|ip|' . $ipHash, $this->config->int('MAGICLINK_EXCHANGE_IP_LIMIT', 60), $window, $now);
            if (!preg_match('/^ml_[a-f0-9]{24}$/D', $selector) || strlen($token) < 40 || strlen($token) > 100) {
                $this->pdo->commit();
                throw new InvalidLink('The link is invalid or expired.');
            }
            $this->rateLimit('exchange|selector|' . $selectorHash, $this->config->int('MAGICLINK_EXCHANGE_SELECTOR_LIMIT', 10), $window, $now);

            $statement = $this->pdo->prepare('SELECT token_hash,email_lookup,email_cipher,deliverable,expires_at,consumed_at FROM magic_links WHERE selector = ? LIMIT 1');
            $statement->execute([$selector]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                $this->pdo->commit();
                throw new InvalidLink('The link is invalid or expired.');
            }
            if ($row['consumed_at'] !== null) {
                $this->audit('magic_link.replayed', (string) $row['email_lookup'], $ipHash, ['selector' => $selector]);
                $this->pdo->commit();
                throw new InvalidLink('The link was already used.');
            }
            $valid = (int) $row['deliverable'] === 1
                && (int) $row['expires_at'] > $now
                && hash_equals((string) $row['token_hash'], $this->crypto->hmac('token', $token));
            if (!$valid) {
                $this->audit('magic_link.rejected', (string) $row['email_lookup'], $ipHash, ['selector' => $selector]);
                $this->pdo->commit();
                throw new InvalidLink('The link is invalid or expired.');
            }
            $consume = $this->pdo->prepare('UPDATE magic_links SET consumed_at = ?, verified_at = ? WHERE selector = ? AND consumed_at IS NULL');
            $consume->execute([$now, $now, $selector]);
            if ($consume->rowCount() !== 1) {
                throw new InvalidLink('The link was already used.');
            }
            $this->audit('magic_link.verified', (string) $row['email_lookup'], $ipHash, ['selector' => $selector]);
            $email = $this->crypto->decrypt((string) $row['email_cipher']);
            $this->pdo->commit();
            return ['state' => MagicLinkState::Verified->value, 'email' => $email];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function emailAllowed(string $email): bool
    {
        if ($this->config->bool('MAGICLINK_ALLOW_ANY_EMAIL')) {
            return true;
        }
        if (in_array($email, $this->config->list('MAGICLINK_ALLOWED_EMAILS'), true)) {
            return true;
        }
        $domain = substr(strrchr($email, '@') ?: '', 1);
        foreach ($this->config->list('MAGICLINK_ALLOWED_DOMAINS') as $allowed) {
            if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    private function rateLimit(string $source, int $limit, int $window, int $now): void
    {
        $window = max(1, $window);
        $windowStarted = intdiv($now, $window) * $window;
        $bucket = $this->crypto->hmac('rate', $source);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $prefix = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $insert = $this->pdo->prepare("{$prefix} INTO rate_limit_counters(bucket,window_started,hits,expires_at) VALUES(?,?,0,?)");
        $insert->execute([$bucket, $windowStarted, $windowStarted + ($window * 2)]);
        $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $select = $this->pdo->prepare('SELECT hits FROM rate_limit_counters WHERE bucket = ? AND window_started = ?' . $suffix);
        $select->execute([$bucket, $windowStarted]);
        if ((int) $select->fetchColumn() >= $limit) {
            throw new RateLimited('Too many requests. Please try again later.', max(1, ($windowStarted + $window) - $now));
        }
        $update = $this->pdo->prepare('UPDATE rate_limit_counters SET hits = hits + 1 WHERE bucket = ? AND window_started = ?');
        $update->execute([$bucket, $windowStarted]);
    }

    /** @param array<string,string|int|bool> $metadata */
    private function audit(string $event, string $subjectHash, string $ipHash, array $metadata): void
    {
        $statement = $this->pdo->prepare('INSERT INTO audit_events(event_type,subject_hash,ip_hash,metadata_json,created_at) VALUES(?,?,?,?,?)');
        $statement->execute([$event, $subjectHash, $ipHash, json_encode($metadata, JSON_THROW_ON_ERROR), time()]);
    }

    private function beginWrite(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('BEGIN IMMEDIATE');
        } else {
            $this->pdo->beginTransaction();
        }
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, min(2, strlen($local)));
        return $visible . str_repeat('•', max(3, strlen($local) - strlen($visible))) . '@' . $domain;
    }
}
