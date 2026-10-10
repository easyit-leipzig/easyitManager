<?php
declare(strict_types=1);
namespace DataForm5\Modules\SDK;
final class ModuleScaffolder
{
    /** @return array{directory:string,files:list<string>} */
    public function create(string $modulesRoot,string $name,string $namespace,string $className='Module',string $description=''): array
    {
        $name=trim($name); $namespace=trim($namespace,' \\'); $className=trim($className);
        if(!preg_match('/^[a-z0-9][a-z0-9._-]*$/',$name)) throw new \InvalidArgumentException('Ungültiger Modulname.');
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/',$namespace)) throw new \InvalidArgumentException('Ungültiger PHP-Namespace.');
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$className)) throw new \InvalidArgumentException('Ungültiger Klassenname.');
        $dir=rtrim($modulesRoot,'/\\').'/'.$name;
        if(file_exists($dir)) throw new \RuntimeException("Ziel existiert bereits: {$dir}");
        foreach(['src','src/Http','src/Providers','src/Events','src/Listeners','src/Jobs','src/Console','src/Models','src/Lifecycle','tests','resources','resources/views','resources/themes','config','forms','database/migrations'] as $sub) if(!mkdir($dir.'/'.$sub,0775,true) && !is_dir($dir.'/'.$sub)) throw new \RuntimeException("Verzeichnis konnte nicht angelegt werden: {$sub}");
        $entry=$namespace.'\\'.$className;
        $routeName=$name.'.index'; $routeHref='app/module.php?route='.rawurlencode($routeName);
        $manifest=['name'=>$name,'version'=>'0.1.0','entry'=>$entry,'dependencies'=>[],'core_version'=>'>=1.7.1','enabled'=>true,'description'=>$description,'capabilities'=>[$name.'.view',$name.'.manage'],'api_routes'=>[['name'=>$name.'.api.status','methods'=>['GET'],'controller'=>$namespace.'\\Http\\ApiController@status','capability'=>$name.'.view']],'lifecycle'=>['install'=>$namespace.'\\Lifecycle\\InstallHook','update'=>$namespace.'\\Lifecycle\\UpdateHook','enable'=>$namespace.'\\Lifecycle\\EnableHook','disable'=>$namespace.'\\Lifecycle\\DisableHook','uninstall'=>$namespace.'\\Lifecycle\\UninstallHook'],'jobs'=>[$name.'.example'=>['name'=>$name.'.example','handler'=>$namespace.'\\Jobs\\ExampleJob','queue'=>'file','max_attempts'=>3,'retry_delay'=>5]],'schedules'=>[$name.'.example.schedule'=>['name'=>$name.'.example.schedule','job'=>$name.'.example','cron'=>'0 * * * *','queue'=>'file','payload'=>[],'without_overlapping'=>true,'lock_ttl'=>3600]],'routes'=>[['name'=>$routeName,'methods'=>['GET','POST'],'controller'=>$namespace.'\\Http\\ModuleController@index','capability'=>$name.'.view','title'=>ucwords(str_replace(['-','_'],' ',$name))]],'ui'=>['navigation'=>[['label'=>ucwords(str_replace(['-','_'], ' ', $name)),'href'=>$routeHref,'capability'=>$name.'.view','priority'=>100]],'dashboard'=>[['label'=>ucwords(str_replace(['-','_'], ' ', $name)),'href'=>$routeHref,'description'=>$description,'capability'=>$name.'.view','priority'=>100]]]];
        file_put_contents($dir.'/module.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");

        $bootstrap = str_replace('__NAMESPACE__', $namespace, <<<'TPL'
<?php
declare(strict_types=1);
spl_autoload_register(static function(string $class): void {
    $prefix='__NAMESPACE__\\';
    if(!str_starts_with($class,$prefix)) return;
    $file=__DIR__.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
    if(is_file($file)) require $file;
});
TPL
        );
        file_put_contents($dir.'/bootstrap.php',$bootstrap."\n");

        $php = str_replace(
            ['__NAMESPACE__','__CLASS__'],
            [$namespace,$className],
            <<<'TPL'
<?php
declare(strict_types=1);
namespace __NAMESPACE__;
use DataForm5\Modules\SDK\AbstractModule;
use DataForm5\Core\Container\ServiceContainer;
final class __CLASS__ extends AbstractModule
{
    public function register(ServiceContainer $container): void
    {
        // Services registrieren.
    }

    public function boot(ServiceContainer $container): void
    {
        // Hooks/Listener nach der Registrierung aktivieren.
    }
}
TPL
        );
        file_put_contents($dir.'/src/'.$className.'.php',$php."\n");
        $controller = str_replace('__NAMESPACE__', $namespace, <<<'TPL'
<?php
declare(strict_types=1);
namespace __NAMESPACE__\Http;
use DataForm5\Http\Core\Request;
use DataForm5\Http\Core\Response;
use DataForm5\Forms\Core\{FormFactory,FormRenderer};
final class ModuleController
{
    public function __construct(private readonly FormFactory $forms,private readonly FormRenderer $renderer) {}
    public function index(Request $request): Response
    {
        $route=$request->route()??[]; $title=(string)($route['title']??'Modul');
        $form=$this->forms->fromFile('example',dirname(__DIR__,2).'/forms/example.php'); $result=null; $message='';
        if($request->method()==='POST'){
            $form=$form->bind((array)$request->body()); $result=$form->validate((array)$request->body());
            if($result->passes()) $message='<div class="notice success">Formular erfolgreich validiert.</div>';
        }
        $html='<section class="hero"><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>Dieses Modul nutzt deklarative Formulare mit automatischem CSRF-Schutz.</p></section>'.$message;
        $html.=$this->renderer->render($form,$result,'app/module.php?route='.rawurlencode((string)($route['name']??'')),'POST');
        return Response::page($html,$title,$result?->fails()?422:200);
    }
}
TPL
        );
        file_put_contents($dir.'/src/Http/ModuleController.php',$controller."\n");
        $apiController = str_replace('__NAMESPACE__', $namespace, <<<'TPL'
<?php
declare(strict_types=1);
namespace __NAMESPACE__\Http;
use DataForm5\Api\Core\ApiResponse;
use DataForm5\Http\Core\Request;
use DataForm5\Http\Core\Response;
final class ApiController
{
    public function __construct(private readonly ApiResponse $api) {}
    public function status(Request $request): Response
    {
        return $this->api->success(['module'=>(string)(($request->route()??[])['module']??''),'status'=>'ok']);
    }
}
TPL
        );
        file_put_contents($dir.'/src/Http/ApiController.php',$apiController."\n");
        $jobClass = str_replace('__NAMESPACE__', $namespace, <<<'TPL'
<?php
declare(strict_types=1);
namespace __NAMESPACE__\Jobs;
final class ExampleJob
{
    public function handle(array $payload): void
    {
        // Hintergrundarbeit des Moduls. Payload ist bereits deserialisiert.
    }
}
TPL
        );
        file_put_contents($dir.'/src/Jobs/ExampleJob.php',$jobClass."\n");
        file_put_contents($dir.'/README.md',"# {$name}\n\n{$description}\n\n## Entwicklung\n\nDieses Modul wurde mit dem easyIT Enterprise Module SDK erzeugt.\n");
        file_put_contents($dir.'/tests/smoke.php',"<?php\ndeclare(strict_types=1);\nif(!is_file(__DIR__.'/../module.json')) exit(1);\necho 'MODULE_SMOKE_OK'.PHP_EOL;\n");
        file_put_contents($dir.'/config/schema.php',"<?php\ndeclare(strict_types=1);\nreturn [\n    'enabled' => ['type'=>'bool','default'=>true,'required'=>true],\n    // 'api_key' => ['type'=>'string','default'=>'','secret'=>true],\n];\n"); file_put_contents($dir.'/resources/.gitkeep','');
        file_put_contents($dir.'/database/migrations/.gitkeep','');
        foreach(['Install','Update','Enable','Disable','Uninstall'] as $hook){
            $hookClass=$hook.'Hook';
            $hookPhp="<?php\ndeclare(strict_types=1);\nnamespace {$namespace}\\Lifecycle;\nuse DataForm5\\Core\\Container\\ServiceContainer;\nfinal class {$hookClass}\n{\n    public function __invoke(ServiceContainer \$container): void\n    {\n        // Lifecycle hook: ".strtolower($hook)."\n    }\n}\n";
            file_put_contents($dir.'/src/Lifecycle/'.$hookClass.'.php',$hookPhp);
        }
        file_put_contents($dir.'/resources/views/index.php',"<?php\ndeclare(strict_types=1);\n?>\n<section><h1>".htmlspecialchars($name,ENT_QUOTES,'UTF-8')."</h1></section>\n");
        file_put_contents($dir.'/resources/themes/default.css',"/* {$name} module theme */\n");
        file_put_contents($dir.'/tests/manifest.php',"<?php\ndeclare(strict_types=1);\n\$data=json_decode((string)file_get_contents(__DIR__.'/../module.json'),true);\nif(!is_array(\$data)||(\$data['name']??'')!=='{$name}') exit(1);\necho 'MODULE_MANIFEST_OK'.PHP_EOL;\n");
        file_put_contents($dir.'/DEVELOPMENT.md',"# Entwicklung\n\n## CLI\n\n```bash\nphp easyit make:provider ExampleProvider --module={$name}\nphp easyit make:event ExampleCreated --module={$name}\nphp easyit make:listener ExampleListener --module={$name}\nphp easyit make:crud Example --module={$name}\n```\n");
        $files=[]; $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $file) if($file->isFile()) $files[]=str_replace('\\','/',$file->getPathname());
        sort($files); return ['directory'=>$dir,'files'=>$files];
    }
}
