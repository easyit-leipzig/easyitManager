<?php
declare(strict_types=1);
namespace DataForm5\Observability\Core;
use DataForm5\Observability\Contracts\{MetricsInterface,MetricStoreInterface};
final class MetricsManager implements MetricsInterface
{
    public function __construct(private readonly MetricStoreInterface $store,private readonly array $defaultTags=[]){ }
    private function tags(array $tags):array{return array_merge($this->defaultTags,$tags);}
    public function increment(string $name,float $value=1.0,array $tags=[]):void{$this->store->increment($name,$value,$this->tags($tags));}
    public function gauge(string $name,float $value,array $tags=[]):void{$this->store->gauge($name,$value,$this->tags($tags));}
    public function observe(string $name,float $value,array $tags=[]):void{$this->store->observe($name,$value,$this->tags($tags));}
    public function timer(string $name,array $tags=[]):Timer{return new Timer($this,$name,$this->tags($tags));}
    public function measure(string $name,callable $callback,array $tags=[]):mixed{$timer=$this->timer($name,$tags);try{return $callback();}finally{$timer->stop();}}
    public function snapshot():array{return $this->store->snapshot();}
    public function clear():void{$this->store->clear();}
    public function healthy():bool{return $this->store->healthy();}
}
