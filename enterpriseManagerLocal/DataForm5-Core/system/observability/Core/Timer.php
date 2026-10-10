<?php
declare(strict_types=1);
namespace DataForm5\Observability\Core;
final class Timer
{
    private float $startedAt;private bool $stopped=false;
    public function __construct(private readonly MetricsManager $metrics,private readonly string $name,private readonly array $tags=[]){$this->startedAt=microtime(true);}
    public function stop():float{if($this->stopped)return 0.0;$this->stopped=true;$duration=(microtime(true)-$this->startedAt)*1000;$this->metrics->observe($this->name,$duration,$this->tags);return $duration;}
    public function __destruct(){if(!$this->stopped)$this->stop();}
}
