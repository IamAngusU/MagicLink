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
        session_name($config->string('SESSION_NAME', 'magiclink_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $config->basePath() ?: '/',
            'secure' => parse_url($config->baseUrl(), PHP_URL_SCHEME) === 'https',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $_SESSION['_created_at'] ??= time();
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

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
