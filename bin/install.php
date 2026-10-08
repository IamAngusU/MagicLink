<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['url:', 'allow:', 'from:', 'name::', 'locale::']);
if (is_file($root . '/.env')) {
    fwrite(STDERR, ".env already exists; installation stopped without overwriting it.\n");
    exit(2);
}
$url = trim((string) ($options['url'] ?? ''));
$allow = strtolower(trim((string) ($options['allow'] ?? '')));
$from = strtolower(trim((string) ($options['from'] ?? '')));
$name = trim((string) ($options['name'] ?? 'Magic Link'));
$locale = in_array(($options['locale'] ?? 'de'), ['de', 'en'], true) ? (string) $options['locale'] : 'de';
if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !filter_var($allow, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/install.php --url=https://login.example.com --allow=you@example.com --from=no-reply@example.com [--name=Portal] [--locale=de]\n");
    exit(2);
}
$contents = (string) file_get_contents($root . '/.env.example');
$replace = [
    'APP_URL' => $url,
    'APP_NAME' => $name,
    'APP_LOCALE' => $locale,
    'MAIL_FROM_ADDRESS' => $from,
    'MAIL_FROM_NAME' => $name,
    'MAGICLINK_ALLOWED_EMAILS' => $allow,
];
foreach ($replace as $key => $value) {
    $safe = str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    $contents = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $key . '="' . $safe . '"', $contents) ?? $contents;
}
if (file_put_contents($root . '/.env', $contents, LOCK_EX) === false) {
    fwrite(STDERR, ".env could not be written.\n");
    exit(1);
}
@chmod($root . '/.env', 0600);
require $root . '/autoload.php';
IamAngusU\MagicLink\Kernel::boot($root);
echo "Installed. Point the document root at {$root}/public and open {$url}.\n";
