<?php
declare(strict_types=1);
namespace DataForm5\Modules\Lifecycle;
final class ModuleLifecycleLogger
{
    public function __construct(private readonly string $logPath) {}
    public function log(ModuleLifecycleEvent $event, string $status, float $durationMs=0.0, ?string $error=null): void
    {
        $dir=rtrim($this->logPath,'/\\'); if(!is_dir($dir)) @mkdir($dir,0775,true);
        $safe=preg_replace('/[^a-z0-9_-]+/i','-',strtolower($event->hook))?:'lifecycle';
        $row=['event_id'=>$event->eventId,'module'=>$event->module,'hook'=>$event->hook,'phase'=>$event->phase,'status'=>$status,'duration_ms'=>round($durationMs,3),'occurred_at'=>$event->occurredAt,'context'=>$event->context];
        if($error!==null)$row['error']=$error;
        @file_put_contents($dir.'/'.$safe.'.log',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n",FILE_APPEND|LOCK_EX);
    }
}
