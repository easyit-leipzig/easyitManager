<?php
declare(strict_types=1);

namespace DataForm5\Core\Developer;

use DataForm5\Events\Core\EventDispatcher;
use DataForm5\Events\Core\EventCatalog;

final class EventInspector
{
    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly EventCatalog $catalog,
        private readonly DeveloperTrace $trace
    ) {}

    public function inspect(): array
    {
        $catalog=[];
        foreach($this->catalog->all() as $name=>$meta){
            $catalog[$name]=[
                'name'=>$name,
                'description'=>(string)($meta['description']??''),
                'producer'=>(string)($meta['producer']??''),
            ];
        }
        $listeners=$this->dispatcher->describeListeners();
        $trace=array_values(array_filter($this->trace->snapshot()['events']??[],static fn(array $row):bool=>isset($row['name'])));
        $allNames=array_unique(array_merge(array_keys($catalog),array_keys($listeners),array_column($trace,'name')));
        sort($allNames,SORT_STRING);
        $rows=[];
        foreach($allNames as $name){
            $rows[$name]=[
                'name'=>$name,
                'catalogued'=>isset($catalog[$name]),
                'description'=>(string)($catalog[$name]['description']??''),
                'producer'=>(string)($catalog[$name]['producer']??''),
                'listener_count'=>(int)($listeners[$name]['listeners']??0),
                'priorities'=>(array)($listeners[$name]['priorities']??[]),
                'dispatch_count'=>count(array_filter($trace,static fn(array $row):bool=>(string)$row['name']===$name)),
                'last_offset_ms'=>$this->lastOffset($trace,$name),
            ];
        }
        return [
            'summary'=>[
                'events'=>count($rows),
                'catalogued'=>count($catalog),
                'with_listeners'=>count(array_filter($rows,static fn(array $r):bool=>$r['listener_count']>0)),
                'dispatched'=>count(array_filter($rows,static fn(array $r):bool=>$r['dispatch_count']>0)),
                'listener_total'=>array_sum(array_column($rows,'listener_count')),
            ],
            'events'=>$rows,
            'trace'=>$trace,
        ];
    }

    private function lastOffset(array $trace,string $name): ?float
    {
        for($i=count($trace)-1;$i>=0;$i--){
            if((string)($trace[$i]['name']??'')===$name){
                return isset($trace[$i]['offset_ms'])?(float)$trace[$i]['offset_ms']:null;
            }
        }
        return null;
    }
}
