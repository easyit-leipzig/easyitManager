<?php
declare(strict_types=1);
namespace DataForm5\Cache\Core;
use DataForm5\Cache\Contracts\CacheInterface;
final class CacheNamespace
{
    public function __construct(private readonly CacheInterface $cache,private readonly string $prefix){}
    private function key(string $key):string{return $this->prefix.':'.$key;}
    public function get(string $key,mixed $default=null):mixed{return $this->cache->get($this->key($key),$default);}
    public function set(string $key,mixed $value,?int $ttl=null):bool{return $this->cache->set($this->key($key),$value,$ttl);}
    public function delete(string $key):bool{return $this->cache->delete($this->key($key));}
    public function remember(string $key,?int $ttl,callable $resolver):mixed{return $this->cache->remember($this->key($key),$ttl,$resolver);}
}
