<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use IamAngusU\MagicLink\Exception\InvalidLink;
use IamAngusU\MagicLink\Exception\RateLimited;
use IamAngusU\MagicLink\Mail\Mailer;
use PDO;
use Throwable;

final class MagicLinkService
{
    public function __construct(
        private PDO $pdo,
        private Config $config,
        private Crypto $crypto,
        private Mailer $mailer,
    ) {}

    /** @return array{selector:string,state:string,expires_at:int,masked_email:string} */
    public function request(string $email, string $sessionBinding, string $ipAddress): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            throw new \InvalidArgumentException('Please enter a valid email address.');
        }

        $now = time();
        $emailLookup = $this->crypto->hmac('email', $email);
        $ipHash = $this->crypto->hmac('ip', $ipAddress);
        $deliverable = $this->emailAllowed($email);
        $selector = 'ml_' . bin2hex(random_bytes(12));
        $token = $this->crypto->token();
        $expiresAt = $now + $this->config->int('MAGICLINK_TTL_SECONDS', 900);
        $sessionHash = $this->crypto->hmac('session', $sessionBinding);

        $this->beginWrite();
        try {
            $this->rateLimit('ip|' . $ipHash, $this->config->int('MAGICLINK_IP_LIMIT', 10), $now);
            $this->rateLimit('email|' . $emailLookup, $this->config->int('MAGICLINK_EMAIL_LIMIT', 5), $now);
            if ($deliverable) {
                $invalidate = $this->pdo->prepare('UPDATE magic_links SET consumed_at = ? WHERE email_lookup = ? AND consumed_at IS NULL');
                $invalidate->execute([$now, $emailLookup]);
            }
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
            $this->audit($deliverable ? 'magic_link.requested' : 'magic_link.denied', $emailLookup, $ipHash, ['selector' => $selector]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof RateLimited) {
                $this->audit('magic_link.rate_limited', $emailLookup, $ipHash, []);
            }
            throw $error;
        }

        if ($deliverable) {
            $url = $this->config->url('/auth/check') . '?id=' . rawurlencode($selector) . '#token=' . rawurlencode($token);
            try {
                $this->sendMail($email, $url, $expiresAt);
            } catch (Throwable $error) {
                $this->audit('magic_link.delivery_failed', $emailLookup, $ipHash, ['selector' => $selector]);
                throw $error;
            }
        }

        return [
            'selector' => $selector,
            'state' => MagicLinkState::Waiting->value,
            'expires_at' => $expiresAt,
            'masked_email' => $this->maskEmail($email),
        ];
    }

    /** @return array{state:string,expires_at:int,verified:bool,email:?string} */
    public function state(string $selector, string $sessionBinding): array
    {
        if (!preg_match('/^ml_[a-f0-9]{24}$/D', $selector)) {
            throw new InvalidLink('Request not found.');
        }
        $statement = $this->pdo->prepare('SELECT email_cipher,session_binding,deliverable,expires_at,consumed_at,verified_at FROM magic_links WHERE selector = ? LIMIT 1');
        $statement->execute([$selector]);
        $row = $statement->fetch();
        $binding = $this->crypto->hmac('session', $sessionBinding);
        if (!is_array($row) || !hash_equals((string) $row['session_binding'], $binding)) {
            throw new InvalidLink('Request not found.');
        }
        if ($row['verified_at'] !== null && (int) $row['deliverable'] === 1) {
            return ['state' => MagicLinkState::Verified->value, 'expires_at' => (int) $row['expires_at'], 'verified' => true, 'email' => $this->crypto->decrypt((string) $row['email_cipher'])];
        }
        $expired = $row['consumed_at'] !== null || (int) $row['expires_at'] <= time();
        return ['state' => $expired ? MagicLinkState::Expired->value : MagicLinkState::Waiting->value, 'expires_at' => (int) $row['expires_at'], 'verified' => false, 'email' => null];
    }

    /** @return array{state:string,email:string} */
    public function exchange(string $selector, string $token, string $ipAddress): array
    {
        if (!preg_match('/^ml_[a-f0-9]{24}$/D', $selector) || strlen($token) < 40 || strlen($token) > 100) {
            throw new InvalidLink('The link is invalid or expired.');
        }
        $ipHash = $this->crypto->hmac('ip', $ipAddress);
        $this->beginWrite();
        try {
            $statement = $this->pdo->prepare('SELECT token_hash,email_lookup,email_cipher,deliverable,expires_at,consumed_at FROM magic_links WHERE selector = ? LIMIT 1');
            $statement->execute([$selector]);
            $row = $statement->fetch();
            if (!is_array($row)) {
                throw new InvalidLink('The link is invalid or expired.');
            }
            if ($row['consumed_at'] !== null) {
                $this->audit('magic_link.replayed', (string) $row['email_lookup'], $ipHash, ['selector' => $selector]);
                $this->pdo->commit();
                throw new InvalidLink('The link was already used.');
            }
            $valid = (int) $row['deliverable'] === 1
                && (int) $row['expires_at'] > time()
                && hash_equals((string) $row['token_hash'], $this->crypto->hmac('token', $token));
            if (!$valid) {
                $this->audit('magic_link.rejected', (string) $row['email_lookup'], $ipHash, ['selector' => $selector]);
                $this->pdo->commit();
                throw new InvalidLink('The link is invalid or expired.');
            }
            $verifiedAt = time();
            $consume = $this->pdo->prepare('UPDATE magic_links SET consumed_at = ?, verified_at = ? WHERE selector = ? AND consumed_at IS NULL');
            $consume->execute([$verifiedAt, $verifiedAt, $selector]);
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

    private function rateLimit(string $source, int $limit, int $now): void
    {
        $window = $this->config->int('MAGICLINK_RATE_WINDOW', 3600);
        $bucket = $this->crypto->hmac('rate', $source);
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE bucket = ? AND created_at >= ?' . $suffix);
        $statement->execute([$bucket, $now - $window]);
        if ((int) $statement->fetchColumn() >= $limit) {
            throw new RateLimited('Too many requests. Please try again later.');
        }
        $insert = $this->pdo->prepare('INSERT INTO rate_limits(bucket,created_at) VALUES(?,?)');
        $insert->execute([$bucket, $now]);
        if (random_int(1, 100) === 1) {
            $cleanup = $this->pdo->prepare('DELETE FROM rate_limits WHERE created_at < ?');
            $cleanup->execute([$now - max($window, 86400)]);
        }
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

    private function sendMail(string $email, string $url, int $expiresAt): void
    {
        $app = htmlspecialchars($this->config->string('APP_NAME'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $minutes = (int) ceil(($expiresAt - time()) / 60);
        $subject = $this->config->locale() === 'de' ? 'Dein sicherer Anmeldelink' : 'Your secure sign-in link';
        if ($this->config->locale() === 'de') {
            $plain = "Öffne diesen Link, um dich bei {$this->config->string('APP_NAME')} anzumelden:\n\n{$url}\n\nDer Link ist {$minutes} Minuten gültig und kann einmal verwendet werden.";
            $copy = 'Öffne den Link, um dich anzumelden. Er ist einmal verwendbar und läuft nach ' . $minutes . ' Minuten ab.';
            $button = 'Sicher anmelden';
        } else {
            $plain = "Open this link to sign in to {$this->config->string('APP_NAME')}:\n\n{$url}\n\nThe link is valid for {$minutes} minutes and can be used once.";
            $copy = 'Open the link to sign in. It can be used once and expires after ' . $minutes . ' minutes.';
            $button = 'Sign in securely';
        }
        $html = '<!doctype html><html><body style="margin:0;background:#eef4f3;color:#142024;font-family:Arial,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;padding:48px 24px"><div style="background:#fff;border:1px solid #cbd8d6;padding:36px">'
            . '<p style="margin:0 0 28px;font-size:13px;color:#43615f">' . $app . '</p>'
            . '<h1 style="font-size:30px;line-height:1.15;margin:0 0 16px">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="font-size:16px;line-height:1.6;margin:0 0 28px">' . htmlspecialchars($copy, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0"><a href="' . $safeUrl . '" style="display:inline-block;background:#0a6e75;color:#fff;text-decoration:none;padding:14px 20px;border-radius:4px">' . htmlspecialchars($button, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p style="font-size:12px;line-height:1.5;color:#607775;margin:28px 0 0">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div></div></body></html>';
        $this->mailer->send($email, $subject, $html, $plain);
    }
}
