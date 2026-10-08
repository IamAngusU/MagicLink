<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['url:', 'allow:', 'from:', 'name::', 'locale::', 'help']);
$usage = "Usage:\n"
    . "  php bin/install.php\n"
    . "  php bin/install.php --url=https://login.example.com --allow=you@example.com --from=no-reply@example.com [--name=Portal] [--locale=de]\n";

if (array_key_exists('help', $options)) {
    echo $usage;
    exit(0);
}

$envPath = $root . '/.env';
if (is_file($envPath)) {
    fwrite(STDERR, ".env already exists; installation stopped without overwriting it.\n");
    exit(2);
}

function interactiveInput(): bool
{
    return function_exists('stream_isatty') && stream_isatty(STDIN);
}

/** @param callable(string):bool $validate */
function ask(string $label, string $default, callable $validate, string $error): string
{
    while (true) {
        $suffix = $default === '' ? '' : " [{$default}]";
        fwrite(STDOUT, "{$label}{$suffix}: ");
        $line = fgets(STDIN);
        if ($line === false) {
            fwrite(STDERR, "\nInstallation cancelled.\n");
            exit(130);
        }
        $value = trim($line);
        if ($value === '') {
            $value = $default;
        }
        if ($validate($value)) {
            return $value;
        }
        fwrite(STDOUT, "  {$error}\n");
    }
}

function validPublicUrl(string $value): bool
{
    $parts = parse_url($value);
    return filter_var($value, FILTER_VALIDATE_URL) !== false
        && ($parts['scheme'] ?? '') === 'https'
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['query'])
        && !isset($parts['fragment']);
}

function validEmailList(string $value): bool
{
    $emails = array_filter(array_map('trim', explode(',', $value)));
    return $emails !== [] && count($emails) === count(array_filter($emails, static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));
}

$url = trim((string) ($options['url'] ?? ''));
$allow = strtolower(trim((string) ($options['allow'] ?? '')));
$from = strtolower(trim((string) ($options['from'] ?? '')));
$name = trim((string) ($options['name'] ?? 'Magic Link'));
$locale = strtolower(trim((string) ($options['locale'] ?? 'de')));

if (($url === '' || $allow === '' || $from === '') && interactiveInput()) {
    echo "MagicLink setup · secrets stay on this machine\n\n";
    if ($url === '') {
        $url = ask('Public HTTPS URL', '', 'validPublicUrl', 'Use the complete https:// URL without query or fragment.');
    }
    if ($allow === '') {
        $allow = strtolower(ask('Allowed login email(s), comma-separated', '', 'validEmailList', 'Enter at least one valid email address.'));
    }
    if ($from === '') {
        $from = strtolower(ask('Sender email', '', static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_EMAIL) !== false, 'Enter a valid sender address.'));
    }
    $name = ask('Site name', $name, static fn (string $value): bool => $value !== '' && strlen($value) <= 100 && !preg_match('/[\x00-\x1f\x7f]/', $value), 'Use 1 to 100 printable characters.');
    $locale = strtolower(ask('Language (de/en)', $locale, static fn (string $value): bool => in_array(strtolower($value), ['de', 'en'], true), 'Choose de or en.'));
}

if (!validPublicUrl($url) || !validEmailList($allow) || filter_var($from, FILTER_VALIDATE_EMAIL) === false || $name === '' || strlen($name) > 100 || !in_array($locale, ['de', 'en'], true)) {
    fwrite(STDERR, $usage);
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

$handle = @fopen($envPath, 'x');
if (!is_resource($handle)) {
    fwrite(STDERR, ".env could not be created exclusively. Nothing was overwritten.\n");
    exit(1);
}
try {
    if (!flock($handle, LOCK_EX) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
        throw new RuntimeException('.env could not be written completely.');
    }
} catch (Throwable $error) {
    fclose($handle);
    @unlink($envPath);
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
fclose($handle);
@chmod($envPath, 0600);

require $root . '/autoload.php';
try {
    IamAngusU\MagicLink\Kernel::boot($root);
} catch (Throwable $error) {
    fwrite(STDERR, "Config saved, but setup could not finish: {$error->getMessage()}\nRun php bin/doctor.php after fixing the environment.\n");
    exit(1);
}
echo "\nInstalled. Database, schema and app key are ready.\n";
echo "Open {$url}\n";
echo "Next check: php bin/doctor.php\n";
