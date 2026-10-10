<?php
declare(strict_types=1);
namespace DataForm5\Packages\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Filesystem\Filesystem;use DataForm5\Core\Support\Path;use DataForm5\Packages\Core\PackageIntegrity;use DataForm5\Packages\Core\PackageManager;use DataForm5\Packages\Core\PackageRegistry;
final class PackageServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$c->singleton(PackageRegistry::class,static fn(ServiceContainer $c)=>new PackageRegistry($c->get(Path::class)->base((string)$c->get(Config::class)->get('packages.registry','storage/packages/installed.json')),$c->get(Filesystem::class)));$c->singleton(PackageIntegrity::class,static fn(ServiceContainer $c)=>new PackageIntegrity($c->get(Filesystem::class)));$c->singleton(PackageManager::class,static fn(ServiceContainer $c)=>new PackageManager($c->get(Path::class)->base((string)$c->get(Config::class)->get('packages.install_path','storage/packages/installed')),$c->get(Filesystem::class),$c->get(PackageRegistry::class),$c->get(PackageIntegrity::class),$c));}
 public function boot(ServiceContainer $container):void{}
}
