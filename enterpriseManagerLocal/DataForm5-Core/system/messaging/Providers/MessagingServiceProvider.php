<?php
declare(strict_types=1);
namespace DataForm5\Messaging\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Messaging\Core\{EventBus,MessageBus};
final class MessagingServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void { $c->singleton(MessageBus::class,static fn(ServiceContainer $c)=>new MessageBus($c));$c->singleton(EventBus::class,static fn(ServiceContainer $c)=>new EventBus($c)); }
 public function boot(ServiceContainer $c):void {}
}
