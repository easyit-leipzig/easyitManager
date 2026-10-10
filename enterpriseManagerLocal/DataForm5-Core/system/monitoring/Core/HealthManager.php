<?php
declare(strict_types=1);
namespace DataForm5\Monitoring\Core;
final class HealthManager
{
    public function __construct(private readonly MetricsCollector $collector,private readonly MetricsStorage $storage,private readonly WorkerHeartbeat $heartbeat,private readonly AlertManager $alerts){}
    public function snapshot(bool $persist=true): array
    {
        $metrics=$this->collector->collect(); if($persist)$this->storage->store($metrics);
        $heartbeats=$this->heartbeat->all(); $alerts=$this->alerts->evaluate($metrics,$heartbeats);
        $status='healthy'; foreach($alerts as $a){if(($a['level']??'')==='critical'){$status='critical';break;} $status='warning';}
        return ['status'=>$status,'generated_at'=>date(DATE_ATOM),'metrics'=>$metrics,'heartbeats'=>$heartbeats,'alerts'=>$alerts];
    }
}
