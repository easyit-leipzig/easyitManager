<?php
declare(strict_types=1);
namespace DataForm5\Health\Core;
final readonly class HealthResult
{
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public string $message='OK',
        public array $details=[],
        public float $durationMs=0.0,
    ) {}
    public function toArray():array{return ['name'=>$this->name,'status'=>$this->status->value,'message'=>$this->message,'details'=>$this->details,'duration_ms'=>round($this->durationMs,3)];}
}
