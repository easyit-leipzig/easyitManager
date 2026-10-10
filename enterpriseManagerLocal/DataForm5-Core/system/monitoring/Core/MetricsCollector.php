<?php
declare(strict_types=1);
namespace DataForm5\Monitoring\Core;
use DataForm5\Modules\Background\ModuleBackgroundAdmin;
final class MetricsCollector
{
    public function __construct(private readonly ModuleBackgroundAdmin $background){}
    public function collect(): array
    {
        $queue=['pending'=>0,'processing'=>0,'failed'=>0];
        try{$queue=(array)($this->background->overview('file')['stats']??$queue);}catch(\Throwable){}
        $diskTotal=@disk_total_space('.'); $diskFree=@disk_free_space('.');
        $diskPercent=($diskTotal&&$diskFree!==false)?round(((float)$diskFree/(float)$diskTotal)*100,2):null;
        $memoryUsage=memory_get_usage(true); $memoryPeak=memory_get_peak_usage(true);
        $limit=$this->memoryLimitBytes((string)ini_get('memory_limit'));
        $memoryPercent=$limit>0?round(($memoryUsage/$limit)*100,2):null;
        $load=function_exists('sys_getloadavg')?sys_getloadavg():false;
        return [
            'time'=>date(DATE_ATOM),
            'php'=>['version'=>PHP_VERSION,'memory_usage'=>$memoryUsage,'memory_peak'=>$memoryPeak,'memory_limit'=>$limit,'memory_percent'=>$memoryPercent],
            'system'=>['hostname'=>gethostname()?:php_uname('n'),'load_1m'=>is_array($load)?($load[0]??null):null,'disk_free'=>$diskFree===false?null:$diskFree,'disk_total'=>$diskTotal===false?null:$diskTotal,'disk_free_percent'=>$diskPercent],
            'queue'=>$queue,
        ];
    }
    private function memoryLimitBytes(string $value): int
    {
        $value=trim($value); if($value===''||$value==='-1') return 0;
        $n=(float)$value; $suffix=strtolower(substr($value,-1));
        return (int)match($suffix){'g'=>$n*1024*1024*1024,'m'=>$n*1024*1024,'k'=>$n*1024,default=>$n};
    }
}
