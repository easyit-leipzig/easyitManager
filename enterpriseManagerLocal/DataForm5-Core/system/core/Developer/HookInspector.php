<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

use DataForm5\Modules\Core\ModuleManager;

final class HookInspector
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly DeveloperTrace $trace
    ) {}

    public function inspect(): array
    {
        $hooks=[];
        foreach($this->modules->discovered() as $moduleName=>$item){
            $manifest=$item['manifest'];
            foreach((array)$manifest->lifecycle as $hook=>$handler){
                $hook=(string)$hook;
                $handler=(string)$handler;
                if($hook===''||$handler==='') continue;
                $hooks[]=[
                    'module'=>$moduleName,
                    'hook'=>$hook,
                    'handler'=>$handler,
                    'enabled'=>$manifest->enabled,
                    'loaded'=>false,
                    'priority'=>$this->priorityFor($hook),
                    'runtime'=>$this->runtimeFor($moduleName,$hook),
                ];
            }
        }

        usort($hooks,static function(array $a,array $b):int{
            return [$a['hook'],$b['priority'],$a['module']] <=> [$b['hook'],$a['priority'],$b['module']];
        });

        return [
            'summary'=>[
                'hooks'=>count($hooks),
                'modules'=>count(array_unique(array_column($hooks,'module'))),
                'with_runtime'=>count(array_filter($hooks,static fn(array $r):bool=>$r['runtime']!==null)),
            ],
            'hooks'=>$hooks,
        ];
    }

    private function priorityFor(string $hook): int
    {
        return match($hook){
            'install'=>100,
            'update'=>90,
            'enable'=>80,
            'disable'=>20,
            'uninstall'=>10,
            default=>50,
        };
    }

    private function runtimeFor(string $module,string $hook): ?array
    {
        $events=$this->trace->snapshot()['events']??[];
        $needle='module.'.$hook;
        $matches=array_values(array_filter($events,static function(array $row)use($needle):bool{
            $name=(string)($row['name']??'');
            return str_starts_with($name,$needle);
        }));
        if($matches===[]) return null;
        $last=end($matches);
        return [
            'event'=>(string)($last['name']??''),
            'offset_ms'=>(float)($last['offset_ms']??0),
        ];
    }
}
