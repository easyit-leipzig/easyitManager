<?php
declare(strict_types=1);

namespace DataForm5\Core\Providers;

use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Filesystem\BackupManager;
use DataForm5\Core\Filesystem\Filesystem;
use DataForm5\Core\Filesystem\Storage;
use DataForm5\Core\Filesystem\ZipManager;
use DataForm5\Core\Support\Path;

final class FilesystemServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(Filesystem::class, static fn(): Filesystem => new Filesystem());
        $container->singleton(ZipManager::class, static fn(): ZipManager => new ZipManager());
        $container->singleton(Storage::class, static function (ServiceContainer $c): Storage {
            $path = $c->get(Path::class);
            $config = $c->get(Config::class);
            return new Storage($path->base((string)$config->get('filesystem.disks.local.root', 'storage/app')), $c->get(Filesystem::class));
        });
        $container->singleton(BackupManager::class, static function (ServiceContainer $c): BackupManager {
            $path = $c->get(Path::class);
            $config = $c->get(Config::class);
            return new BackupManager(
                $c->get(Filesystem::class),
                $c->get(ZipManager::class),
                $path->base((string)$config->get('filesystem.backup_path', 'storage/backups'))
            );
        });
    }

    public function boot(ServiceContainer $container): void {}
}
