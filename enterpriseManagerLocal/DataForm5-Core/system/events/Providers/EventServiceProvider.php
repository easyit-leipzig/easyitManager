<?php
declare(strict_types=1);
namespace DataForm5\Events\Providers;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Events\Core\EventDispatcher;
final class EventServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(EventDispatcher::class, static fn(): EventDispatcher => new EventDispatcher());
        $container->singleton(EventDispatcherInterface::class, static fn(ServiceContainer $c): EventDispatcher => $c->get(EventDispatcher::class));
    }
    public function boot(ServiceContainer $container): void {}
}
