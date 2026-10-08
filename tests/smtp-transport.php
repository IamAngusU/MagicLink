<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Mail\DeliveryContext;
use IamAngusU\MagicLink\Mail\MailTransportException;
use IamAngusU\MagicLink\Mail\SmtpMailer;

$root = dirname(__DIR__);
require $root . '/autoload.php';

function smtpExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{process:resource,pipes:array<int,resource>,output:string,port:int} */
function startFakeSmtp(string $root, string $temporary, int $recipientCode, int $messages): array
{
    $output = $temporary . '/smtp-' . $recipientCode . '-' . bin2hex(random_bytes(3)) . '.json';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open([
        PHP_BINARY,
        $root . '/tests/fixtures/fake-smtp-server.php',
        $output,
        (string) $recipientCode,
        (string) $messages,
    ], $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Fake SMTP process could not start.');
    }
    fclose($pipes[0]);
    $ready = fgets($pipes[1]);
    $payload = json_decode((string) $ready, true, 4, JSON_THROW_ON_ERROR);
    return ['process' => $process, 'pipes' => $pipes, 'output' => $output, 'port' => (int) $payload['port']];
}

/** @param array{process:resource,pipes:array<int,resource>,output:string,port:int} $server @return array<string,mixed> */
function stopFakeSmtp(array $server): array
{
    $stdout = stream_get_contents($server['pipes'][1]);
    $stderr = stream_get_contents($server['pipes'][2]);
    fclose($server['pipes'][1]);
    fclose($server['pipes'][2]);
    $status = proc_close($server['process']);
    smtpExpect($status === 0, 'Fake SMTP failed: ' . trim($stdout . ' ' . $stderr));
    return json_decode((string) file_get_contents($server['output']), true, 8, JSON_THROW_ON_ERROR);
}

function smtpConfig(string $temporary, int $port): Config
{
    return Config::fromArray($temporary, [
        'APP_ENV' => 'test',
        'APP_URL' => 'http://127.0.0.1:8080',
        'APP_NAME' => 'SMTP Test',
        'APP_LOCALE' => 'en',
        'DB_DRIVER' => 'sqlite',
        'DB_PATH' => 'storage/unused.sqlite',
        'MAIL_TRANSPORT' => 'smtp',
        'MAIL_FROM_ADDRESS' => 'sender@example.com',
        'MAIL_FROM_NAME' => 'Mäil Test',
        'SMTP_HOST' => '127.0.0.1',
        'SMTP_PORT' => (string) $port,
        'SMTP_ENCRYPTION' => 'none',
        'MAGICLINK_ALLOWED_DOMAINS' => 'example.com',
        'MAIL_LOCK_TIMEOUT_SECONDS' => '30',
    ]);
}

$temporary = sys_get_temp_dir() . '/magic-link-smtp-' . bin2hex(random_bytes(6));
mkdir($temporary . '/storage', 0700, true);

// The application, not ambient OpenSSL policy, owns the SMTP protocol floor.
$tlsMethod = new ReflectionMethod(SmtpMailer::class, 'tlsCryptoMethod');
$tlsMask = $tlsMethod->invoke(new SmtpMailer(smtpConfig($temporary, 587)));
$expectedTlsMask = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
    $expectedTlsMask |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
}
smtpExpect($tlsMask === $expectedTlsMask, 'SMTP TLS must allow exactly TLS 1.2 and supported newer versions.');

// Two messages share one SMTP session, use stable caller-supplied IDs and emit
// RFC-safe base64 MIME lines without the former CRLF duplication.
$successServer = startFakeSmtp($root, $temporary, 250, 2);
$smtp = new SmtpMailer(smtpConfig($temporary, $successServer['port']));
$heartbeats = 0;
$smtp->sendWithContext(
    'one@example.com',
    'Sicher anmelden',
    '<p>Hällo<br>one</p>',
    ".first\nsecond",
    new DeliveryContext('ml-first@example.com', function () use (&$heartbeats): void { $heartbeats++; }),
);
$smtp->sendWithContext(
    'two@example.com',
    'Sign in',
    '<p>Hello two</p>',
    "first\r\nsecond",
    new DeliveryContext('ml-second@example.com', function () use (&$heartbeats): void { $heartbeats++; }),
);
$smtp->finishBatch();
$success = stopFakeSmtp($successServer);
smtpExpect(count($success['messages']) === 2, 'Both messages must be accepted.');
smtpExpect(count(array_filter($success['commands'], static fn (string $line): bool => str_starts_with($line, 'EHLO '))) === 1, 'A batch must reuse one authenticated SMTP connection.');
smtpExpect(count(array_filter($success['commands'], static fn (string $line): bool => $line === 'NOOP')) === 1, 'A reused SMTP session must be checked before the next envelope.');
smtpExpect($heartbeats >= 2, 'SMTP protocol progress must invoke the lease heartbeat.');
foreach ($success['messages'] as $index => $message) {
    $expectedId = $index === 0 ? 'ml-first@example.com' : 'ml-second@example.com';
    smtpExpect(str_contains($message, 'Message-ID: <' . $expectedId . ">\r\n"), 'The supplied stable Message-ID must reach the wire.');
    smtpExpect(substr_count($message, 'Content-Transfer-Encoding: base64') === 2, 'Both MIME alternatives must be safely encoded.');
    smtpExpect(!str_contains($message, "\r\r\n") && !preg_match('/(?<!\r)\n/', $message), 'SMTP output must use canonical CRLF exactly once.');
    foreach (explode("\r\n", $message) as $line) {
        smtpExpect(strlen($line) <= 998, 'No MIME line may exceed the RFC line limit.');
    }
}

$forwardServer = startFakeSmtp($root, $temporary, 252, 1);
$forwardingSmtp = new SmtpMailer(smtpConfig($temporary, $forwardServer['port']));
$forwardingSmtp->sendWithContext(
    'forward@example.com',
    'Sign in',
    '<p>test</p>',
    'test',
    new DeliveryContext('ml-forward@example.com'),
);
$forwardingSmtp->finishBatch();
smtpExpect(count(stopFakeSmtp($forwardServer)['messages']) === 1, 'SMTP 252 must remain a legitimate accepted-recipient response.');

// SMTP 4xx and 5xx responses retain only their safe numeric code and map to
// retryable/permanent outcomes respectively.
foreach ([450 => true, 550 => false] as $responseCode => $retryable) {
    $rejectServer = startFakeSmtp($root, $temporary, $responseCode, 1);
    $rejectingSmtp = new SmtpMailer(smtpConfig($temporary, $rejectServer['port']));
    try {
        $rejectingSmtp->sendWithContext(
            'reject@example.com',
            'Sign in',
            '<p>test</p>',
            'test',
            new DeliveryContext('ml-reject-' . $responseCode . '@example.com'),
        );
        throw new RuntimeException('SMTP rejection was unexpectedly accepted.');
    } catch (MailTransportException $error) {
        smtpExpect($error->transportCode === $responseCode && $error->retryable === $retryable, 'SMTP response classification is wrong.');
    }
    stopFakeSmtp($rejectServer);
}

echo "SMTP transport tests passed.\n";
