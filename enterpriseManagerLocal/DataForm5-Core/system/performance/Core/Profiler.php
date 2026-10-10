<?php
declare(strict_types=1);
namespace DataForm5\Performance\Core;
use DataForm5\Performance\Contracts\ProfilerInterface;
use DataForm5\Performance\Exceptions\PerformanceException;
final class Profiler implements ProfilerInterface
{
    private array $active=[];
    private array $measurements=[];
    private array $marks=[];
    public function __construct(private PerformanceThresholds $thresholds, private bool $enabled=true, private int $maxEntries=1000) {}
    public function start(string $name,array $tags=[]):string
    {
        $this->assertName($name);$token=bin2hex(random_bytes(12));
        if(!$this->enabled)return $token;
        $this->active[$token]=['name'=>$name,'started_ns'=>hrtime(true),'started_at'=>gmdate(DATE_ATOM),'memory_bytes'=>memory_get_usage(true),'tags'=>$this->normalize($tags)];
        return $token;
    }
    public function stop(string $token,array $metadata=[]):array
    {
        if(!$this->enabled)return ['enabled'=>false];
        if(!isset($this->active[$token]))throw new PerformanceException('Unbekannter oder bereits beendeter Messpunkt.');
        $entry=$this->active[$token];unset($this->active[$token]);
        $duration=(hrtime(true)-$entry['started_ns'])/1e6;$memoryDelta=memory_get_usage(true)-$entry['memory_bytes'];
        $result=['name'=>$entry['name'],'started_at'=>$entry['started_at'],'finished_at'=>gmdate(DATE_ATOM),'duration_ms'=>round($duration,3),'memory_delta_bytes'=>$memoryDelta,'peak_memory_bytes'=>memory_get_peak_usage(true),'status'=>$this->thresholds->classify($entry['name'],$duration),'threshold_ms'=>$this->thresholds->millisecondsFor($entry['name']),'tags'=>$entry['tags'],'metadata'=>$this->normalize($metadata)];
        $this->append($result);return $result;
    }
    public function measure(string $name,callable $callback,array $tags=[]):mixed
    {
        $token=$this->start($name,$tags);
        try{return $callback();}finally{$this->stop($token);}
    }
    public function mark(string $name,array $metadata=[]):void
    {
        $this->assertName($name);if(!$this->enabled)return;
        $this->marks[]=['name'=>$name,'at'=>gmdate(DATE_ATOM),'elapsed_ms'=>round((microtime(true)-(float)($_SERVER['REQUEST_TIME_FLOAT']??microtime(true)))*1000,3),'memory_bytes'=>memory_get_usage(true),'metadata'=>$this->normalize($metadata)];
        if(count($this->marks)>$this->maxEntries)array_shift($this->marks);
    }
    public function report():array
    {
        $grouped=[];
        foreach($this->measurements as $m){$n=$m['name'];$grouped[$n]??=['count'=>0,'total_ms'=>0.0,'min_ms'=>INF,'max_ms'=>0.0,'warnings'=>0,'critical'=>0];$g=&$grouped[$n];$g['count']++;$g['total_ms']+=$m['duration_ms'];$g['min_ms']=min($g['min_ms'],$m['duration_ms']);$g['max_ms']=max($g['max_ms'],$m['duration_ms']);if($m['status']==='warning')$g['warnings']++;if($m['status']==='critical')$g['critical']++;unset($g);}
        foreach($grouped as &$g){$g['total_ms']=round($g['total_ms'],3);$g['average_ms']=round($g['total_ms']/$g['count'],3);if($g['min_ms']===INF)$g['min_ms']=0.0;}unset($g);
        uasort($grouped,fn($a,$b)=>$b['total_ms']<=>$a['total_ms']);
        return ['enabled'=>$this->enabled,'generated_at'=>gmdate(DATE_ATOM),'active_measurements'=>count($this->active),'measurement_count'=>count($this->measurements),'measurements'=>$this->measurements,'summary'=>$grouped,'marks'=>$this->marks,'bottlenecks'=>array_values(array_filter($this->measurements,fn($m)=>in_array($m['status'],['warning','critical'],true)))];
    }
    public function reset():void{$this->active=[];$this->measurements=[];$this->marks=[];}
    private function append(array $entry):void{$this->measurements[]=$entry;if(count($this->measurements)>$this->maxEntries)array_shift($this->measurements);}
    private function assertName(string $name):void{if(!preg_match('/^[A-Za-z0-9_.:-]{1,120}$/',$name))throw new PerformanceException('Ungültiger Messpunktname.');}
    private function normalize(array $data):array{foreach($data as $k=>$v){if(is_object($v))$data[$k]=$v instanceof \Stringable?(string)$v:$v::class;elseif(is_resource($v))$data[$k]='resource';elseif(is_array($v))$data[$k]=$this->normalize($v);}return $data;}
}
