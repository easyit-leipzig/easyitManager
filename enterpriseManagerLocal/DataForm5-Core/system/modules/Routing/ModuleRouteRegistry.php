<?php
declare(strict_types=1);
namespace DataForm5\Modules\Routing;

use DataForm5\Modules\Core\ModuleManager;

final class ModuleRouteRegistry
{
    private ?array $cache=null;
    public function __construct(private readonly ModuleManager $manager) {}

    /** @return array<string,array<string,mixed>> */
    public function all(): array
    {
        if($this->cache!==null)return $this->cache;
        $routes=[];
        foreach($this->manager->discovered() as $module=>$item){
            $manifest=$item['manifest'];
            if(!$manifest->enabled) continue;
            foreach($manifest->routes as $route){
                if(!is_array($route)) continue;
                $name=trim((string)($route['name']??''));
                $controller=trim((string)($route['controller']??''));
                if($name==='' || $controller==='') continue;
                if(isset($routes[$name])) throw new \RuntimeException("Doppelte Modulroute '{$name}'.");
                $methods=array_values(array_unique(array_map('strtoupper',array_filter((array)($route['methods']??['GET']),'is_string'))));
                if($methods===[]) $methods=['GET'];
                $routes[$name]=[
                    'name'=>$name,
                    'module'=>$module,
                    'controller'=>$controller,
                    'methods'=>$methods,
                    'capability'=>(string)($route['capability']??''),
                    'title'=>(string)($route['title']??$name),
                    'path'=>(string)($route['path']??$name),
                    'module_path'=>$item['path'],
                ];
            }
        }
        ksort($routes);
        return $this->cache=$routes;
    }

    /** @return array<string,mixed>|null */
    public function get(string $name): ?array
    {
        return $this->all()[$name]??null;
    }
}
