<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Kernel;
use IamAngusU\MagicLink\Session;

$root = __DIR__;
require $root . '/autoload.php';

$kernel = Kernel::boot($root);
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$healthPath = rtrim($kernel->config->basePath(), '/') . '/health';
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET' || $requestPath !== $healthPath) {
    Session::start($kernel->config);
}

return $kernel->app();
