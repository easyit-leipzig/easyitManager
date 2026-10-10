<?php
declare(strict_types=1);
namespace DataForm5\Features\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Features\Contracts\FeatureManagerInterface;use DataForm5\Features\Core\FeatureManager;
final class FeatureServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$c->singleton(FeatureManager::class,static fn(ServiceContainer $c)=>new FeatureManager((array)$c->get(Config::class)->get('features.flags',[]),(array)$c->get(Config::class)->get('features.defaults',[])));$c->singleton(FeatureManagerInterface::class,static fn(ServiceContainer $c)=>$c->get(FeatureManager::class));}
 public function boot(ServiceContainer $c):void{}
}
