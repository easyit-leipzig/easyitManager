<?php
declare(strict_types=1);
namespace DataForm5\Rules\Providers;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Rules\Core\RuleEngine;
final class RuleServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void{$container->singleton(RuleEngine::class,fn()=>new RuleEngine());}
    public function boot(ServiceContainer $container): void{}
}
