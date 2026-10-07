<?php
declare(strict_types=1);

use IamAngusU\MagicLink\App;
use IamAngusU\MagicLink\Config;
use IamAngusU\MagicLink\Crypto;
use IamAngusU\MagicLink\Database;
use IamAngusU\MagicLink\Mail\FileMailer;
use IamAngusU\MagicLink\Mail\NativeMailer;
use IamAngusU\MagicLink\Mail\SmtpMailer;
use IamAngusU\MagicLink\MagicLinkService;
use IamAngusU\MagicLink\Session;
use IamAngusU\MagicLink\StateCatalog;

$root = __DIR__;

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'IamAngusU\\MagicLink\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = $root . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$config = Config::load($root);
Session::start($config);
$database = Database::connect($config);
$database->migrate();
$crypto = new Crypto($config->appKey());
$mailer = match ($config->string('MAIL_TRANSPORT')) {
    'log' => new FileMailer($config),
    'smtp' => new SmtpMailer($config),
    default => new NativeMailer($config),
};
$service = new MagicLinkService($database->pdo(), $config, $crypto, $mailer);

return new App($config, $service, $crypto, new StateCatalog($root, $config->locale()));
