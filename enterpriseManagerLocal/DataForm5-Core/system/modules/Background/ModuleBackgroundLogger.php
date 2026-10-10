<?php
declare(strict_types=1);

namespace DataForm5\Modules\Background;

final class ModuleBackgroundLogger
{
    public function __construct(private readonly string $file) {}

    public function record(string $status,string $job,string $module,array $context=[]): void
    {
        $dir=dirname($this->file);
        if(!is_dir($dir) && !@mkdir($dir,0775,true) && !is_dir($dir)) return;
        $row=[
            'time'=>date(DATE_ATOM),
            'status'=>$status,
            'job'=>$job,
            'module'=>$module,
            'context'=>$this->sanitize($context),
        ];
        $json=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(is_string($json)) @file_put_contents($this->file,$json.PHP_EOL,FILE_APPEND|LOCK_EX);
    }

    private function sanitize(array $data): array
    {
        $result=[];
        foreach($data as $key=>$value){
            $name=strtolower((string)$key);
            if(preg_match('/password|secret|token|api[_-]?key/',$name)){
                $result[$key]='[REDACTED]';
            }elseif(is_scalar($value)||$value===null){
                $result[$key]=$value;
            }else{
                $result[$key]='['.get_debug_type($value).']';
            }
        }
        return $result;
    }
}
