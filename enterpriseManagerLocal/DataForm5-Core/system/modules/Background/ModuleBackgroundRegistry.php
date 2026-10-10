<?php
declare(strict_types=1);

namespace DataForm5\Modules\Background;

use DataForm5\Modules\Core\ModuleManager;
use DataForm5\Queue\Core\QueueDispatcher;
use DataForm5\Scheduler\Core\Scheduler;

final class ModuleBackgroundRegistry
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly QueueDispatcher $queue,
        private readonly ModuleBackgroundLogger $logger
    ) {}

    /** @return array<string,array<string,mixed>> */
    public function jobs(): array
    {
        $result=[];
        foreach($this->modules->discovered() as $module=>$item){
            $manifest=$item['manifest'];
            if(!$manifest->enabled) continue;
            foreach($manifest->jobs as $key=>$definition){
                if(is_string($definition)){
                    $name=is_string($key)?$key:$module.'.job.'.count($result);
                    $definition=['handler'=>$definition];
                }
                if(!is_array($definition)) continue;
                $name=trim((string)($definition['name']??(is_string($key)?$key:'')));
                $handler=trim((string)($definition['handler']??''));
                if($name===''||$handler==='') continue;
                if(isset($result[$name])) throw new \RuntimeException("Doppelter Modul-Job '{$name}'.");
                $result[$name]=[
                    'name'=>$name,
                    'module'=>$module,
                    'module_path'=>$item['path'],
                    'handler'=>$handler,
                    'queue'=>(string)($definition['queue']??''),
                    'max_attempts'=>max(1,(int)($definition['max_attempts']??3)),
                    'retry_delay'=>max(0,(int)($definition['retry_delay']??5)),
                ];
            }
        }
        ksort($result);
        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    public function schedules(): array
    {
        $result=[];
        foreach($this->modules->discovered() as $module=>$item){
            $manifest=$item['manifest'];
            if(!$manifest->enabled) continue;
            foreach($manifest->schedules as $key=>$definition){
                if(!is_array($definition)) continue;
                $name=trim((string)($definition['name']??(is_string($key)?$key:'')));
                $job=trim((string)($definition['job']??''));
                $cron=trim((string)($definition['cron']??''));
                if($name===''||$job===''||$cron==='') continue;
                if(isset($result[$name])) throw new \RuntimeException("Doppelter Modul-Zeitplan '{$name}'.");
                $result[$name]=[
                    'name'=>$name,
                    'module'=>$module,
                    'job'=>$job,
                    'cron'=>$cron,
                    'payload'=>is_array($definition['payload']??null)?$definition['payload']:[],
                    'queue'=>(string)($definition['queue']??''),
                    'without_overlapping'=>(bool)($definition['without_overlapping']??true),
                    'lock_ttl'=>max(1,(int)($definition['lock_ttl']??3600)),
                ];
            }
        }
        ksort($result);
        return $result;
    }

    public function dispatch(string $jobName,array $payload=[],int $delay=0,?string $connection=null): string
    {
        $definition=$this->jobs()[$jobName]??null;
        if($definition===null) throw new \RuntimeException("Modul-Job '{$jobName}' wurde nicht gefunden.");
        $queueConnection=$connection;
        if($queueConnection===null && $definition['queue']!=='') $queueConnection=$definition['queue'];

        $job=new ModuleQueuedJob([
            'definition'=>$definition,
            'payload'=>$payload,
        ]);
        $id=$this->queue->dispatch($job,max(0,$delay),$queueConnection);
        $this->logger->record('dispatched',$jobName,(string)$definition['module'],[
            'queue'=>$queueConnection,
            'delay'=>max(0,$delay),
            'job_id'=>$id,
        ]);
        return $id;
    }

    public function registerSchedules(Scheduler $scheduler): int
    {
        $count=0;
        foreach($this->schedules() as $definition){
            if(!isset($this->jobs()[$definition['job']])) {
                throw new \RuntimeException("Zeitplan '{$definition['name']}' verweist auf unbekannten Job '{$definition['job']}'.");
            }
            $task=$scheduler->task(
                'module:'.$definition['name'],
                $definition['cron'],
                function() use ($definition): void {
                    $queue=$definition['queue']!==''?$definition['queue']:null;
                    $this->dispatch($definition['job'],$definition['payload'],0,$queue);
                }
            );
            if($definition['without_overlapping']) $task->withoutOverlapping($definition['lock_ttl']);
            $count++;
        }
        return $count;
    }
}
