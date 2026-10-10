<?php
declare(strict_types=1);
namespace DataForm5\Health\Core;
final readonly class HealthReport
{
    /** @param list<HealthResult> $checks */
    public function __construct(public HealthStatus $status,public array $checks,public string $generatedAt){}
    public function isHealthy():bool{return $this->status!==HealthStatus::DOWN;}
    public function toArray():array{return ['status'=>$this->status->value,'healthy'=>$this->isHealthy(),'generated_at'=>$this->generatedAt,'checks'=>array_map(static fn(HealthResult $r)=>$r->toArray(),$this->checks)];}
}
