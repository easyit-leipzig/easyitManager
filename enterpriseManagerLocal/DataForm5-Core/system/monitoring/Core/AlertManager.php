<?php
declare(strict_types=1);
namespace DataForm5\Monitoring\Core;
final class AlertManager
{
    public function __construct(private readonly array $thresholds,private readonly string $logFile){}
    public function evaluate(array $metrics,array $heartbeats): array
    {
        $alerts=[]; $now=time();
        $q=(array)($metrics['queue']??[]);
        if((int)($q['pending']??0)>=(int)($this->thresholds['queue_pending_warning']??100)) $alerts[]=$this->a('queue_pending','warning','Queue enthält viele wartende Jobs.');
        if((int)($q['failed']??0)>=(int)($this->thresholds['queue_failed_warning']??1)) $alerts[]=$this->a('queue_failed','warning','Fehlgeschlagene Jobs vorhanden.');
        $disk=$metrics['system']['disk_free_percent']??null;
        if(is_numeric($disk)&&(float)$disk<(float)($this->thresholds['disk_free_percent_warning']??10)) $alerts[]=$this->a('disk_low','critical','Freier Festplattenspeicher ist niedrig.');
        $mem=$metrics['php']['memory_percent']??null;
        if(is_numeric($mem)&&(float)$mem>(float)($this->thresholds['memory_percent_warning']??90)) $alerts[]=$this->a('memory_high','warning','PHP-Speicherauslastung ist hoch.');
        foreach($heartbeats as $hb){
            $age=$now-(int)($hb['timestamp']??0); $name=(string)($hb['name']??'worker');
            $limit=str_contains(strtolower($name),'scheduler')?(int)($this->thresholds['scheduler_stale_seconds']??180):(int)($this->thresholds['worker_stale_seconds']??180);
            if($age>$limit) $alerts[]=$this->a('heartbeat_stale','warning',"Heartbeat {$name} ist veraltet.",['age_seconds'=>$age]);
        }
        if($alerts!==[]) $this->log($alerts);
        return $alerts;
    }
    private function a(string $code,string $level,string $message,array $context=[]): array{return ['code'=>$code,'level'=>$level,'message'=>$message,'context'=>$context];}
    private function log(array $alerts): void
    {
        $dir=dirname($this->logFile); if(!is_dir($dir)) @mkdir($dir,0775,true);
        foreach($alerts as $a) @file_put_contents($this->logFile,json_encode(['time'=>date(DATE_ATOM)]+$a,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
}
