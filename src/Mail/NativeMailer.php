<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;

final class NativeMailer implements ContextualMailer
{
    public function __construct(private Config $config) {}

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
        $fromAddress = $this->config->string('MAIL_FROM_ADDRESS');
        $fromName = $this->headerValue($this->config->string('MAIL_FROM_NAME', $this->config->string('APP_NAME')));
        $subject = $this->headerValue($subject);
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $fromAddress)) {
            throw MailTransportException::configuration('sender');
        }
        $boundary = 'ml_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'Message-ID: <' . $context->messageId . '>',
            'MIME-Version: 1.0',
            'From: ' . $this->encodeHeader($fromName) . ' <' . $fromAddress . '>',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'Auto-Submitted: auto-generated',
            'X-Auto-Response-Suppress: All',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= $this->base64Body($plain);
        $body .= "\r\n--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
        $body .= $this->base64Body($html) . "\r\n--{$boundary}--\r\n";

        $context->heartbeat();
        if (!mail($to, $this->encodeHeader($subject), $body, implode("\r\n", $headers))) {
            throw MailTransportException::transient('native_mail');
        }
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
        $body = preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
        return rtrim(chunk_split(base64_encode($body), 76, "\r\n"), "\r\n");
    }

    private function messageIdHost(): string
    {
        $host = strtolower((string) parse_url($this->config->baseUrl(), PHP_URL_HOST));
        return preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host) ? $host : 'magic-link.local';
    }
}
