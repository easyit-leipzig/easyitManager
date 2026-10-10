<?php
declare(strict_types=1);
namespace DataForm5\Modules\Routing;
use DataForm5\Modules\Core\ModuleManager;
final class ModuleApiRegistry
{
    private ?array $cache=null;
    public function __construct(private readonly ModuleManager $manager) {}
    public function all(): array
    {
        if($this->cache!==null)return $this->cache;
        $routes=[];
        foreach($this->manager->discovered() as $module=>$item){
            $manifest=$item['manifest']; if(!$manifest->enabled) continue;
            foreach($manifest->apiRoutes as $route){
                if(!is_array($route)) continue;
                $name=trim((string)($route['name']??'')); $controller=trim((string)($route['controller']??''));
                if($name===''||$controller==='') continue;
                if(isset($routes[$name])) throw new \RuntimeException("Doppelte Modul-API-Route '{$name}'.");
                $methods=array_values(array_unique(array_map('strtoupper',array_filter((array)($route['methods']??['GET']),'is_string'))));
                if($methods===[]) $methods=['GET'];
                $routes[$name]=['name'=>$name,'module'=>$module,'controller'=>$controller,'methods'=>$methods,'capability'=>(string)($route['capability']??''),'module_path'=>$item['path']];
            }
        }
        ksort($routes); return $this->cache=$routes;
    }
    public function get(string $name): ?array { return $this->all()[$name]??null; }
}
