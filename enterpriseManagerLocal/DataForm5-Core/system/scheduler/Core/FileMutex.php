<?php
declare(strict_types=1);
namespace DataForm5\Scheduler\Core;
use DataForm5\Scheduler\Exceptions\SchedulerException;
use DataForm5\Scheduler\Contracts\MutexInterface;
final class FileMutex implements MutexInterface
{
    public function __construct(private readonly string $directory){if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory))throw new SchedulerException("Lock-Verzeichnis kann nicht erstellt werden: {$directory}");}
    public function acquire(string $name,int $ttl):bool
    {
        $file=$this->file($name); if(is_file($file)){ $age=time()-(int)filemtime($file); if($age<$ttl)return false; @unlink($file); }
        $handle=@fopen($file,'x'); if($handle===false)return false; fwrite($handle,(string)getmypid()); fclose($handle); return true;
    }
    public function release(string $name):void{@unlink($this->file($name));}
    private function file(string $name):string{return rtrim($this->directory,'/\\').'/'.hash('sha256',$name).'.lock';}
}
