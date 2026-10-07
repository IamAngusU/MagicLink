<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

interface Mailer
{
    public function send(string $to, string $subject, string $html, string $plain): void;
}
