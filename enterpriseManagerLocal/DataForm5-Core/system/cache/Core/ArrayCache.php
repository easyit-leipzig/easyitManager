<?php
declare(strict_types=1);
namespace DataForm5\Cache\Core;
use DataForm5\Cache\Contracts\CacheInterface;
final class ArrayCache implements CacheInterface
{
    /** @var array<string,array{value:mixed,expires:?int}> */
    private array $items=[];
    public function get(string $key,mixed $default=null):mixed { if(!$this->has($key))return $default; return $this->items[$key]['value']; }
    public function set(string $key,mixed $value,?int $ttl=null):bool { $this->assertKey($key); $this->items[$key]=['value'=>$value,'expires'=>$ttl===null?null:time()+max(0,$ttl)]; return true; }
    public function has(string $key):bool { $this->assertKey($key); if(!isset($this->items[$key]))return false; $expires=$this->items[$key]['expires']; if($expires!==null&&$expires<=time()){unset($this->items[$key]);return false;} return true; }
    public function delete(string $key):bool { $this->assertKey($key); unset($this->items[$key]); return true; }
    public function clear():bool { $this->items=[]; return true; }
    public function remember(string $key,?int $ttl,callable $resolver):mixed { if($this->has($key))return $this->get($key); $value=$resolver(); $this->set($key,$value,$ttl); return $value; }
    private function assertKey(string $key):void { if($key==='')throw new \InvalidArgumentException('Cache-Schlüssel darf nicht leer sein.'); }
}
