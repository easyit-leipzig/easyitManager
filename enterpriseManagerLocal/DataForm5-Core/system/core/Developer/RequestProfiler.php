<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

final class RequestProfiler
{
    private float $startedAt;
    private int $startedMemory;
    private array $records=[];
    private array $spans=[];
    private array $categories=[
        'php'=>[],
        'sql'=>[],
        'cache'=>[],
        'filesystem'=>[],
        'http'=>[],
        'api'=>[],
        'modules'=>[],
        'queue'=>[],
        'scheduler'=>[],
    ];

    public function __construct(private readonly bool $enabled=false,private readonly int $maxRecords=500)
    {
        $this->startedAt=$_SERVER['REQUEST_TIME_FLOAT']??microtime(true);
        $this->startedMemory=memory_get_usage(true);
        ProfilerHub::install($this);
    }

    public function enabled(): bool { return $this->enabled; }

    public function start(string $category,string $operation,array $context=[]): ?string
    {
        if(!$this->enabled) return null;
        $token=bin2hex(random_bytes(8));
        $this->spans[$token]=[
            'category'=>$this->normalizeCategory($category),
            'operation'=>$operation,
            'started'=>microtime(true),
            'memory'=>memory_get_usage(true),
            'context'=>$this->sanitize($context),
        ];
        return $token;
    }

    public function stop(string $token,array $context=[]): void
    {
        if(!$this->enabled||!isset($this->spans[$token])) return;
        $span=$this->spans[$token];
        unset($this->spans[$token]);
        $this->record(
            (string)$span['category'],
            (string)$span['operation'],
            (microtime(true)-(float)$span['started'])*1000,
            array_merge((array)$span['context'],$context,[
                'memory_delta'=>memory_get_usage(true)-(int)$span['memory'],
            ])
        );
    }

    public function record(string $category,string $operation,float $durationMs=0.0,array $context=[]): void
    {
        if(!$this->enabled) return;
        $category=$this->normalizeCategory($category);
        $row=[
            'category'=>$category,
            'operation'=>$operation,
            'duration_ms'=>round(max(0,$durationMs),3),
            'offset_ms'=>round((microtime(true)-$this->startedAt)*1000,3),
            'memory_bytes'=>memory_get_usage(true),
            'context'=>$this->sanitize($context),
        ];
        $this->records[]=$row;
        $this->categories[$category][]=$row;
        if(count($this->records)>max(1,$this->maxRecords)){
            $this->records=array_slice($this->records,-$this->maxRecords);
            $this->rebuildCategories();
        }
    }

    public function snapshot(): array
    {
        $summary=[];
        foreach($this->categories as $category=>$rows){
            $summary[$category]=[
                'count'=>count($rows),
                'duration_ms'=>round(array_sum(array_column($rows,'duration_ms')),3),
                'max_ms'=>$rows===[]?0.0:max(array_column($rows,'duration_ms')),
            ];
        }
        return [
            'enabled'=>$this->enabled,
            'runtime_ms'=>round((microtime(true)-$this->startedAt)*1000,3),
            'memory_current'=>memory_get_usage(true),
            'memory_peak'=>memory_get_peak_usage(true),
            'memory_delta'=>memory_get_usage(true)-$this->startedMemory,
            'summary'=>$summary,
            'records'=>$this->records,
            'open_spans'=>count($this->spans),
        ];
    }

    private function normalizeCategory(string $category): string
    {
        return array_key_exists($category,$this->categories)?$category:'php';
    }

    private function sanitize(array $context): array
    {
        $result=[];
        foreach($context as $key=>$value){
            $k=strtolower((string)$key);
            if(preg_match('/password|secret|token|cookie|authorization|session|api[_-]?key|dsn/',$k)){
                $result[$key]='[REDACTED]';
            }elseif(is_scalar($value)||$value===null){
                $result[$key]=$value;
            }elseif(is_array($value)){
                $result[$key]='[array '.count($value).']';
            }else{
                $result[$key]='['.get_debug_type($value).']';
            }
        }
        return $result;
    }

    private function rebuildCategories(): void
    {
        foreach($this->categories as $category=>$_) $this->categories[$category]=[];
        foreach($this->records as $row) $this->categories[$row['category']][]=$row;
    }
}
