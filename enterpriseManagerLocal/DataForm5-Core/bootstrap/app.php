<?php
declare(strict_types=1);

require __DIR__ . '/autoload.php';

use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Kernel;

$kernel = new Kernel(dirname(__DIR__));
$providers = require dirname(__DIR__) . '/config/providers.php';

foreach ($providers as $providerClass) {
    if (!is_string($providerClass) || !class_exists($providerClass)) {
        throw new RuntimeException('ServiceProvider konnte nicht geladen werden: ' . (string)$providerClass);
    }

    $provider = new $providerClass();
    if (!$provider instanceof ServiceProviderInterface) {
        throw new RuntimeException($providerClass . ' implementiert ServiceProviderInterface nicht.');
    }

    $kernel->register($provider);
}

return $kernel->boot();
