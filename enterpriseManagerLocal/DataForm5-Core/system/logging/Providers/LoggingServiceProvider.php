<?php
declare(strict_types=1);
namespace DataForm5\Logging\Providers;
use DataForm5\Core\Config; use DataForm5\Core\Container\ServiceContainer; use DataForm5\Core\Contracts\ServiceProviderInterface; use DataForm5\Core\Support\Path; use DataForm5\Logging\Contracts\LoggerInterface; use DataForm5\Logging\Core\LogManager;
final class LoggingServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void { $c->singleton(LogManager::class,static fn(ServiceContainer $c):LogManager=>new LogManager($c->get(Config::class),$c->get(Path::class))); $c->singleton(LoggerInterface::class,static fn(ServiceContainer $c):LoggerInterface=>$c->get(LogManager::class)->channel()); }
 public function boot(ServiceContainer $container):void {}
}
