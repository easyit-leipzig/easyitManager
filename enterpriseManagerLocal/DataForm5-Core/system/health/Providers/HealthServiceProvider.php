<?php
declare(strict_types=1);
namespace DataForm5\Health\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;use DataForm5\Health\Checks\DirectoryWritableCheck;use DataForm5\Health\Core\{DiagnosticReport,HealthManager,SystemInfo};
final class HealthServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{
  $c->singleton(SystemInfo::class,fn()=>new SystemInfo());
  $c->singleton(HealthManager::class,function(ServiceContainer $c):HealthManager{$m=new HealthManager();$cfg=$c->get(Config::class);$path=$c->get(Path::class);$storage=(string)$cfg->get('health.storage_path','storage');if(!str_starts_with($storage,'/'))$storage=$path->base($storage);if(!is_dir($storage))@mkdir($storage,0775,true);$m->register(new DirectoryWritableCheck('storage.writable',$storage),true,false);return $m;});
  $c->singleton(DiagnosticReport::class,fn(ServiceContainer $c)=>new DiagnosticReport($c->get(HealthManager::class),$c->get(SystemInfo::class)));
 }
 public function boot(ServiceContainer $container):void{}
}
