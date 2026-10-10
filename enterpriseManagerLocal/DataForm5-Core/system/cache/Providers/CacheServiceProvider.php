<?php
declare(strict_types=1);
namespace DataForm5\Cache\Providers;
use DataForm5\Cache\Contracts\CacheInterface;
use DataForm5\Cache\Core\CacheManager;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Support\Path;
final class CacheServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void { $c->singleton(CacheManager::class,static fn(ServiceContainer $c):CacheManager=>new CacheManager($c->get(Config::class),$c->get(Path::class))); $c->singleton(CacheInterface::class,static fn(ServiceContainer $c):CacheInterface=>$c->get(CacheManager::class)->store()); }
    public function boot(ServiceContainer $container):void {}
}
