<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Mail\MailTemplateRenderer;

$root = dirname(__DIR__);
require $root . '/autoload.php';

$locale = null;
$format = 'html';

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help' || $argument === '-h') {
        echo "Usage: php bin/mail-preview.php [--locale=en|de] [--format=html|plain|subject|all]\n";
        echo "The command renders a dummy link; it never sends mail or creates a valid token.\n";
        exit(0);
    }
    if (str_starts_with($argument, '--locale=')) {
        $locale = substr($argument, strlen('--locale='));
        continue;
    }
    if (str_starts_with($argument, '--format=')) {
        $format = substr($argument, strlen('--format='));
        continue;
    }
    fwrite(STDERR, '[fail] Unknown argument: ' . $argument . PHP_EOL);
    exit(2);
}

if ($locale !== null && !in_array($locale, ['en', 'de'], true)) {
    fwrite(STDERR, "[fail] --locale must be en or de.\n");
    exit(2);
}
if (!in_array($format, ['html', 'plain', 'subject', 'all'], true)) {
    fwrite(STDERR, "[fail] --format must be html, plain, subject or all.\n");
    exit(2);
}

try {
    $config = Config::load($root);
    $message = (new MailTemplateRenderer($config))->render(
        'preview@example.com',
        $config->url('/auth/check?id=ml_preview#token=preview-not-a-real-secret'),
        time() + $config->int('MAGICLINK_TTL_SECONDS', 900),
        $locale,
    );

    if ($format === 'all') {
        echo "--- subject ---\n" . $message['subject'] . "\n\n";
        echo "--- plain ---\n" . $message['plain'] . "\n\n";
        echo "--- html ---\n" . $message['html'] . "\n";
    } else {
        echo $message[$format];
        if (!str_ends_with($message[$format], "\n")) {
            echo PHP_EOL;
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[fail] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
