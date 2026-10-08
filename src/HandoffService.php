<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use IamAngusU\MagicLink\Exception\InvalidHandoff;
use IamAngusU\MagicLink\Exception\RateLimited;
use PDO;
use Throwable;

/**
 * Confidential-client authorization-code flow for one configured relying app.
 */
final class HandoffService
{
    private WriteTransaction $transaction;

    public function __construct(
        private PDO $pdo,
        private Config $config,
        private Crypto $crypto,
    ) {
        $this->transaction = new WriteTransaction($pdo);
    }

    public function enabled(): bool
    {
        return $this->config->string('HANDOFF_REDIRECT_URL') !== '';
    }

    public function clientAuthenticated(string $authorization): bool
    {
        if (!$this->enabled() || !preg_match('/^Bearer ([A-Za-z0-9+\/=]{40,128})$/D', trim($authorization), $match)) {
            return false;
        }
        return hash_equals($this->config->string('HANDOFF_CLIENT_SECRET'), $match[1]);
    }

    /** @return array{authorize_url:string,expires_at:int} */
    public function initiate(
        string $redirectUri,
        string $state,
        string $codeChallenge,
        string $codeChallengeMethod,
    ): array {
        if (!$this->enabled()) {
            throw new \LogicException('Server handoff is unavailable.');
        }
        if (!hash_equals($this->config->string('HANDOFF_REDIRECT_URL'), $redirectUri)) {
            throw new \InvalidArgumentException('redirect_uri is not registered.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/D', $state)) {
            throw new \InvalidArgumentException('state must be 43 to 128 base64url characters.');
        }
        if ($codeChallengeMethod !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $codeChallenge)) {
            throw new \InvalidArgumentException('A valid S256 code_challenge is required.');
        }

        $now = time();
        $expiresAt = $now + $this->config->int('HANDOFF_TRANSACTION_TTL_SECONDS', 900);
        $request = $this->crypto->token();
        $requestHash = $this->crypto->hmac('handoff-request', $request);

        $this->transaction->begin();
        try {
            $this->rateLimit($now);
            $statement = $this->pdo->prepare(
                'INSERT INTO auth_handoffs('
                . 'request_hash,state_cipher,redirect_uri_hash,pkce_challenge,status,expires_at,created_at'
                . ") VALUES(?,?,?,?,'pending',?,?)"
            );
            $statement->execute([
                $requestHash,
                $this->crypto->encrypt($state),
                $this->crypto->hmac('handoff-redirect', $redirectUri),
                $codeChallenge,
                $expiresAt,
                $now,
            ]);
            $this->audit('handoff.initiated', $requestHash);
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }

