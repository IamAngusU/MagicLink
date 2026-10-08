<?php
declare(strict_types=1);

$output = (string) ($argv[1] ?? '');
$recipientCode = (int) ($argv[2] ?? 250);
$expectedMessages = max(1, (int) ($argv[3] ?? 1));
$server = @stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
if (!is_resource($server)) {
    fwrite(STDERR, "server:{$errorCode}\n");
    exit(2);
}
$address = (string) stream_socket_get_name($server, false);
$separator = strrpos($address, ':');
$port = $separator === false ? 0 : (int) substr($address, $separator + 1);
fwrite(STDOUT, json_encode(['port' => $port], JSON_THROW_ON_ERROR) . PHP_EOL);
fflush(STDOUT);

$messages = [];
$commands = [];
$client = @stream_socket_accept($server, 10);
if (is_resource($client)) {
    stream_set_timeout($client, 10);
    fwrite($client, "220 fake-smtp.local ESMTP\r\n");
    while (($line = fgets($client, 4096)) !== false) {
        $command = rtrim($line, "\r\n");
        $commands[] = preg_replace('/^(AUTH\s+).*/i', '$1[redacted]', $command) ?? '[invalid]';
        if (str_starts_with($command, 'EHLO ')) {
            fwrite($client, "250-fake-smtp.local\r\n250 PIPELINING\r\n");
        } elseif ($command === 'NOOP') {
            fwrite($client, "250 ok\r\n");
        } elseif (str_starts_with($command, 'MAIL FROM:')) {
            fwrite($client, "250 sender ok\r\n");
        } elseif (str_starts_with($command, 'RCPT TO:')) {
            fwrite($client, $recipientCode . " recipient response\r\n");
        } elseif ($command === 'DATA') {
            fwrite($client, "354 end with dot\r\n");
            $message = '';
            while (($dataLine = fgets($client, 4096)) !== false && $dataLine !== ".\r\n" && $dataLine !== ".\n") {
                $message .= $dataLine;
            }
            $messages[] = $message;
            fwrite($client, "250 queued\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($client, "221 bye\r\n");
            break;
        } else {
            fwrite($client, "500 unsupported\r\n");
        }
    }
    fclose($client);
}
fclose($server);
if ($output !== '') {
    file_put_contents($output, json_encode([
        'messages' => $messages,
        'commands' => $commands,
        'expected_messages' => $expectedMessages,
    ], JSON_THROW_ON_ERROR));
}
$acceptsRecipient = $recipientCode >= 200 && $recipientCode < 300;
exit(count($messages) === ($acceptsRecipient ? $expectedMessages : 0) ? 0 : 3);
