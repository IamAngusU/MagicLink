<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Http;

use RuntimeException;

final class Request
{
    /** @var array<string,mixed>|null */
    private ?array $input = null;

    public function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    /** @return array<string,mixed> */
    public function input(): array
    {
        if ($this->input !== null) {
            return $this->input;
        }
        $contentType = strtolower($this->header('Content-Type'));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Invalid JSON request.');
            }
            return $this->input = $decoded;
        }
        return $this->input = $_POST;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (strtolower($name) === 'content-type') {
            $key = 'CONTENT_TYPE';
        }
        return trim((string) ($_SERVER[$key] ?? ''));
    }

    public function ip(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
