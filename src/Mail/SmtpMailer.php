<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;
use Throwable;

final class SmtpMailer implements ContextualMailer, BatchMailer
{
    /** @var resource|null */
    private $stream = null;
    private ?DeliveryContext $context = null;

    public function __construct(private Config $config) {}

    public function __destruct()
    {
        $this->close(false);
    }

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        $this->sendWithContext(
            $to,
            $subject,
            $html,
            $plain,
            new DeliveryContext('ml-' . bin2hex(random_bytes(16)) . '@' . $this->messageIdHost()),
        );
    }

    public function sendWithContext(
        string $to,
        string $subject,
        string $html,
        string $plain,
        DeliveryContext $context,
    ): void {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to)) {
            throw MailTransportException::configuration('recipient');
        }
        $from = $this->config->string('MAIL_FROM_ADDRESS');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)) {
            throw MailTransportException::configuration('sender');
        }

        $this->context = $context;
        try {
            $context->heartbeat();
            $this->ensureConnected();
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251, 252]);
            $this->command('DATA', [354]);
            $message = $this->normalizeCrlf($this->message($to, $from, $subject, $html, $plain, $context->messageId));
            $message = preg_replace('/(?m)^\./', '..', $message) ?? $message;
            $context->heartbeat();
            $this->write(rtrim($message, "\r\n") . "\r\n.\r\n");
            $this->expect([250]);
        } catch (DeliveryOwnershipLost | MailTransportException $error) {
            $this->close(false);
            throw $error;
        } catch (Throwable $error) {
            $this->close(false);
            throw MailTransportException::transient('smtp_internal', $error);
        } finally {
            $this->context = null;
        }
    }

    public function finishBatch(): void
    {
        $this->close(true);
    }

    private function ensureConnected(): void
    {
        if (is_resource($this->stream)) {
            try {
                $this->command('NOOP', [250]);
                return;
            } catch (DeliveryOwnershipLost $error) {
                throw $error;
            } catch (Throwable) {
                $this->close(false);
            }
        }

        $host = $this->config->string('SMTP_HOST');
        if (!preg_match('/^[A-Za-z0-9.-]+$/D', $host)) {
            throw MailTransportException::configuration('smtp_host');
        }
        $encryption = $this->config->string('SMTP_ENCRYPTION', 'tls');
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw MailTransportException::configuration('smtp_encryption');
        }
        $port = $this->config->int('SMTP_PORT', $encryption === 'ssl' ? 465 : 587);
        if ($port < 1 || $port > 65535) {
            throw MailTransportException::configuration('smtp_port');
        }
        $cryptoMethod = $this->tlsCryptoMethod();
        $socketContext = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'allow_self_signed' => false,
            'crypto_method' => $cryptoMethod,
        ]]);
        $address = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $stream = @stream_socket_client($address, $errorCode, $errorMessage, 15, STREAM_CLIENT_CONNECT, $socketContext);
        if (!is_resource($stream)) {
            throw new MailTransportException('smtp_connect', true, $errorCode > 0 ? $errorCode : null);
        }
        $this->stream = $stream;
        $timeout = max(5, min(15, intdiv($this->config->int('MAIL_LOCK_TIMEOUT_SECONDS', 300), 3)));
        stream_set_timeout($stream, $timeout);

        try {
            $this->expect([220]);
            $this->command('EHLO magic-link.local', [250]);
            if ($encryption === 'tls') {
                $this->command('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($stream, true, $cryptoMethod)) {
                    throw MailTransportException::transient('smtp_tls');
                }
                $this->command('EHLO magic-link.local', [250]);
            }
            $username = $this->config->string('SMTP_USERNAME');
            if ($username !== '') {
                $this->command('AUTH LOGIN', [334]);
                $this->command(base64_encode($username), [334]);
                $this->command(base64_encode($this->config->string('SMTP_PASSWORD')), [235]);
            }
        } catch (Throwable $error) {
            $this->close(false);
            throw $error;
        }
    }

    private function message(string $to, string $from, string $subject, string $html, string $plain, string $messageId): string
    {
        $name = $this->headerValue($this->config->string('MAIL_FROM_NAME', $this->config->string('APP_NAME')));
        $subject = $this->headerValue($subject);
        $boundary = 'ml_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . $messageId . '>',
            'To: <' . $to . '>',
            'From: ' . $this->encodeHeader($name) . ' <' . $from . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        return implode("\r\n", $headers)
            . "\r\n\r\n--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $this->base64Body($plain)
            . "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . $this->base64Body($html)
            . "\r\n--{$boundary}--\r\n";
    }

    /** @param list<int> $codes */
    private function command(string $command, array $codes): void
    {
        if (!is_resource($this->stream) || preg_match('/[\r\n]/', $command)) {
            throw MailTransportException::transient('smtp_protocol');
        }
        $this->heartbeat();
        $this->write($command . "\r\n");
        $this->expect($codes);
    }

    private function write(string $payload): void
    {
        if (!is_resource($this->stream)) {
            throw MailTransportException::transient('smtp_connection');
        }
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $this->heartbeat();
            $written = @fwrite($this->stream, substr($payload, $offset));
            if ($written === false || $written === 0) {
                $meta = stream_get_meta_data($this->stream);
                throw MailTransportException::transient(!empty($meta['timed_out']) ? 'smtp_timeout' : 'smtp_connection');
            }
            $offset += $written;
        }
    }

    /** @param list<int> $codes */
    private function expect(array $codes): void
    {
        if (!is_resource($this->stream)) {
            throw MailTransportException::transient('smtp_connection');
        }
        $responseBytes = 0;
        $lines = 0;
        $responseCode = null;
        do {
            $this->heartbeat();
            $line = @fgets($this->stream, 2048);
            if ($line === false) {
                $meta = stream_get_meta_data($this->stream);
                throw MailTransportException::transient(!empty($meta['timed_out']) ? 'smtp_timeout' : 'smtp_connection');
            }
            $responseBytes += strlen($line);
            $lines++;
            if ($lines > 100 || $responseBytes > 65536 || !preg_match('/^([0-9]{3})([ -])[^\r\n]*(?:\r\n|\n)$/D', $line, $match)) {
                throw MailTransportException::transient('smtp_protocol');
            }
            $lineCode = (int) $match[1];
            if ($responseCode !== null && $responseCode !== $lineCode) {
                throw MailTransportException::transient('smtp_protocol');
            }
            $responseCode = $lineCode;
            $continued = $match[2] === '-';
        } while ($continued);

        if (!in_array($responseCode, $codes, true)) {
            if ($responseCode !== null && $responseCode >= 400 && $responseCode < 600) {
                throw MailTransportException::smtp($responseCode);
            }
            throw new MailTransportException('smtp_protocol', true, $responseCode);
        }
    }

    private function heartbeat(): void
    {
        $this->context?->heartbeat();
    }

    private function close(bool $graceful): void
    {
        if (!is_resource($this->stream)) {
            $this->stream = null;
            return;
        }
        if ($graceful) {
            try {
                $this->write("QUIT\r\n");
                $this->expect([221]);
            } catch (Throwable) {
                // Delivery is already complete; closing the socket is sufficient.
            }
        }
        fclose($this->stream);
        $this->stream = null;
    }

    private function headerValue(string $value): string
    {
        if ($value === '' || strlen($value) > 500 || preg_match('/[\x00-\x1f\x7f]/', $value) || !preg_match('//u', $value)) {
            throw MailTransportException::configuration('mail_header');
        }
        return $value;
    }

    private function encodeHeader(string $value): string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw MailTransportException::configuration('mail_header');
        }
        $chunks = [];
        $chunk = '';
        foreach ($characters as $character) {
            if ($chunk !== '' && strlen($chunk . $character) > 30) {
                $chunks[] = $chunk;
                $chunk = '';
            }
            $chunk .= $character;
        }
        if ($chunk !== '') {
            $chunks[] = $chunk;
        }
        return implode(' ', array_map(
            static fn (string $part): string => '=?UTF-8?B?' . base64_encode($part) . '?=',
            $chunks,
        ));
    }

    private function base64Body(string $body): string
    {
        return rtrim(chunk_split(base64_encode($this->normalizeCrlf($body)), 76, "\r\n"), "\r\n");
    }

    private function normalizeCrlf(string $value): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $value) ?? $value;
    }

    private function messageIdHost(): string
    {
        $host = strtolower((string) parse_url($this->config->baseUrl(), PHP_URL_HOST));
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host) ? $host : 'magic-link.local';
    }

    private function tlsCryptoMethod(): int
    {
        if (!defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            throw MailTransportException::configuration('smtp_tls_version');
        }
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        return $method;
    }
}
