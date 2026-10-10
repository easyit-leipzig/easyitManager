<?php
declare(strict_types=1);
namespace DataForm5\UI\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\UI\Core\UiManager;
final class UiServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void{$container->singleton(UiManager::class,fn()=>new UiManager());}
    public function boot(ServiceContainer $container): void{}
}
