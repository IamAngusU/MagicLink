<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

final class Session
{
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.lazy_write', '1');
        session_name($config->string('SESSION_NAME', 'magiclink_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $config->basePath() ?: '/',
            'secure' => parse_url($config->baseUrl(), PHP_URL_SCHEME) === 'https',
            'httponly' => true,
            'samesite' => $config->string('SESSION_SAMESITE', 'Lax'),
        ]);
        session_start();
        $now = time();
        $created = (int) ($_SESSION['_created_at'] ?? $now);
        $lastSeen = (int) ($_SESSION['_last_seen_at'] ?? $now);
        if (($now - $created) > $config->int('SESSION_ABSOLUTE_SECONDS', 604800) || ($now - $lastSeen) > $config->int('SESSION_IDLE_SECONDS', 28800)) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['_created_at'] ??= $now;
        $_SESSION['_last_seen_at'] = $now;
        $_SESSION['_binding'] ??= bin2hex(random_bytes(32));
        $_SESSION['_csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function csrf(): string
    {
        return (string) ($_SESSION['_csrf'] ?? '');
    }

    public static function binding(): string
    {
        return (string) ($_SESSION['_binding'] ?? '');
    }

    public static function authenticate(string $email): void
    {
        session_regenerate_id(true);
        $_SESSION['auth_email'] = $email;
        $_SESSION['auth_at'] = time();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function email(): ?string
    {
        $email = $_SESSION['auth_email'] ?? null;
        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function rememberRequest(string $selector): void
    {
        $requests = $_SESSION['_magic_requests'] ?? [];
        if (!is_array($requests)) {
            $requests = [];
        }
        $requests[$selector] = time();
        asort($requests, SORT_NUMERIC);
        while (count($requests) > 100) {
            array_shift($requests);
        }
        $_SESSION['_magic_requests'] = $requests;
    }

    public static function ownsRequest(string $selector): bool
    {
        $requests = $_SESSION['_magic_requests'] ?? [];
        return is_array($requests) && isset($requests[$selector]);
    }

    /** @param list<string> $selectors */
    public static function ownsRequests(array $selectors): bool
    {
        foreach ($selectors as $selector) {
            if (!self::ownsRequest($selector)) {
                return false;
            }
        }
        return true;
    }

    public static function claimStatePoll(int $minimumIntervalMs): int
    {
        $now = (int) floor(microtime(true) * 1000);
        $last = (int) ($_SESSION['_last_state_poll_ms'] ?? 0);
        $remaining = $minimumIntervalMs - ($now - $last);
        if ($last > 0 && $remaining > 0) {
            return $remaining;
        }
        $_SESSION['_last_state_poll_ms'] = $now;
        return 0;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
