<?php
declare(strict_types=1);
namespace DataForm5\Health\Core;
use DataForm5\Health\Contracts\HealthCheckInterface;
final class HealthManager
{
    /** @var array<string,array{check:HealthCheckInterface,readiness:bool,liveness:bool}> */ private array $checks=[];
    public function register(HealthCheckInterface $check,bool $readiness=true,bool $liveness=false):self{$this->checks[$check->name()]=compact('check','readiness','liveness');return $this;}
    public function report(string $type='all'):HealthReport
    {
        $results=[];foreach($this->checks as $item){if($type==='readiness'&&!$item['readiness'])continue;if($type==='liveness'&&!$item['liveness'])continue;$results[]=$item['check']->run();}
        $status=HealthStatus::UP;foreach($results as $r){if($r->status===HealthStatus::DOWN){$status=HealthStatus::DOWN;break;}if($r->status===HealthStatus::DEGRADED)$status=HealthStatus::DEGRADED;}
        return new HealthReport($status,$results,gmdate(DATE_ATOM));
    }
    public function readiness():HealthReport{return $this->report('readiness');}
    public function liveness():HealthReport{return $this->report('liveness');}
}
