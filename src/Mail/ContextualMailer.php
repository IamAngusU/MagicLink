<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

interface ContextualMailer extends Mailer
{
    public function sendWithContext(
        string $to,
        string $subject,
        string $html,
        string $plain,
        DeliveryContext $context,
    ): void;
}
