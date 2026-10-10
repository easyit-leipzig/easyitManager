<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Modules\Core\ModuleManager;
use DataForm5\Events\Core\EventDispatcher;
use DataForm5\Modules\Background\ModuleBackgroundAdmin;
use DataForm5\Scheduler\Core\Scheduler;

final class DeveloperInspector
{
    public function __construct(
        private readonly ServiceContainer $container,
        private readonly ModuleManager $modules,
        private readonly EventDispatcher $events,
        private readonly ModuleBackgroundAdmin $background,
        private readonly Scheduler $scheduler,
        private readonly DeveloperTrace $trace
    ) {}

    public function snapshot(): array
    {
        $moduleRows=[];
        foreach($this->modules->status() as $row){
            $moduleRows[]=[
                'name'=>(string)($row['name']??''),
                'version'=>(string)($row['version']??''),
                'enabled'=>(bool)($row['enabled']??false),
                'loaded'=>(bool)($row['loaded']??false),
                'issues'=>count((array)($row['missing_dependencies']??[])),
            ];
        }

        $background=['stats'=>['pending'=>0,'processing'=>0,'failed'=>0],'tasks'=>[]];
        try{$background=$this->background->overview('file');}catch(\Throwable){}

        $container=$this->container->describe();
        $resolved=count(array_filter($container,static fn(array $r):bool=>(bool)($r['resolved']??false)));
        $singletons=count(array_filter($container,static fn(array $r):bool=>(bool)($r['shared']??false)));

        $catalog=[];
        try{
            if(function_exists('enterprise_event_catalog')){
                $reflection=new \ReflectionObject(enterprise_event_catalog());
                if($reflection->hasMethod('all')){
                    $catalog=enterprise_event_catalog()->all();
                }
            }
        }catch(\Throwable){}

        return [
            'runtime'=>$this->trace->snapshot(),
            'container'=>[
                'total'=>count($container),
                'resolved'=>$resolved,
                'singletons'=>$singletons,
                'services'=>$container,
                'aliases'=>$this->container->aliases(),
            ],
            'modules'=>$moduleRows,
            'events'=>[
                'catalog'=>$catalog,
                'trace'=>$this->trace->snapshot()['events'],
            ],
            'queue'=>[
                'stats'=>$background['stats']??[],
            ],
            'scheduler'=>[
                'tasks'=>array_map(static fn($task):array=>[
                    'name'=>$task->name(),
                    'expression'=>$task->expression(),
                    'without_overlapping'=>$task->preventsOverlapping(),
                ],$this->scheduler->tasks()),
            ],
        ];
    }
}
