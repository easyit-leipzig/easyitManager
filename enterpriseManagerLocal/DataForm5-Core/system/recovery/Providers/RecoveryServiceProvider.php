<?php
declare(strict_types=1);
namespace DataForm5\Recovery\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;use DataForm5\Recovery\Contracts\BackupStoreInterface;use DataForm5\Recovery\Core\{FileBackupStore,RecoveryManager};
final class RecoveryServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c): void {$c->singleton(BackupStoreInterface::class,static fn(ServiceContainer $c)=>new FileBackupStore($c->get(Path::class)->base((string)$c->get(Config::class)->get('recovery.path','storage/recovery'))));$c->singleton(RecoveryManager::class,static fn(ServiceContainer $c)=>new RecoveryManager($c->get(BackupStoreInterface::class),(int)$c->get(Config::class)->get('recovery.retain',10)));}
 public function boot(ServiceContainer $container): void {}
}
