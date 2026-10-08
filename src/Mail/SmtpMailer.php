<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;
use RuntimeException;

final class SmtpMailer implements Mailer
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(private Config $config) {}

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Recipient email is invalid.');
        }
        $host = $this->config->string('SMTP_HOST');
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)) {
            throw new RuntimeException('SMTP_HOST is invalid.');
        }
        $encryption = $this->config->string('SMTP_ENCRYPTION', 'tls');
        $port = $this->config->int('SMTP_PORT', $encryption === 'ssl' ? 465 : 587);
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'allow_self_signed' => false,
        ]]);
        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($address, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($stream)) {
            throw new RuntimeException('SMTP connection failed: ' . $errorMessage);
        }
        $this->stream = $stream;
        stream_set_timeout($stream, 15);
        try {
            $this->expect([220]);
            $this->command('EHLO magic-link.local', [250]);
            if ($encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS negotiation failed.');
                }
                $this->command('EHLO magic-link.local', [250]);
            }
            $username = $this->config->string('SMTP_USERNAME');
            if ($username !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($username), [334]);
                $this->command(base64_encode($this->config->string('SMTP_PASSWORD')), [235]);
            }
            $from = $this->config->string('MAIL_FROM_ADDRESS');
            if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('MAIL_FROM_ADDRESS is invalid.');
            }
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $message = $this->message($to, $from, $subject, $html, $plain);
            $message = preg_replace('/(?m)^\./', '..', str_replace(["\r\n", "\r", "\n"], "\r\n", $message)) ?? $message;
            $this->write($message . "\r\n.\r\n");
            $this->expect([250]);
            $this->command('QUIT', [221]);
        } finally {
            fclose($stream);
            $this->stream = null;
        }
    }

    private function message(string $to, string $from, string $subject, string $html, string $plain): string
    {
        $name = $this->cleanHeader($this->config->string('MAIL_FROM_NAME', $this->config->string('APP_NAME')));
        $boundary = 'ml_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (parse_url($this->config->baseUrl(), PHP_URL_HOST) ?: 'localhost') . '>',
            'To: <' . $to . '>',
            'From: "' . addcslashes($name, '"\\') . '" <' . $from . '>',
            'Subject: =?UTF-8?B?' . base64_encode($this->cleanHeader($subject)) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Auto-Response-Suppress: All',
        ];
        return implode("\r\n", $headers)
            . "\r\n\r\n--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$plain}"
            . "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}"
            . "\r\n--{$boundary}--";
    }

    /** @param list<int> $codes */
    private function command(string $command, array $codes): void
    {
        if (!is_resource($this->stream) || preg_match('/[\r\n]/', $command)) {
            throw new RuntimeException('Invalid SMTP command.');
        }
        $this->write($command . "\r\n");
        $this->expect($codes);
    }

    private function write(string $payload): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('SMTP stream is unavailable.');
        }
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = fwrite($this->stream, substr($payload, $offset));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($this->stream);
                throw new RuntimeException(!empty($meta['timed_out']) ? 'SMTP write timed out.' : 'SMTP write failed.');
            }
            $offset += $written;
        }
    }

    /** @param list<int> $codes */
    private function expect(array $codes): void
    {
        if (!is_resource($this->stream)) {
            throw new RuntimeException('SMTP stream is unavailable.');
        }
        $response = '';
        $lines = 0;
        do {
            $line = fgets($this->stream, 2048);
            if ($line === false) {
                throw new RuntimeException('SMTP server closed the connection.');
            }
            $response .= $line;
            $lines++;
            if ($lines > 100 || strlen($response) > 65536) {
                throw new RuntimeException('SMTP response exceeded the safety limit.');
            }
        } while (isset($line[3]) && $line[3] === '-');
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP rejected the request with code ' . $code . '.');
        }
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
