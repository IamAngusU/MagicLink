<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;

final class MagicLinkMessage
{
    private MailTemplateRenderer $renderer;

    public function __construct(private Config $config, private Mailer $mailer)
    {
        $this->renderer = new MailTemplateRenderer($config);
    }

    public function send(string $email, string $url, int $expiresAt, ?DeliveryContext $context = null): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Outbox recipient is invalid.');
        }
        if (!str_starts_with($url, $this->config->baseUrl() . '/')) {
            throw new \RuntimeException('Outbox URL does not belong to APP_URL.');
        }

        $rendered = $this->renderer->render($email, $url, $expiresAt);
        if ($context !== null && $this->mailer instanceof ContextualMailer) {
            $this->mailer->sendWithContext($email, $rendered['subject'], $rendered['html'], $rendered['plain'], $context);
            return;
        }

        $this->mailer->send($email, $rendered['subject'], $rendered['html'], $rendered['plain']);
    }

    public function finishBatch(): void
    {
        if ($this->mailer instanceof BatchMailer) {
            $this->mailer->finishBatch();
        }
    }
}
