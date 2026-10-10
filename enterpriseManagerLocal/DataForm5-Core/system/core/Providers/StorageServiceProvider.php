<?php
declare(strict_types=1);

namespace DataForm5\Core\Providers;

use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Filesystem\{Filesystem,StorageManager};
use DataForm5\Core\Kernel;

final class StorageServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(StorageManager::class,function(ServiceContainer $c): StorageManager {
            return new StorageManager(
                (array)$c->get(Config::class)->get('storage',[]),
                $c->get(Kernel::class)->basePath(),
                $c->get(Filesystem::class)
            );
        });
    }

    public function boot(ServiceContainer $container): void {}
}
