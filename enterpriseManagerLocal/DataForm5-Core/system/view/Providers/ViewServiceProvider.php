<?php
declare(strict_types=1);
namespace DataForm5\View\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Kernel;use DataForm5\View\Contracts\ViewFactoryInterface;use DataForm5\View\Core\{AssetManager,ThemeManager,ViewFactory};
final class ViewServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void
 {
  $c->singleton(ThemeManager::class,fn(ServiceContainer $c)=>new ThemeManager($c->get(Kernel::class)->basePath().'/resources/themes','default'));
  $c->singleton(AssetManager::class,fn(ServiceContainer $c)=>new AssetManager($c->get(ThemeManager::class),''));
  $c->singleton(ViewFactory::class,fn(ServiceContainer $c)=>new ViewFactory($c->get(Kernel::class)->basePath().'/resources/views',$c->get(ThemeManager::class),$c->get(AssetManager::class)));
  $c->singleton(ViewFactoryInterface::class,fn(ServiceContainer $c)=>$c->get(ViewFactory::class));
 }
 public function boot(ServiceContainer $c):void{}
}