        return [
            'authorize_url' => $this->config->url('/api/v1/handoffs/authorize')
                . '?' . http_build_query(['request' => $request], '', '&', PHP_QUERY_RFC3986),
            'expires_at' => $expiresAt,
        ];
    }

    public function bind(string $request, string $sessionBinding): void
    {
        $this->validateRequest($request);
        $binding = $this->crypto->hmac('session', $sessionBinding);
        $now = time();
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $this->transaction->begin();
        try {
            $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare(
                'SELECT id,status,expires_at,session_binding FROM auth_handoffs WHERE request_hash = ? LIMIT 1' . $suffix
            );
            $statement->execute([$this->crypto->hmac('handoff-request', $request)]);
            $row = $statement->fetch();
            if (!is_array($row)
                || !in_array((string) $row['status'], ['pending', 'authorized'], true)
                || (int) $row['expires_at'] <= $now
            ) {
                throw new InvalidHandoff('The authorization request is invalid or expired.');
            }

            $existingBinding = $row['session_binding'];
            if ($existingBinding !== null && !hash_equals((string) $existingBinding, $binding)) {
                throw new InvalidHandoff('The authorization request is already bound to another browser.');
            }
            if ($existingBinding === null) {
                $update = $this->pdo->prepare(
                    "UPDATE auth_handoffs SET session_binding = ? WHERE id = ? AND status = 'pending' AND session_binding IS NULL AND expires_at > ?"
                );
                $update->execute([$binding, (int) $row['id'], $now]);
                if ($update->rowCount() !== 1) {
                    throw new InvalidHandoff('The authorization request could not be bound.');
                }
            }
            $this->transaction->commit();
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }
    }

    /** @return array{redirect:string,expires_at:int,state:string} */
    public function authorize(string $request, string $sessionBinding, string $email): array
    {
        $this->validateRequest($request);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidHandoff('An authenticated identity is required.');
        }

        $now = time();
        $binding = $this->crypto->hmac('session', $sessionBinding);
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->transaction->begin();
        try {
            $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare(
                'SELECT id,status,state_cipher,redirect_uri_hash,pkce_challenge,session_binding,'
                . 'code_cipher,code_expires_at,expires_at FROM auth_handoffs WHERE request_hash = ? LIMIT 1' . $suffix
            );
            $statement->execute([$this->crypto->hmac('handoff-request', $request)]);
            $row = $statement->fetch();
            if (!is_array($row)
                || !in_array((string) $row['status'], ['pending', 'authorized'], true)
                || (int) $row['expires_at'] <= $now
                || !is_string($row['session_binding'])
                || !hash_equals((string) $row['session_binding'], $binding)
                || !hash_equals(
                    (string) $row['redirect_uri_hash'],
                    $this->crypto->hmac('handoff-redirect', $this->config->string('HANDOFF_REDIRECT_URL')),
                )
            ) {
                throw new InvalidHandoff('The authorization request is invalid or expired.');
            }

            $state = $this->crypto->decrypt((string) $row['state_cipher']);
            if ((string) $row['status'] === 'authorized') {
                if ((int) $row['code_expires_at'] <= $now || !is_string($row['code_cipher']) || $row['code_cipher'] === '') {
                    throw new InvalidHandoff('The authorization code is invalid or expired.');
                }
                $code = $this->crypto->decrypt((string) $row['code_cipher']);
                $this->transaction->commit();
                return $this->authorizationResult($code, $state, (int) $row['code_expires_at']);
            }

            $code = $this->crypto->token();
            $codeExpiresAt = $now + $this->config->int('HANDOFF_CODE_TTL_SECONDS', 60);
            $subjectHash = $this->crypto->hmac('email', strtolower($email));
            $update = $this->pdo->prepare(
                "UPDATE auth_handoffs SET code_hash = ?,code_cipher = ?,email_cipher = ?,subject_hash = ?,"
                . "status = 'authorized',authorized_at = ?,code_expires_at = ? "
                . "WHERE id = ? AND status = 'pending' AND session_binding = ? AND expires_at > ?"
            );
            $update->execute([
                $this->crypto->hmac('handoff-code', $code),
                $this->crypto->encrypt($code),
                $this->crypto->encrypt(strtolower($email)),
                $subjectHash,
                $now,
                $codeExpiresAt,
                (int) $row['id'],
                $binding,
                $now,
            ]);
            if ($update->rowCount() !== 1) {
                throw new InvalidHandoff('The authorization request could not be authorized.');
            }
            $this->audit('handoff.authorized', $subjectHash);
            $this->transaction->commit();
            return $this->authorizationResult($code, $state, $codeExpiresAt);
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }
    }

    /** @return array{email:string} */
    public function exchange(string $code, string $redirectUri, string $codeVerifier): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $code)
            || !preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeVerifier)
            || !$this->enabled()
            || !hash_equals($this->config->string('HANDOFF_REDIRECT_URL'), $redirectUri)
        ) {
            throw new InvalidHandoff('The authorization code is invalid or expired.');
        }

        $now = time();
        $hash = $this->crypto->hmac('handoff-code', $code);
        $redirectHash = $this->crypto->hmac('handoff-redirect', $redirectUri);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->transaction->begin();
        try {
            $suffix = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->pdo->prepare(
                'SELECT id,email_cipher,subject_hash,redirect_uri_hash,pkce_challenge,status,'
                . 'code_expires_at,consumed_at FROM auth_handoffs WHERE code_hash = ? LIMIT 1' . $suffix
            );
            $statement->execute([$hash]);
            $row = $statement->fetch();
            if (!is_array($row)
                || (string) $row['status'] !== 'authorized'
                || $row['consumed_at'] !== null
                || (int) $row['code_expires_at'] <= $now
                || !hash_equals((string) $row['redirect_uri_hash'], $redirectHash)
                || !hash_equals((string) $row['pkce_challenge'], $challenge)
            ) {
                throw new InvalidHandoff('The authorization code is invalid or expired.');
            }

            $consume = $this->pdo->prepare(
                "UPDATE auth_handoffs SET status = 'consumed',consumed_at = ? "
                . "WHERE id = ? AND status = 'authorized' AND consumed_at IS NULL AND code_expires_at > ?"
            );
            $consume->execute([$now, (int) $row['id'], $now]);
            if ($consume->rowCount() !== 1) {
                throw new InvalidHandoff('The authorization code was already used.');
            }

            $email = $this->crypto->decrypt((string) $row['email_cipher']);
            $this->audit('handoff.exchanged', (string) $row['subject_hash']);
            $this->transaction->commit();
            return ['email' => $email];
        } catch (Throwable $error) {
            $this->transaction->rollback();
            throw $error;
        }
    }

    private function validateRequest(string $request): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $request)) {
            throw new InvalidHandoff('The authorization request is invalid or expired.');
        }
    }

    /** @return array{redirect:string,expires_at:int,state:string} */
    private function authorizationResult(string $code, string $state, int $expiresAt): array
    {
        $redirect = $this->config->string('HANDOFF_REDIRECT_URL');
        $separator = str_contains($redirect, '?') ? '&' : '?';
        return [
            'redirect' => $redirect . $separator . http_build_query(
                ['code' => $code, 'state' => $state],
                '',
                '&',
                PHP_QUERY_RFC3986,
            ),
            'expires_at' => $expiresAt,
            'state' => $state,
        ];
    }

    private function audit(string $event, string $subjectHash): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_events(event_type,subject_hash,ip_hash,metadata_json,created_at) VALUES(?,?,?,?,?)'
        );
        $statement->execute([$event, $subjectHash, $this->crypto->hmac('ip', 'server-handoff'), '{}', time()]);
    }

    private function rateLimit(int $now): void
    {
        $window = $this->config->int('HANDOFF_INIT_WINDOW', 600);
        $windowStarted = intdiv($now, $window) * $window;
        $bucket = $this->crypto->hmac('rate', 'handoff|initiate');
        $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $prefix = $driver === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $insert = $this->pdo->prepare("{$prefix} INTO rate_limit_counters(bucket,window_started,hits,expires_at) VALUES(?,?,0,?)");
        $insert->execute([$bucket, $windowStarted, $windowStarted + ($window * 2)]);
        $update = $this->pdo->prepare(
            'UPDATE rate_limit_counters SET hits = hits + 1 '
            . 'WHERE bucket = ? AND window_started = ? AND hits < ?'
        );
        $update->execute([
            $bucket,
            $windowStarted,
            $this->config->int('HANDOFF_INIT_LIMIT', 1000),
        ]);
        if ($update->rowCount() !== 1) {
            throw new RateLimited(
                'Too many authorization transactions. Please try again later.',
                max(1, ($windowStarted + $window) - $now),
            );
        }
    }
}
