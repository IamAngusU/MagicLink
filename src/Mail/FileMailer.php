<?php
declare(strict_types=1);

namespace IamAngusU\MagicLink\Mail;

use IamAngusU\MagicLink\Config;
use RuntimeException;

final class FileMailer implements Mailer
{
    public function __construct(private Config $config) {}

    public function send(string $to, string $subject, string $html, string $plain): void
    {
        $directory = $this->config->root() . '/storage/mail';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Development mail directory could not be created.');
        }
        $message = "To: {$to}\nSubject: {$subject}\n\n{$plain}\n\n--- HTML ---\n{$html}\n";
        $path = $directory . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(5)) . '.eml';
        if (file_put_contents($path, $message, LOCK_EX) === false) {
            throw new RuntimeException('Development mail could not be written.');
        }
        @chmod($path, 0600);
    }
}
