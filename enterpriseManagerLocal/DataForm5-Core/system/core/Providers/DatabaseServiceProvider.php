<?php
declare(strict_types=1);

namespace DataForm5\Core\Providers;

use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm\Database\Core\DatabaseManager;
use DataForm\Database\Core\MigrationManager;
use DataForm\Database\Core\RelationManager;
use DataForm\Database\Core\SchemaBuilder;

final class DatabaseServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(DatabaseManager::class, static function (ServiceContainer $container): DatabaseManager {
            /** @var Config $config */
            $config = $container->get(Config::class);
            $connections = $config->get('database.connections', []);

            if (!is_array($connections)) {
                throw new \RuntimeException('database.connections muss ein Array sein.');
            }

            return new DatabaseManager(
                configurations: $connections,
                defaultConnection: (string)$config->get('database.default', 'default')
            );
        });

        $container->singleton(SchemaBuilder::class, static fn(ServiceContainer $container): SchemaBuilder =>
            new SchemaBuilder($container->get(DatabaseManager::class)->connection())
        );

        $container->singleton(RelationManager::class, static fn(ServiceContainer $container): RelationManager =>
            new RelationManager($container->get(DatabaseManager::class)->connection())
        );

        $container->singleton(MigrationManager::class, static fn(ServiceContainer $container): MigrationManager =>
            new MigrationManager($container->get(DatabaseManager::class)->connection())
        );
    }

    public function boot(ServiceContainer $container): void
    {
        // Verbindungen werden absichtlich erst bei der ersten Verwendung aufgebaut.
    }
}
