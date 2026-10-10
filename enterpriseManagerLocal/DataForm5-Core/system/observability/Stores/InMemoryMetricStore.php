<?php
declare(strict_types=1);
namespace DataForm5\Observability\Stores;
use DataForm5\Observability\Contracts\MetricStoreInterface;
use DataForm5\Observability\Core\MetricKey;
class InMemoryMetricStore implements MetricStoreInterface
{
    protected array $metrics=[];
    public function increment(string $name,float $value=1.0,array $tags=[]):void{$this->mutate('counter',$name,$value,$tags);}
    public function gauge(string $name,float $value,array $tags=[]):void{$this->mutate('gauge',$name,$value,$tags,true);}
    public function observe(string $name,float $value,array $tags=[]):void{$this->mutate('histogram',$name,$value,$tags);}
    protected function mutate(string $type,string $name,float $value,array $tags,bool $replace=false):void
    {
        $name=MetricKey::normalizeName($name);$tags=MetricKey::normalizeTags($tags);$id=MetricKey::id($name,$tags);$now=gmdate(DATE_ATOM);
        $m=$this->metrics[$id]??['name'=>$name,'type'=>$type,'tags'=>$tags,'count'=>0,'sum'=>0.0,'min'=>null,'max'=>null,'value'=>0.0,'updated_at'=>$now];
        if($m['type']!==$type){throw new \DataForm5\Observability\Exceptions\ObservabilityException("Metrik '{$name}' wurde bereits als {$m['type']} registriert.");}
        if($type==='counter'){$m['count']++;$m['sum']+=(float)$value;$m['value']=$m['sum'];$m['min']=$m['min']===null?$value:min($m['min'],$value);$m['max']=$m['max']===null?$value:max($m['max'],$value);}
        elseif($type==='gauge'){$m['count']++;$m['sum']+=(float)$value;$m['value']=(float)$value;$m['min']=$m['min']===null?$value:min($m['min'],$value);$m['max']=$m['max']===null?$value:max($m['max'],$value);}
        else{$m['count']++;$m['sum']+=(float)$value;$m['value']=$m['sum']/$m['count'];$m['min']=$m['min']===null?$value:min($m['min'],$value);$m['max']=$m['max']===null?$value:max($m['max'],$value);}
        $m['updated_at']=$now;$this->metrics[$id]=$m;
    }
    public function snapshot():array{$items=array_values($this->metrics);usort($items,fn($a,$b)=>[$a['name'],json_encode($a['tags'])]<=>[$b['name'],json_encode($b['tags'])]);return ['generated_at'=>gmdate(DATE_ATOM),'metrics'=>$items,'count'=>count($items)];}
    public function clear():void{$this->metrics=[];}
    public function healthy():bool{return true;}
}
