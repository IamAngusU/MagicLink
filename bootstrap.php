<?php
declare(strict_types=1);

use IamAngusU\MagicLink\Kernel;
use IamAngusU\MagicLink\Session;

$root = __DIR__;
require $root . '/autoload.php';

$kernel = Kernel::boot($root);
Session::start($kernel->config);

return $kernel->app();
