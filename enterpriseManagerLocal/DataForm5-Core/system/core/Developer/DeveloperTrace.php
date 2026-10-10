<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

final class DeveloperTrace
{
    private float $startedAt;
    private int $startedMemory;
    private array $events=[];

    public function __construct(private readonly int $maxEvents=100)
    {
        $this->startedAt=$_SERVER['REQUEST_TIME_FLOAT']??microtime(true);
        $this->startedMemory=memory_get_usage(true);
    }

    public function event(string $name,array $context=[]): void
    {
        $this->events[]=[
            'name'=>$name,
            'time'=>microtime(true),
            'offset_ms'=>round((microtime(true)-$this->startedAt)*1000,3),
            'context'=>$this->sanitize($context),
        ];
        if(count($this->events)>max(1,$this->maxEvents)){
            $this->events=array_slice($this->events,-$this->maxEvents);
        }
    }

    public function snapshot(): array
    {
        return [
            'runtime_ms'=>round((microtime(true)-$this->startedAt)*1000,3),
            'memory_bytes'=>memory_get_usage(true),
            'memory_peak_bytes'=>memory_get_peak_usage(true),
            'memory_delta_bytes'=>memory_get_usage(true)-$this->startedMemory,
            'php_version'=>PHP_VERSION,
            'sapi'=>PHP_SAPI,
            'events'=>$this->events,
        ];
    }

    private function sanitize(array $context): array
    {
        $result=[];
        foreach($context as $key=>$value){
            $keyString=strtolower((string)$key);
            if(preg_match('/password|secret|token|session|cookie|authorization|api[_-]?key/',$keyString)){
                $result[$key]='[REDACTED]';
                continue;
            }
            if(is_scalar($value)||$value===null){
                $result[$key]=$value;
            }elseif(is_array($value)){
                $result[$key]='[array '.count($value).']';
            }else{
                $result[$key]='['.get_debug_type($value).']';
            }
        }
        return $result;
    }
}
