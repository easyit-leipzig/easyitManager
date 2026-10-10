<?php
declare(strict_types=1);

namespace DataForm5\Modules\SDK;

final class SdkGenerator
{
    public function __construct(private readonly string $enterpriseRoot) {}

    public function generate(string $kind,string $name,?string $module=null): array
    {
        $kind=strtolower(trim($kind));
        $name=trim($name);
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$name)) throw new \InvalidArgumentException('Ungültiger Artefaktname.');
        if($module===null||trim($module)==='') throw new \InvalidArgumentException("--module ist für make:{$kind} erforderlich.");
        $moduleDir=$this->moduleDirectory($module);
        $namespace=$this->moduleNamespace($moduleDir);
        $spec=$this->spec($kind,$name,$namespace);
        $target=$moduleDir.'/'.$spec['path'];
        if(is_file($target)) throw new \RuntimeException("Zieldatei existiert bereits: {$target}");
        $dir=dirname($target);
        if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir)) throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$dir}");
        file_put_contents($target,$spec['content']);
        return ['kind'=>$kind,'name'=>$name,'module'=>$module,'namespace'=>$namespace,'file'=>$target,'relative_file'=>str_replace('\\','/',substr($target,strlen($this->enterpriseRoot)+1))];
    }

    public function createModule(string $name,?string $namespace=null,string $description=''): array
    {
        $slug=$this->slug($name);
        $namespace=$namespace!==null&&trim($namespace)!==''?trim($namespace,' \\'):'EasyIT\\Modules\\'.$this->studly($name);
        return (new ModuleScaffolder())->create($this->enterpriseRoot.'/modules',$slug,$namespace,'Module',$description);
    }

    public function kinds(): array
    {
        return ['provider','event','listener','migration','model','controller','view','api','theme','job','command','test'];
    }

    private function spec(string $kind,string $name,string $namespace): array
    {
        if(!in_array($kind,$this->kinds(),true)) throw new \InvalidArgumentException("Unbekannter Generator-Typ '{$kind}'.");
        $php=static fn(string $ns,string $body):string=>"<?php\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\n".trim($body)."\n";
        return match($kind){
            'provider'=>['path'=>"src/Providers/{$name}.php",'content'=>$php($namespace.'\\Providers',"use DataForm5\\Core\\Container\\ServiceContainer;\nuse DataForm5\\Core\\Contracts\\ServiceProviderInterface;\n\nfinal class {$name} implements ServiceProviderInterface\n{\n    public function register(ServiceContainer \$container): void {}\n    public function boot(ServiceContainer \$container): void {}\n}")],
            'event'=>['path'=>"src/Events/{$name}.php",'content'=>$php($namespace.'\\Events',"final class {$name}\n{\n    public function __construct(public readonly array \$payload = []) {}\n}")],
            'listener'=>['path'=>"src/Listeners/{$name}.php",'content'=>$php($namespace.'\\Listeners',"final class {$name}\n{\n    public function __invoke(object \$event): void\n    {\n        // Event verarbeiten.\n    }\n}")],
            'migration'=>['path'=>'database/migrations/'.date('Ymd_His').'_'.strtolower(preg_replace('/(?<!^)[A-Z]/','_$0',$name)).'.php','content'=>"<?php\ndeclare(strict_types=1);\n\nreturn static function(\\PDO \$pdo): void {\n    // Migration {$name}\n};\n"],
            'model'=>['path'=>"src/Models/{$name}.php",'content'=>$php($namespace.'\\Models',"final class {$name}\n{\n    public function __construct(public readonly array \$attributes = []) {}\n}")],
            'controller'=>['path'=>"src/Http/{$name}.php",'content'=>$php($namespace.'\\Http',"use DataForm5\\Http\\Core\\Request;\nuse DataForm5\\Http\\Core\\Response;\n\nfinal class {$name}\n{\n    public function __invoke(Request \$request): Response\n    {\n        return Response::page('<h1>{$name}</h1>','{$name}');\n    }\n}")],
            'view'=>['path'=>"resources/views/{$name}.php",'content'=>"<?php\ndeclare(strict_types=1);\n?>\n<section>\n    <h1>{$name}</h1>\n</section>\n"],
            'api'=>['path'=>"src/Http/{$name}.php",'content'=>$php($namespace.'\\Http',"use DataForm5\\Api\\Core\\ApiResponse;\nuse DataForm5\\Http\\Core\\Request;\nuse DataForm5\\Http\\Core\\Response;\n\nfinal class {$name}\n{\n    public function __construct(private readonly ApiResponse \$api) {}\n    public function __invoke(Request \$request): Response\n    {\n        return \$this->api->success(['status'=>'ok']);\n    }\n}")],
            'theme'=>['path'=>"resources/themes/{$name}.css",'content'=>"/* easyIT theme: {$name} */\n:root {\n    --easyit-theme-name: '{$name}';\n}\n"],
            'job'=>['path'=>"src/Jobs/{$name}.php",'content'=>$php($namespace.'\\Jobs',"final class {$name}\n{\n    public function handle(array \$payload): void\n    {\n        // Hintergrundarbeit.\n    }\n}")],
            'command'=>['path'=>"src/Console/{$name}.php",'content'=>$php($namespace.'\\Console',"use DataForm5\\Console\\Core\\{AbstractCommand,Input,Output};\n\nfinal class {$name} extends AbstractCommand\n{\n    public function name(): string { return 'module:command'; }\n    public function description(): string { return 'Modulkommando.'; }\n    public function execute(Input \$input,Output \$output): int\n    {\n        \$output->success('{$name} ausgeführt.');\n        return 0;\n    }\n}")],
            'test'=>['path'=>"tests/{$name}.php",'content'=>"<?php\ndeclare(strict_types=1);\n\necho 'TEST_OK'.PHP_EOL;\n"],
        };
    }

    private function moduleDirectory(string $module): string
    {
        $module=trim($module);
        if(!preg_match('/^[a-z0-9][a-z0-9._-]*$/',$module)) throw new \InvalidArgumentException('Ungültiger Modulname.');
        $dir=$this->enterpriseRoot.'/modules/'.$module;
        if(!is_dir($dir)||!is_file($dir.'/module.json')) throw new \RuntimeException("Modul '{$module}' wurde nicht gefunden.");
        return $dir;
    }

    private function moduleNamespace(string $moduleDir): string
    {
        $data=json_decode((string)file_get_contents($moduleDir.'/module.json'),true);
        $entry=is_array($data)&&is_string($data['entry']??null)?$data['entry']:'';
        if($entry==='') throw new \RuntimeException('module.json enthält keinen gültigen entry.');
        $pos=strrpos($entry,'\\');
        return $pos===false?'':substr($entry,0,$pos);
    }

    private function slug(string $name): string
    {
        $value=strtolower(trim((string)preg_replace('/[^A-Za-z0-9]+/','-',$name),'-'));
        if($value==='') throw new \InvalidArgumentException('Ungültiger Modulname.');
        return $value;
    }

    private function studly(string $name): string
    {
        $parts=preg_split('/[^A-Za-z0-9]+/',$name,-1,PREG_SPLIT_NO_EMPTY)?:[];
        $value=implode('',array_map(static fn(string $p):string=>ucfirst(strtolower($p)),$parts));
        return $value!==''?$value:'Module';
    }
}
