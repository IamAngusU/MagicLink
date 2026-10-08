<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Database;

$root = dirname(__DIR__);
require $root . '/autoload.php';

try {
    $config = Config::load($root);
    $database = Database::connect($config);
    $database->migrate();
    echo "MagicLink database schema is current.\n";
} catch (Throwable $error) {
    fwrite(STDERR, '[migrate] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
