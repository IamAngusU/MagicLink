<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = false;
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname());
    exec($command, $output, $status);
    if ($status !== 0) {
        $failed = true;
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
    $output = [];
}
if ($failed) exit(1);

$version = trim((string) file_get_contents($root . '/VERSION'));
$changelog = (string) file_get_contents($root . '/CHANGELOG.md');
if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version)
    || !preg_match('/^##\s+([0-9]+\.[0-9]+\.[0-9]+)/m', $changelog, $match)
    || !hash_equals($version, $match[1])
) {
    fwrite(STDERR, "VERSION must be semantic and match the newest CHANGELOG entry.\n");
    exit(1);
}

$tests = [
    'tests/platform-hardening.php',
    'tests/mail-templates.php',
    'tests/smtp-transport.php',
];
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $tests[] = 'tests/mail-worker-hardening.php';
}
$tests[] = 'tests/run.php';
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $tests[] = 'tests/handoff-security.php';
}

ob_start();
foreach ($tests as $test) {
    (static function (string $path): void {
        require $path;
    })($root . '/' . $test);
    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
        throw new RuntimeException('A test session could not be persisted.');
    }
    $_SESSION = [];
    session_id('');
}
ob_end_flush();
