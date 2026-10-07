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
            'DB_DRIVER' => 'sqlite',
            'DB_PATH' => 'storage/database.sqlite',
            'MAIL_TRANSPORT' => 'mail',
            'MAIL_FROM_NAME' => 'Magic Link',
            'MAGICLINK_ALLOW_ANY_EMAIL' => 'false',
            'MAGICLINK_TTL_SECONDS' => '900',
            'MAGICLINK_IP_LIMIT' => '10',
            'MAGICLINK_EMAIL_LIMIT' => '5',
            'MAGICLINK_RATE_WINDOW' => '3600',
            'SESSION_NAME' => 'magiclink_session',
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
        if (!in_array($this->string('DB_DRIVER'), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('DB_DRIVER must be sqlite or mysql.');
        }
        if (!in_array($this->string('MAIL_TRANSPORT'), ['mail', 'smtp', 'log'], true)) {
            throw new RuntimeException('MAIL_TRANSPORT must be mail, smtp or log.');
        }
        if ($this->string('APP_ENV') === 'production' && $this->string('MAIL_TRANSPORT') === 'log') {
            throw new RuntimeException('The log mail transport is disabled in production.');
        }
        if ($this->string('MAIL_TRANSPORT') === 'smtp') {
            if ($this->string('SMTP_HOST') === '' || !in_array($this->string('SMTP_ENCRYPTION', 'tls'), ['tls', 'ssl', 'none'], true)) {
                throw new RuntimeException('SMTP_HOST and a valid SMTP_ENCRYPTION are required for SMTP.');
            }
            if ($this->string('APP_ENV') === 'production' && $this->string('SMTP_ENCRYPTION', 'tls') === 'none') {
                throw new RuntimeException('Unencrypted SMTP is disabled in production.');
            }
        }
        if (!$this->bool('MAGICLINK_ALLOW_ANY_EMAIL') && $this->list('MAGICLINK_ALLOWED_EMAILS') === [] && $this->list('MAGICLINK_ALLOWED_DOMAINS') === []) {
            throw new RuntimeException('Configure MAGICLINK_ALLOWED_EMAILS or MAGICLINK_ALLOWED_DOMAINS.');
        }
        if ($this->int('MAGICLINK_TTL_SECONDS', 0) < 120 || $this->int('MAGICLINK_TTL_SECONDS', 0) > 3600) {
            throw new RuntimeException('MAGICLINK_TTL_SECONDS must be between 120 and 3600.');
        }
        foreach (['MAGICLINK_IP_LIMIT', 'MAGICLINK_EMAIL_LIMIT'] as $limit) {
            if ($this->int($limit, 0) < 1 || $this->int($limit, 0) > 1000) {
                throw new RuntimeException($limit . ' must be between 1 and 1000.');
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
