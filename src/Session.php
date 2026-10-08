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
        self::setIni('session.use_strict_mode', '1');
        self::setIni('session.use_only_cookies', '1');
        self::setIni('session.use_trans_sid', '0');
        self::setIni('session.lazy_write', '1');
        if ($config->string('SESSION_STORAGE', 'files') === 'files') {
            self::configureFileStorage($config);
            self::setIni('session.gc_maxlifetime', (string) min(
                $config->int('SESSION_IDLE_SECONDS', 28800),
                $config->int('SESSION_ABSOLUTE_SECONDS', 604800),
            ));
            self::setIni('session.gc_probability', '1');
            self::setIni('session.gc_divisor', '100');
        }
        session_name($config->string('SESSION_NAME', 'magiclink_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $config->basePath() ?: '/',
            'secure' => parse_url($config->baseUrl(), PHP_URL_SCHEME) === 'https',
            'httponly' => true,
            'samesite' => $config->string('SESSION_SAMESITE', 'Lax'),
        ]);
        if (!@session_start()) {
            throw new \RuntimeException('The configured session store could not start a persistent session.');
        }
        $now = time();
        $created = (int) ($_SESSION['_created_at'] ?? $now);
        $lastSeen = (int) ($_SESSION['_last_seen_at'] ?? $now);
        if (($now - $created) > $config->int('SESSION_ABSOLUTE_SECONDS', 604800) || ($now - $lastSeen) > $config->int('SESSION_IDLE_SECONDS', 28800)) {
            $_SESSION = [];
            if (!session_regenerate_id(true)) {
                throw new \RuntimeException('The expired session could not be rotated safely.');
            }
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

    public static function rotateForAuthentication(): void
    {
        if (!session_regenerate_id(true)) {
            throw new \RuntimeException('The authenticated session could not be rotated safely.');
        }
    }

    public static function authenticate(string $email): void
    {
        $_SESSION['auth_email'] = $email;
        $_SESSION['auth_at'] = time();
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    public static function email(): ?string
    {
        $email = $_SESSION['auth_email'] ?? null;
        return is_string($email) && $email !== '' ? $email : null;
    }

    public static function bindHandoffRequest(string $request): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $request)) {
            throw new \InvalidArgumentException('Invalid handoff request.');
        }
        $_SESSION['_handoff_request'] = $request;
    }

    public static function handoffRequest(): ?string
    {
        $request = $_SESSION['_handoff_request'] ?? null;
        return is_string($request) && preg_match('/^[A-Za-z0-9_-]{43}$/D', $request) ? $request : null;
    }

    public static function clearHandoffRequest(): void
    {
        unset($_SESSION['_handoff_request']);
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

    private static function configureFileStorage(Config $config): void
    {
        $savePath = $config->string('SESSION_SAVE_PATH', 'storage/sessions');
        if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $savePath)) {
            $savePath = $config->root() . '/' . ltrim($savePath, '/\\');
        }
        if (is_link($savePath)) {
            throw new \RuntimeException('SESSION_SAVE_PATH must not be a symbolic link.');
        }
        if (!is_dir($savePath) && !mkdir($savePath, 0700, true) && !is_dir($savePath)) {
            throw new \RuntimeException('Session storage could not be created.');
        }
        $resolved = realpath($savePath);
        if ($resolved === false || !is_writable($resolved)) {
            throw new \RuntimeException('Session storage is not writable.');
        }

        $public = realpath($config->root() . '/public');
        if ($public !== false && self::pathWithin($resolved, $public)) {
            throw new \RuntimeException('SESSION_SAVE_PATH must be outside the public web root.');
        }

        @chmod($resolved, 0700);
        if (DIRECTORY_SEPARATOR === '/') {
            $permissions = fileperms($resolved);
            if ($permissions === false || ($permissions & 0077) !== 0) {
                throw new \RuntimeException('Session storage must not be accessible by group or other users.');
            }
        }
        if (session_save_path($resolved) === false) {
            throw new \RuntimeException('Session storage could not be configured.');
        }
    }

    private static function pathWithin(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $parent = rtrim(str_replace('\\', '/', $parent), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path, $parent . '/');
    }

    private static function setIni(string $name, string $value): void
    {
        if (ini_set($name, $value) === false || (string) ini_get($name) !== $value) {
            throw new \RuntimeException(sprintf('PHP setting %s could not be secured.', $name));
        }
    }
}
