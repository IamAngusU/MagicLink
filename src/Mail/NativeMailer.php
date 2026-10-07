<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;
use RuntimeException;

final class NativeMailer implements Mailer
{
    public function __construct(private Config $config) {}

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        $fromAddress = $this->cleanHeader($this->config->string('MAIL_FROM_ADDRESS'));
        $fromName = $this->cleanHeader($this->config->string('MAIL_FROM_NAME', $this->config->string('APP_NAME')));
        if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('MAIL_FROM_ADDRESS is invalid.');
        }
        $boundary = 'ml_' . bin2hex(random_bytes(12));
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . sprintf('"%s" <%s>', addcslashes($fromName, '"\\'), $fromAddress),
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Auto-Response-Suppress: All',
        ];
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$plain}\r\n";
        $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--";
        if (!mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers))) {
            throw new RuntimeException('The configured PHP mail transport rejected the message.');
        }
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }
}
