<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Http;

use IamAngusU\MagicLink\Exception\BadRequest;
use IamAngusU\MagicLink\Exception\PayloadTooLarge;

final class Request
{
    /** @var array<string,mixed>|null */
    private ?array $input = null;
    private ?string $requestId = null;

    /** @param list<string> $trustedProxies */
    public function __construct(private int $maxBodyBytes = 16384, private array $trustedProxies = []) {}

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
        $contentLength = $this->header('Content-Length');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int) $contentLength > $this->maxBodyBytes) {
            throw new PayloadTooLarge('Request body is too large.');
        }

        $contentType = strtolower(trim(explode(';', $this->header('Content-Type'), 2)[0]));
        if ($contentType === 'application/json') {
            $body = file_get_contents('php://input', false, null, 0, $this->maxBodyBytes + 1);
            if (!is_string($body) || strlen($body) > $this->maxBodyBytes) {
                throw new PayloadTooLarge('Request body is too large.');
            }
            try {
                $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new BadRequest('Invalid JSON request.', previous: $error);
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new BadRequest('JSON body must be an object.');
            }
            return $this->input = $decoded;
        }
        if ($contentType !== '' && $contentType !== 'application/x-www-form-urlencoded') {
            throw new BadRequest('Content-Type must be application/json or application/x-www-form-urlencoded.');
        }
        $this->measureFormInput($_POST);
        return $this->input = $_POST;
    }

    public function header(string $name): string
    {
        $normalized = strtolower($name);
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($normalized === 'content-type') {
            $key = 'CONTENT_TYPE';
        } elseif ($normalized === 'content-length') {
            $key = 'CONTENT_LENGTH';
        } elseif ($normalized === 'authorization' && !isset($_SERVER[$key])) {
            $key = 'REDIRECT_HTTP_AUTHORIZATION';
        }
        return trim((string) ($_SERVER[$key] ?? ''));
    }

    public function ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) {
            return '0.0.0.0';
        }
        if (!$this->isTrustedProxy($remote)) {
            return $remote;
        }

        $forwarded = array_map('trim', explode(',', $this->header('X-Forwarded-For')));
        if ($forwarded === [''] || count($forwarded) > 20) {
            return $remote;
        }
        foreach ($forwarded as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) {
                return $remote;
            }
        }

        $current = $remote;
        for ($index = count($forwarded) - 1; $index >= 0; $index--) {
            if (!$this->isTrustedProxy($current)) {
                return $current;
            }
            $current = $forwarded[$index];
        }
        return $current;
    }

    public function requestId(): string
    {
        if ($this->requestId !== null) {
            return $this->requestId;
        }
        $provided = $this->header('X-Request-ID');
        if ($provided !== '' && preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $provided)) {
            return $this->requestId = $provided;
        }
        return $this->requestId = bin2hex(random_bytes(12));
    }

    /** @param array<mixed> $input */
    private function measureFormInput(array $input): void
    {
        $bytes = 0;
        $nodes = 0;
        $stack = [[$input, 0]];
        while ($stack !== []) {
            [$values, $depth] = array_pop($stack);
            if ($depth > 4) {
                throw new PayloadTooLarge('Request body nesting is too deep.');
            }
            foreach ($values as $key => $value) {
                $nodes++;
                $bytes += strlen((string) $key);
                if ($nodes > 512 || $bytes > $this->maxBodyBytes) {
                    throw new PayloadTooLarge('Request body is too large.');
                }
                if (is_array($value)) {
                    $stack[] = [$value, $depth + 1];
                    continue;
                }
                if (!is_string($value)) {
                    throw new BadRequest('Form fields must contain strings or lists.');
                }
                $bytes += strlen($value);
                if ($bytes > $this->maxBodyBytes) {
                    throw new PayloadTooLarge('Request body is too large.');
                }
            }
        }
    }

    private function isTrustedProxy(string $address): bool
    {
        foreach ($this->trustedProxies as $trusted) {
            if ($this->ipMatches($address, $trusted)) {
                return true;
            }
        }
        return false;
    }

    private function ipMatches(string $address, string $rule): bool
    {
        [$network, $prefix] = array_pad(explode('/', $rule, 2), 2, null);
        if (!filter_var($network, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($prefix === null) {
            return hash_equals($network, $address);
        }
        if (!ctype_digit($prefix)) {
            return false;
        }
        $addressBytes = inet_pton($address);
        $networkBytes = inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }
        $bits = (int) $prefix;
        $maximum = strlen($addressBytes) * 8;
        if ($bits < 0 || $bits > $maximum) {
            return false;
        }
        $whole = intdiv($bits, 8);
        $remaining = $bits % 8;
        if (substr($addressBytes, 0, $whole) !== substr($networkBytes, 0, $whole)) {
            return false;
        }
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($addressBytes[$whole]) & $mask) === (ord($networkBytes[$whole]) & $mask);
    }
}
