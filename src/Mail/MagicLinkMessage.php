<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;

final class MagicLinkMessage
{
    public function __construct(private Config $config, private Mailer $mailer) {}

    public function send(string $email, string $url, int $expiresAt): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Outbox recipient is invalid.');
        }
        if (!str_starts_with($url, $this->config->baseUrl() . '/')) {
            throw new \RuntimeException('Outbox URL does not belong to APP_URL.');
        }

        $appName = $this->config->string('APP_NAME');
        $app = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $minutes = max(1, (int) ceil(($expiresAt - time()) / 60));
        $subject = $this->config->locale() === 'de' ? 'Dein sicherer Anmeldelink' : 'Your secure sign-in link';
        if ($this->config->locale() === 'de') {
            $plain = "Öffne diesen Link, um dich bei {$appName} anzumelden:\n\n{$url}\n\nDer Link ist {$minutes} Minuten gültig und kann einmal verwendet werden.";
            $copy = 'Öffne den Link, um dich anzumelden. Er ist einmal verwendbar und läuft nach ' . $minutes . ' Minuten ab.';
            $button = 'Sicher anmelden';
        } else {
            $plain = "Open this link to sign in to {$appName}:\n\n{$url}\n\nThe link is valid for {$minutes} minutes and can be used once.";
            $copy = 'Open the link to sign in. It can be used once and expires after ' . $minutes . ' minutes.';
            $button = 'Sign in securely';
        }
        $html = '<!doctype html><html><body style="margin:0;background:#eef4f3;color:#142024;font-family:Arial,sans-serif">'
            . '<div style="max-width:560px;margin:0 auto;padding:48px 24px"><div style="background:#fff;border:1px solid #cbd8d6;padding:36px">'
            . '<p style="margin:0 0 28px;font-size:13px;color:#43615f">' . $app . '</p>'
            . '<h1 style="font-size:30px;line-height:1.15;margin:0 0 16px">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="font-size:16px;line-height:1.6;margin:0 0 28px">' . htmlspecialchars($copy, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0"><a href="' . $safeUrl . '" style="display:inline-block;background:#087982;color:#fff;text-decoration:none;padding:14px 20px;border-radius:4px">' . htmlspecialchars($button, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p style="font-size:12px;line-height:1.5;color:#607775;margin:28px 0 0">' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div></div></body></html>';

        $this->mailer->send($email, $subject, $html, $plain);
    }
}

