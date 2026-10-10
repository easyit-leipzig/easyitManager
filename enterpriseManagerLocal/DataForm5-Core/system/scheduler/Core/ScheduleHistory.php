<?php
declare(strict_types=1);
namespace DataForm5\Scheduler\Core;
final class ScheduleHistory
{
    public function __construct(private readonly string $directory){if(!is_dir($directory))mkdir($directory,0775,true);}
    public function record(string $task,string $status,\DateTimeInterface $started,?\Throwable $error=null):void
    {
        $row=['task'=>$task,'status'=>$status,'started_at'=>$started->format(DATE_ATOM),'finished_at'=>(new \DateTimeImmutable())->format(DATE_ATOM)];
        if($error)$row['error']=['class'=>$error::class,'message'=>$error->getMessage()];
        file_put_contents(rtrim($this->directory,'/\\').'/'.date('Y-m-d').'.log',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
    public function recent(int $limit=100): array
    {
        $files=glob(rtrim($this->directory,'/\\').'/*.log') ?: [];
        rsort($files,SORT_STRING);
        $rows=[];
        foreach($files as $file){
            $lines=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
            for($i=count($lines)-1;$i>=0;$i--){
                $row=json_decode($lines[$i],true);
                if(is_array($row)){$rows[]=$row;if(count($rows)>=max(1,$limit)) return $rows;}
            }
        }
        return $rows;
    }
}
