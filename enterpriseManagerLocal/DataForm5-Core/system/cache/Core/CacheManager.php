<?php
declare(strict_types=1);
namespace DataForm5\Cache\Core;
use DataForm5\Cache\Contracts\CacheInterface;
use DataForm5\Core\Config;
use DataForm5\Core\Support\Path;
use DataForm5\Cache\Exceptions\CacheException;
use DataForm5\Core\Developer\ProfilerHub;
final class CacheManager
{
    /** @var array<string,CacheInterface> */ private array $stores=[];
    public function __construct(private readonly Config $config,private readonly Path $path){}
    public function store(?string $name=null):CacheInterface {
        $name=$name??(string)$this->config->get('cache.default','file');
        $hit=isset($this->stores[$name]);
        $span=ProfilerHub::start('cache',$hit?'store.hit':'store.create',['store'=>$name]);
        try{return $this->stores[$name]??=$this->create($name);}
        finally{ProfilerHub::stop($span);}
    }
    public function forgetStore(string $name):void { unset($this->stores[$name]); }
    private function create(string $name):CacheInterface {
        $definition=$this->config->get('cache.stores.'.$name); if(!is_array($definition))throw new CacheException('Unbekannter Cache-Store: '.$name);
        return match((string)($definition['driver']??$name)){
            'array'=>new ArrayCache(),
            'file'=>new FileCache($this->resolvePath((string)($definition['path']??'storage/framework/cache/data')),(string)($definition['namespace']??'dataform5')),
            default=>throw new CacheException('Nicht unterstützter Cache-Treiber: '.($definition['driver']??$name)),
        };
    }
    private function resolvePath(string $value):string { if(str_starts_with($value,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$value))return $value; return rtrim($this->path->base(),'/\\').'/'.ltrim($value,'/\\'); }
}
