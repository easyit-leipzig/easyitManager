<?php
declare(strict_types=1);
namespace DataForm5\Modules\Core;
use DataForm5\Modules\Exceptions\ModuleException;
final class ModuleManifest
{
    /** @param list<string> $dependencies @param array<string,string> $dependencyConstraints */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $entry,
        public readonly array $dependencies = [],
        public readonly bool $enabled = true,
        public readonly string $description = '',
        public readonly array $dependencyConstraints = [],
        public readonly string $coreVersion = '*',
        public readonly array $capabilities = [],
        public readonly array $ui = [],
        public readonly array $routes = [],
        public readonly array $apiRoutes = [],
        public readonly array $lifecycle = [],
        public readonly array $jobs = [],
        public readonly array $schedules = []
    ) {}
    public static function fromFile(string $file): self
    {
        $data=json_decode((string)@file_get_contents($file),true);
        if(!is_array($data))throw new ModuleException("Ungültiges Modulmanifest: {$file}");
        return self::fromArray($data,$file);
    }
    public static function fromArray(array $data,string $source='[array]'): self
    {
        foreach(['name','version','entry'] as $key)if(!isset($data[$key])||!is_string($data[$key])||$data[$key]==='')throw new ModuleException("Manifestfeld '{$key}' fehlt in {$source}");
        $raw=$data['dependencies']??[]; $deps=[]; $constraints=[];
        if(is_array($raw)){
            foreach($raw as $key=>$value){
                if(is_int($key)&&is_string($value)&&$value!==''){$deps[]=$value;$constraints[$value]='*';}
                elseif(is_string($key)&&$key!==''&&is_string($value)){$deps[]=$key;$constraints[$key]=$value===''?'*':$value;}
            }
        }
        return new self($data['name'],$data['version'],$data['entry'],array_values(array_unique($deps)),(bool)($data['enabled']??true),(string)($data['description']??''),$constraints,(string)($data['core_version']??'*'),array_values(array_filter((array)($data['capabilities']??[]),'is_string')),(array)($data['ui']??[]),(array)($data['routes']??[]),(array)($data['api_routes']??[]),(array)($data['lifecycle']??[]),(array)($data['jobs']??[]),(array)($data['schedules']??[]));
    }
}
