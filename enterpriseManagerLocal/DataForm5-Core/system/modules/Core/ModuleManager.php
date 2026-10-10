<?php
declare(strict_types=1);
namespace DataForm5\Modules\Core;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Modules\Contracts\ModuleInterface;
use DataForm5\Modules\Exceptions\ModuleException;
use DataForm5\Cache\Core\CacheNamespace;
use DataForm5\Core\Developer\ProfilerHub;
final class ModuleManager
{
    /** @var array<string,array{manifest:ModuleManifest,path:string,manifest_file:string}> */
    private array $discovered=[];
    /** @var list<string> */
    private array $scanPaths=[];

    public function __construct(private readonly ServiceContainer $container, private readonly ModuleRegistry $registry, private readonly ?CacheNamespace $cache=null) {}

    public function discover(string $modulesPath): self
    {
        return $this->discoverMany([$modulesPath]);
    }

    /** @param list<string> $modulesPaths */
    public function discoverMany(array $modulesPaths): self
    {
        $span=ProfilerHub::start('modules','discover',['paths'=>count($modulesPaths)]);
        try{
        $this->discovered=[];$this->scanPaths=[];
        $files=[];
        foreach($modulesPaths as $modulesPath){
            $modulesPath=rtrim((string)$modulesPath,'/\\');
            if($modulesPath===''||in_array($modulesPath,$this->scanPaths,true))continue;
            $this->scanPaths[]=$modulesPath;
            foreach(glob($modulesPath.'/*/module.json')?:[] as $file)$files[]=$file;
        }
        sort($files,SORT_STRING);
        $fingerprint=hash('sha256',implode('|',array_map(static fn(string $f):string=>$f.':'.(filemtime($f)?:0).':'.(filesize($f)?:0),$files)));
        $cached=$this->cache?->get('discovery:'.$fingerprint);
        $records=is_array($cached)?$cached:null;
        if($records===null){
            $records=[];
            foreach($files as $file){
                $data=json_decode((string)@file_get_contents($file),true);
                if(!is_array($data))throw new ModuleException("Ungültiges Modulmanifest: {$file}");
                $records[]=['file'=>$file,'data'=>$data];
            }
            $this->cache?->set('discovery:'.$fingerprint,$records,3600);
        }
        foreach($records as $record){
            $file=(string)($record['file']??'');
            $data=is_array($record['data']??null)?$record['data']:[];
            $manifest=ModuleManifest::fromArray($data,$file);
            if(isset($this->discovered[$manifest->name])){
                $first=$this->discovered[$manifest->name]['manifest_file'];
                throw new ModuleException("Doppeltes Modul '{$manifest->name}': {$first} und {$file}");
            }
            $this->discovered[$manifest->name]=['manifest'=>$manifest,'path'=>dirname($file),'manifest_file'=>$file];
        }
        return $this;
        }finally{ProfilerHub::stop($span,['modules'=>count($this->discovered)]);}
    }

    /** @return list<string> */
    public function loadEnabled(): array
    {
        $span=ProfilerHub::start('modules','load_enabled');
        try{
        $loaded=[]; $visiting=[];
        foreach(array_keys($this->discovered) as $name) $this->load($name,$loaded,$visiting);
        return array_values(array_unique($loaded));
        }finally{ProfilerHub::stop($span,['loaded'=>count($loaded??[])]);}
    }

    /** @param list<string> $loaded @param array<string,bool> $visiting */
    private function load(string $name,array &$loaded,array &$visiting): void
    {
        if ($this->registry->has($name)) return;
        $item=$this->discovered[$name] ?? null;
        if ($item===null) throw new ModuleException("Abhängigkeit '{$name}' wurde nicht gefunden.");
        if (!$item['manifest']->enabled) return;
        if (isset($visiting[$name])) throw new ModuleException("Zyklische Modulabhängigkeit bei '{$name}'.");
        $visiting[$name]=true;
        foreach($item['manifest']->dependencies as $dependency) $this->load($dependency,$loaded,$visiting);
        $bootstrap=$item['path'].'/bootstrap.php'; if(is_file($bootstrap)) require_once $bootstrap;
        $entry=$item['manifest']->entry;
        if(!class_exists($entry)) throw new ModuleException("Modulklasse '{$entry}' wurde nicht gefunden.");
        $instance=$this->container->build($entry);
        if(!$instance instanceof ModuleInterface) throw new ModuleException("'{$entry}' implementiert ModuleInterface nicht.");
        $instance->register($this->container); $this->registry->add($item['manifest'],$instance); $loaded[]=$name; unset($visiting[$name]);
    }

    public function bootLoaded(): void
    {
        foreach($this->registry->names() as $name){
            $module=$this->registry->get($name);
            if($module instanceof ModuleInterface) $module->boot($this->container);
        }
    }

    /** @return list<string> */
    public function scanPaths(): array { return $this->scanPaths; }

    /** @return array<string,array{manifest:ModuleManifest,path:string,manifest_file:string}> */
    public function discovered(): array { return $this->discovered; }

    /** @return array<string,array<string,mixed>> */
    public function status(): array
    {
        $result=[];
        foreach($this->discovered as $name=>$item){
            $manifest=$item['manifest'];
            $missing=[];
            foreach($manifest->dependencies as $dependency){
                if(!isset($this->discovered[$dependency])) $missing[]=$dependency;
            }
            $result[$name]=[
                'name'=>$name,
                'version'=>$manifest->version,
                'description'=>$manifest->description,
                'enabled'=>$manifest->enabled,
                'loaded'=>$this->registry->has($name),
                'dependencies'=>$manifest->dependencies,
                'dependency_constraints'=>$manifest->dependencyConstraints,
                'core_version'=>$manifest->coreVersion,
                'missing_dependencies'=>$missing,
                'path'=>$item['path'],
                'manifest_file'=>$item['manifest_file'],
                'entry'=>$manifest->entry,
            ];
        }
        ksort($result);
        return $result;
    }
}
