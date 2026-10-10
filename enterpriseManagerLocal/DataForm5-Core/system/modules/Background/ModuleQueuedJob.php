<?php
declare(strict_types=1);

namespace DataForm5\Modules\Background;

use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Events\Core\NamedEvent;
use DataForm5\Queue\Core\AbstractJob;

final class ModuleQueuedJob extends AbstractJob
{
    public function handle(ServiceContainer $container): void
    {
        $definition=(array)($this->data['definition']??[]);
        $payload=(array)($this->data['payload']??[]);
        $name=(string)($definition['name']??'module.job');
        $module=(string)($definition['module']??'unknown');
        $modulePath=(string)($definition['module_path']??'');
        $handler=(string)($definition['handler']??'');
        $logger=$container->get(ModuleBackgroundLogger::class);

        if($handler==='') throw new \RuntimeException("Kein Handler für Modul-Job '{$name}' konfiguriert.");
        $bootstrap=rtrim($modulePath,'/\\').'/bootstrap.php';
        if($modulePath!=='' && is_file($bootstrap)) require_once $bootstrap;
        if(!class_exists($handler)) throw new \RuntimeException("Job-Handler '{$handler}' wurde nicht gefunden.");

        $events=$container->get(EventDispatcherInterface::class);
        $eventId=bin2hex(random_bytes(12));
        $events->dispatch(new NamedEvent('module.job.started',[
            'job'=>$name,'module'=>$module,'event_id'=>$eventId,
        ]),'module.job.started');

        $start=microtime(true);
        try{
            $object=$container->build($handler);
            if(is_callable($object)){
                $container->call($object,['payload'=>$payload,'module'=>$module,'modulePath'=>$modulePath]);
            }elseif(method_exists($object,'handle')){
                $container->call([$object,'handle'],['payload'=>$payload,'module'=>$module,'modulePath'=>$modulePath]);
            }else{
                throw new \RuntimeException("Job-Handler '{$handler}' ist weder aufrufbar noch besitzt er handle().");
            }
            $duration=(microtime(true)-$start)*1000;
            $logger->record('success',$name,$module,['duration_ms'=>round($duration,3),'event_id'=>$eventId]);
            $events->dispatch(new NamedEvent('module.job.finished',[
                'job'=>$name,'module'=>$module,'event_id'=>$eventId,'duration_ms'=>$duration,
            ]),'module.job.finished');
        }catch(\Throwable $e){
            $duration=(microtime(true)-$start)*1000;
            $logger->record('failed',$name,$module,[
                'duration_ms'=>round($duration,3),'event_id'=>$eventId,
                'error_class'=>$e::class,'error'=>$e->getMessage(),
            ]);
            $events->dispatch(new NamedEvent('module.job.failed',[
                'job'=>$name,'module'=>$module,'event_id'=>$eventId,'error_class'=>$e::class,
            ]),'module.job.failed');
            throw $e;
        }
    }

    public function maxAttempts(): int
    {
        return max(1,(int)(($this->data['definition']['max_attempts']??3)));
    }

    public function retryDelay(): int
    {
        return max(0,(int)(($this->data['definition']['retry_delay']??5)));
    }
}
