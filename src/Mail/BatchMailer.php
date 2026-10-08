<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

interface BatchMailer extends Mailer
{
    public function finishBatch(): void;
}
