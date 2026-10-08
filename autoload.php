<?php
declare(strict_types=1);

$magicLinkRoot = __DIR__;

spl_autoload_register(static function (string $class) use ($magicLinkRoot): void {
    $prefix = 'IamAngusU\\MagicLink\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $magicLinkRoot . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

