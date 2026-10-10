<?php
declare(strict_types=1);
namespace DataForm5\Modules\Lifecycle;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Modules\Core\ModuleManifest;
final class ModuleLifecycleManager
{
    public function __construct(
        private readonly ServiceContainer $container,
        private readonly EventDispatcherInterface $events,
        private readonly ModuleLifecycleLogger $logger
    ) {}

    public function run(string $module,string $modulePath,string $hook,array $context=[]): mixed
    {
        $manifestFile=rtrim($modulePath,'/\\').'/module.json';
        if(!is_file($manifestFile)) return null;
        $manifest=ModuleManifest::fromFile($manifestFile);
        $handler=trim((string)($manifest->lifecycle[$hook]??''));
        $eventId=bin2hex(random_bytes(12));
        $started=new ModuleLifecycleEvent($module,$hook,'started',$context,$eventId);
        $this->events->dispatch($started,'module.'.$hook.'.started');
        $start=microtime(true);
        try{
            $result=null;
            if($handler!==''){
                if(!class_exists($handler)){
                    $bootstrap=rtrim($modulePath,'/\\').'/bootstrap.php'; if(is_file($bootstrap)) require_once $bootstrap;
                }
                if(!class_exists($handler)) throw new \RuntimeException("Lifecycle-Handler '{$handler}' wurde nicht gefunden.");
                $object=$this->container->build($handler);
                if(is_callable($object)) $result=$this->container->call($object,['module'=>$module,'modulePath'=>$modulePath,'hook'=>$hook,'context'=>$context]);
                elseif(method_exists($object,'handle')) $result=$this->container->call([$object,'handle'],['module'=>$module,'modulePath'=>$modulePath,'hook'=>$hook,'context'=>$context]);
                else throw new \RuntimeException("Lifecycle-Handler '{$handler}' ist weder aufrufbar noch besitzt er handle().");
            }
            $duration=(microtime(true)-$start)*1000;
            $finished=new ModuleLifecycleEvent($module,$hook,'finished',$context,$eventId);
            $this->events->dispatch($finished,'module.'.$hook.'.finished');
            $this->logger->log($finished,'success',$duration);
            return $result;
        }catch(\Throwable $e){
            $duration=(microtime(true)-$start)*1000;
            $failed=new ModuleLifecycleEvent($module,$hook,'failed',$context,$eventId);
            $this->events->dispatch($failed,'module.'.$hook.'.failed');
            $this->logger->log($failed,'failed',$duration,$e->getMessage());
            throw $e;
        }
    }
}
