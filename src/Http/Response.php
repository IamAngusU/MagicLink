<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Http;

final class Response
{
    /** @param array<string,string> $headers */
    private function __construct(private string $body, private int $status, private array $headers) {}

    /** @param array<string,string> $headers */
    public static function html(string $body, int $status = 200, array $headers = []): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=UTF-8'] + $headers);
    }

    /** @param array<string,mixed> $payload */
    /** @param array<string,string> $headers */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status, ['Content-Type' => 'application/json; charset=UTF-8'] + $headers);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    /** @param array<string,string> $headers */
    public static function noContent(array $headers = []): self
    {
        return new self('', 204, $headers);
    }

    /** @param array<string,string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->body, $this->status, $headers + $this->headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(?callable $afterResponse = null): never
    {
        $body = $this->body;
        $status = $this->status;
        $headers = $this->headers;
        if (session_status() === PHP_SESSION_ACTIVE && !@session_write_close()) {
            $body = 'The session could not be persisted.';
            $status = 500;
            $headers = [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
            ] + $headers;
        }
        http_response_code($status);
        $headers = ['Content-Length' => (string) strlen($body)] + $headers;
        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($afterResponse !== null) {
            ignore_user_abort(true);
        }
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) {
                @ob_end_flush();
            }
            flush();
        }
        if ($afterResponse !== null) {
            try {
                $afterResponse();
            } catch (\Throwable $error) {
                error_log('MagicLink deferred work failed: ' . $error->getMessage());
            }
        }
        exit;
    }
}
