<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink;

use RuntimeException;

final class Config
{
    /** @param array<string,string> $values */
    private function __construct(private string $root, private array $values) {}

    public static function load(string $root): self
    {
        $values = [];
        $path = rtrim($root, '/\\') . '/.env';
        if (is_file($path)) {
            $values = self::parseEnv((string) file_get_contents($path));
        }

        foreach (array_keys($_ENV + $_SERVER) as $name) {
            $value = getenv((string) $name);
            if ($value !== false) {
                $values[(string) $name] = $value;
            }
        }

        return self::fromArray($root, $values);
    }

    /** @param array<string,string|int|bool> $values */
    public static function fromArray(string $root, array $values): self
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            $normalized[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        $config = new self(rtrim($root, '/\\'), $normalized + [
            'APP_ENV' => 'production',
            'APP_NAME' => 'Magic Link',
            'APP_LOCALE' => 'de',
            'AUTH_SUCCESS_URL' => '',
            'DB_DRIVER' => 'sqlite',
            'DB_PATH' => 'storage/database.sqlite',
            'MAIL_TRANSPORT' => 'mail',
            'MAIL_FROM_NAME' => 'Magic Link',
            'MAGICLINK_ALLOW_ANY_EMAIL' => 'false',
            'MAGICLINK_TTL_SECONDS' => '900',
            'MAGICLINK_IP_LIMIT' => '10',
            'MAGICLINK_EMAIL_LIMIT' => '5',
            'MAGICLINK_RATE_WINDOW' => '3600',
            'MAGICLINK_EXCHANGE_IP_LIMIT' => '60',
            'MAGICLINK_EXCHANGE_SELECTOR_LIMIT' => '10',
            'MAGICLINK_EXCHANGE_WINDOW' => '900',
            'MAGICLINK_POLL_AFTER_MS' => '2500',
            'MAGICLINK_STATE_BATCH_MAX' => 'auto',
            'MAGICLINK_RETENTION_SECONDS' => '604800',
            'AUDIT_RETENTION_SECONDS' => '2592000',
            'MAIL_AUTO_DISPATCH' => 'true',
            'MAIL_WORKER_BATCH' => 'auto',
            'MAIL_MAX_ATTEMPTS' => '5',
            'MAIL_LOCK_TIMEOUT_SECONDS' => '300',
            'MAIL_RETENTION_SECONDS' => '604800',
            'MAINTENANCE_BATCH' => 'auto',
            'MAINTENANCE_INTERVAL_SECONDS' => '900',
            'HTTP_MAX_BODY_BYTES' => '16384',
            'SESSION_NAME' => 'magiclink_session',
            'SESSION_SAMESITE' => 'Lax',
            'SESSION_IDLE_SECONDS' => '28800',
            'SESSION_ABSOLUTE_SECONDS' => '604800',
        ]);
        $config->validate();
        return $config;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function string(string $key, string $default = ''): string
    {
        return trim($this->values[$key] ?? $default);
    }

    public function int(string $key, int $default): int
    {
        $value = filter_var($this->values[$key] ?? $default, FILTER_VALIDATE_INT);
        return $value === false ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = filter_var($this->values[$key] ?? $default, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        return $value ?? $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $values = array_map(static fn (string $value): string => strtolower(trim($value)), explode(',', $this->string($key)));
        return array_values(array_filter(array_unique($values), static fn (string $value): bool => $value !== ''));
    }

    public function locale(): string
    {
        return in_array($this->string('APP_LOCALE'), ['de', 'en'], true) ? $this->string('APP_LOCALE') : 'de';
    }

    public function baseUrl(): string
    {
        return rtrim($this->string('APP_URL'), '/');
    }

    public function basePath(): string
    {
        $path = (string) parse_url($this->baseUrl(), PHP_URL_PATH);
        return $path === '/' ? '' : rtrim($path, '/');
    }

    public function path(string $path): string
    {
        return $this->basePath() . '/' . ltrim($path, '/');
    }

    public function url(string $path): string
    {
        return $this->baseUrl() . '/' . ltrim($path, '/');
    }

    public function appKey(): string
    {
        $configured = $this->string('APP_KEY');
        if ($configured !== '') {
            $decoded = base64_decode($configured, true);
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException('APP_KEY must be base64-encoded 32-byte material.');
            }
            return $decoded;
        }

        $storage = $this->root . '/storage';
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new RuntimeException('storage/ could not be created.');
        }
        $path = $storage . '/app.key';
        if (!is_file($path)) {
            $handle = @fopen($path, 'x');
            if (is_resource($handle)) {
                try {
                    if (fwrite($handle, base64_encode(random_bytes(32)) . PHP_EOL) === false) {
                        throw new RuntimeException('storage/app.key could not be written.');
                    }
                } finally {
                    fclose($handle);
                }
                @chmod($path, 0600);
            } elseif (!is_file($path)) {
                throw new RuntimeException('storage/app.key could not be created atomically.');
            }
        }
        $decoded = base64_decode(trim((string) file_get_contents($path)), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('storage/app.key is invalid.');
        }
        return $decoded;
    }

