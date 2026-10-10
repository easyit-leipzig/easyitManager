<?php
declare(strict_types=1);
namespace DataForm5\Testing\Core;
final class TestResult
{
    public function __construct(public readonly string $name, public readonly string $status, public readonly float $durationMs, public readonly ?string $message = null, public readonly ?string $trace = null) {}
    public function passed(): bool { return $this->status === 'passed'; }
    public function toArray(): array { return ['name'=>$this->name,'status'=>$this->status,'duration_ms'=>round($this->durationMs,3),'message'=>$this->message,'trace'=>$this->trace]; }
}
