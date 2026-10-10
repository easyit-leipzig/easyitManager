<?php
declare(strict_types=1);

namespace DataForm5\ORM\Providers;

use DataForm\Database\Core\DatabaseManager;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\ORM\Core\OrmManager;

final class OrmServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(OrmManager::class, static fn(ServiceContainer $container): OrmManager =>
            new OrmManager($container->get(DatabaseManager::class))
        );
    }

    public function boot(ServiceContainer $container): void
    {
    }
}