    private function validate(): void
    {
        $url = $this->baseUrl();
        $urlParts = parse_url($url);
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || empty($urlParts['host']) || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])) {
            throw new RuntimeException('APP_URL must contain the public URL, for example https://login.example.com.');
        }
        if ($this->string('APP_ENV') === 'production' && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Production requires an https APP_URL.');
        }
        if (!in_array($this->string('APP_ENV'), ['production', 'local', 'test'], true)) {
            throw new RuntimeException('APP_ENV must be production, local or test.');
        }
        if (!in_array($this->string('APP_LOCALE'), ['de', 'en'], true)) {
            throw new RuntimeException('APP_LOCALE must be de or en.');
        }
        if ($this->string('APP_NAME') === '' || strlen($this->string('APP_NAME')) > 100 || preg_match('/[\x00-\x1f\x7f]/', $this->string('APP_NAME'))) {
            throw new RuntimeException('APP_NAME must contain 1 to 100 printable characters.');
        }
        $successUrl = $this->string('AUTH_SUCCESS_URL');
        if ($successUrl !== '') {
            $successParts = parse_url($successUrl);
            if (!filter_var($successUrl, FILTER_VALIDATE_URL) || !in_array($successParts['scheme'] ?? '', ['http', 'https'], true) || empty($successParts['host']) || isset($successParts['user']) || isset($successParts['pass'])) {
                throw new RuntimeException('AUTH_SUCCESS_URL must be an absolute http or https URL.');
            }
            if ($this->string('APP_ENV') === 'production' && ($successParts['scheme'] ?? '') !== 'https') {
                throw new RuntimeException('Production AUTH_SUCCESS_URL must use https.');
            }
        }
        if (!in_array($this->string('DB_DRIVER'), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('DB_DRIVER must be sqlite or mysql.');
        }
        if (!in_array($this->string('MAIL_TRANSPORT'), ['mail', 'smtp', 'log'], true)) {
            throw new RuntimeException('MAIL_TRANSPORT must be mail, smtp or log.');
        }
        foreach (['MAGICLINK_ALLOW_ANY_EMAIL', 'MAIL_AUTO_DISPATCH'] as $boolean) {
            if (!in_array(strtolower($this->string($boolean)), ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true)) {
                throw new RuntimeException($boolean . ' must be a boolean value.');
            }
        }
        if ($this->string('APP_ENV') === 'production' && $this->string('MAIL_TRANSPORT') === 'log') {
            throw new RuntimeException('The log mail transport is disabled in production.');
        }
        if ($this->string('MAIL_TRANSPORT') === 'smtp') {
            if ($this->string('SMTP_HOST') === '' || !in_array($this->string('SMTP_ENCRYPTION', 'tls'), ['tls', 'ssl', 'none'], true)) {
                throw new RuntimeException('SMTP_HOST and a valid SMTP_ENCRYPTION are required for SMTP.');
            }
            $smtpPort = $this->int('SMTP_PORT', $this->string('SMTP_ENCRYPTION', 'tls') === 'ssl' ? 465 : 587);
            if ($smtpPort < 1 || $smtpPort > 65535) {
                throw new RuntimeException('SMTP_PORT must be between 1 and 65535.');
            }
            if ($this->string('APP_ENV') === 'production' && $this->string('SMTP_ENCRYPTION', 'tls') === 'none') {
                throw new RuntimeException('Unencrypted SMTP is disabled in production.');
            }
        }
        if (!$this->bool('MAGICLINK_ALLOW_ANY_EMAIL') && $this->list('MAGICLINK_ALLOWED_EMAILS') === [] && $this->list('MAGICLINK_ALLOWED_DOMAINS') === []) {
            throw new RuntimeException('Configure MAGICLINK_ALLOWED_EMAILS or MAGICLINK_ALLOWED_DOMAINS.');
        }
        foreach ($this->list('MAGICLINK_ALLOWED_EMAILS') as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
                throw new RuntimeException('MAGICLINK_ALLOWED_EMAILS contains an invalid address.');
            }
        }
        foreach ($this->list('MAGICLINK_ALLOWED_DOMAINS') as $domain) {
            if (strlen($domain) > 253 || !filter_var('operator@' . $domain, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('MAGICLINK_ALLOWED_DOMAINS contains an invalid domain.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $this->string('SESSION_NAME', 'magiclink_session'))) {
            throw new RuntimeException('SESSION_NAME must contain 1 to 64 safe characters.');
        }
        if ($this->int('MAGICLINK_TTL_SECONDS', 0) < 120 || $this->int('MAGICLINK_TTL_SECONDS', 0) > 3600) {
            throw new RuntimeException('MAGICLINK_TTL_SECONDS must be between 120 and 3600.');
        }
        foreach (['MAGICLINK_IP_LIMIT', 'MAGICLINK_EMAIL_LIMIT'] as $limit) {
            if ($this->int($limit, 0) < 1 || $this->int($limit, 0) > 1000) {
                throw new RuntimeException($limit . ' must be between 1 and 1000.');
            }
        }
        foreach (['MAGICLINK_EXCHANGE_IP_LIMIT', 'MAGICLINK_EXCHANGE_SELECTOR_LIMIT'] as $limit) {
            if ($this->int($limit, 0) < 1 || $this->int($limit, 0) > 10000) {
                throw new RuntimeException($limit . ' must be between 1 and 10000.');
            }
        }
        foreach ([
            'MAGICLINK_RATE_WINDOW' => [60, 86400],
            'MAGICLINK_EXCHANGE_WINDOW' => [60, 86400],
            'MAGICLINK_POLL_AFTER_MS' => [500, 30000],
            'HTTP_MAX_BODY_BYTES' => [1024, 1048576],
            'MAIL_MAX_ATTEMPTS' => [1, 20],
            'MAIL_LOCK_TIMEOUT_SECONDS' => [30, 3600],
            'MAINTENANCE_INTERVAL_SECONDS' => [60, 86400],
            'SESSION_IDLE_SECONDS' => [300, 2592000],
            'SESSION_ABSOLUTE_SECONDS' => [3600, 31536000],
        ] as $name => [$minimum, $maximum]) {
            $value = $this->int($name, 0);
            if ($value < $minimum || $value > $maximum) {
                throw new RuntimeException(sprintf('%s must be between %d and %d.', $name, $minimum, $maximum));
            }
        }
        foreach (['MAGICLINK_RETENTION_SECONDS', 'AUDIT_RETENTION_SECONDS', 'MAIL_RETENTION_SECONDS'] as $retention) {
            if ($this->int($retention, 0) < 3600 || $this->int($retention, 0) > 31536000) {
                throw new RuntimeException($retention . ' must be between 3600 and 31536000.');
            }
        }
        $sameSite = $this->string('SESSION_SAMESITE', 'Lax');
        if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new RuntimeException('SESSION_SAMESITE must be Lax, Strict or None.');
        }
        if ($sameSite === 'None' && parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('SESSION_SAMESITE=None requires an https APP_URL.');
        }
        foreach ($this->list('API_ALLOWED_ORIGINS') as $origin) {
            $parts = parse_url($origin);
            $originPath = (string) ($parts['path'] ?? '');
            if (!filter_var($origin, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || ($originPath !== '' && $originPath !== '/') || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('API_ALLOWED_ORIGINS must contain origins without paths.');
            }
            if ($this->string('APP_ENV') === 'production' && ($parts['scheme'] ?? '') !== 'https') {
                throw new RuntimeException('Production API_ALLOWED_ORIGINS must use https.');
            }
        }
        foreach ($this->list('TRUSTED_PROXIES') as $proxy) {
            [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
            if (!filter_var($address, FILTER_VALIDATE_IP)) {
                throw new RuntimeException('TRUSTED_PROXIES contains an invalid IP address.');
            }
            if ($prefix !== null) {
                $maximum = str_contains($address, ':') ? 128 : 32;
                if (!ctype_digit($prefix) || (int) $prefix > $maximum) {
                    throw new RuntimeException('TRUSTED_PROXIES contains an invalid CIDR prefix.');
                }
            }
        }
    }

    /** @return array<string,string> */
    private static function parseEnv(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/D', $key)) {
                continue;
            }
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }
}
