<?php
declare(strict_types=1);
namespace DataForm5\Deployment\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Deployment\Contracts\ReleaseManagerInterface;use DataForm5\Deployment\Core\{MaintenanceMode,ReleaseManager};
final class DeploymentServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$base=dirname(__DIR__,3);$c->singleton(MaintenanceMode::class,fn()=>new MaintenanceMode($base.'/storage/framework/maintenance.json'));$c->singleton(ReleaseManager::class,fn(ServiceContainer $c)=>new ReleaseManager($base,$base.'/storage/releases',$c->get(MaintenanceMode::class)));$c->singleton(ReleaseManagerInterface::class,fn(ServiceContainer $c)=>$c->get(ReleaseManager::class));}
 public function boot(ServiceContainer $c):void{}
}
