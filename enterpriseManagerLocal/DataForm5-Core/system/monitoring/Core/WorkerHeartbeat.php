<?php
declare(strict_types=1);
namespace DataForm5\Monitoring\Core;
final class WorkerHeartbeat
{
    public function __construct(private readonly string $directory){}
    public function beat(string $name,array $context=[]): void
    {
        if(!is_dir($this->directory)) @mkdir($this->directory,0775,true);
        $safe=preg_replace('/[^A-Za-z0-9_.-]/','_',trim($name)) ?: 'worker';
        $row=['name'=>$name,'time'=>date(DATE_ATOM),'timestamp'=>time(),'pid'=>getmypid(),'host'=>gethostname()?:php_uname('n'),'context'=>$this->sanitize($context)];
        @file_put_contents(rtrim($this->directory,'/\\').'/'.$safe.'.json',json_encode($row,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
    }
    public function all(): array
    {
        $rows=[];
        foreach(glob(rtrim($this->directory,'/\\').'/*.json')?:[] as $file){
            $row=json_decode((string)@file_get_contents($file),true);
            if(is_array($row)) $rows[]=$row;
        }
        usort($rows,fn(array $a,array $b):int=>(int)($b['timestamp']??0)<=>(int)($a['timestamp']??0));
        return $rows;
    }
    private function sanitize(array $context): array
    {
        $out=[]; foreach($context as $k=>$v){
            if(preg_match('/password|secret|token|api[_-]?key/i',(string)$k)){$out[$k]='[REDACTED]';continue;}
            $out[$k]=is_scalar($v)||$v===null?$v:'['.get_debug_type($v).']';
        } return $out;
    }
}
