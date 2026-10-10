<?php
declare(strict_types=1);
namespace DataForm5\Audit\Providers;
use DataForm5\Audit\Contracts\AuditStoreInterface;use DataForm5\Audit\Core\AuditManager;use DataForm5\Audit\Stores\{FileAuditStore,NullAuditStore};use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;
final class AuditServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{
  $c->singleton(AuditStoreInterface::class,function(ServiceContainer $c):AuditStoreInterface{$cfg=$c->get(Config::class);$driver=(string)$cfg->get('audit.driver','file');if($driver==='null')return new NullAuditStore();$file=(string)$cfg->get('audit.file','storage/logs/audit.jsonl');if(!str_starts_with($file,'/')&&!preg_match('/^[A-Za-z]:[\\\\\/]/',$file))$file=$c->get(Path::class)->base($file);return new FileAuditStore($file,(string)$cfg->get('audit.key',''));});
  $c->singleton(AuditManager::class,fn(ServiceContainer $c)=>new AuditManager($c->get(AuditStoreInterface::class)));
 }
 public function boot(ServiceContainer $container):void{}
}
