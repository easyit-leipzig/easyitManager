<?php
declare(strict_types=1);
namespace DataForm5\Queue\Drivers;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Queue\Contracts\JobInterface;
use DataForm5\Queue\Contracts\QueueInterface;
use DataForm5\Queue\Core\JobEnvelope;
use DataForm5\Queue\Exceptions\QueueException;
final class FileQueue implements QueueInterface
{
    private string $pending;
    private string $processing;
    private string $failed;
    public function __construct(private readonly ServiceContainer $container, string $basePath)
    {
        $basePath = rtrim($basePath, '/\\');
        $this->pending = $basePath . '/pending';
        $this->processing = $basePath . '/processing';
        $this->failed = $basePath . '/failed';
        foreach ([$this->pending,$this->processing,$this->failed] as $dir) if (!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new QueueException("Queue-Verzeichnis kann nicht erstellt werden: {$dir}");
    }
    public function push(JobInterface $job, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));
        $this->write($this->pending.'/'.$id.'.json', $this->record($id,$job,0,time()+max(0,$delay)));
        return $id;
    }
    public function pop(): ?JobEnvelope
    {
        $files = glob($this->pending.'/*.json') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $record = $this->read($file);
            if (($record['available_at'] ?? 0) > time()) continue;
            $target = $this->processing.'/'.basename($file);
            if (!@rename($file,$target)) continue;
            return $this->hydrate($record,$target);
        }
        return null;
    }
    public function release(JobEnvelope $envelope, int $delay): void
    {
        if ($envelope->source && is_file($envelope->source)) @unlink($envelope->source);
        $next=$envelope->nextAttempt(time()+max(0,$delay));
        $this->write($this->pending.'/'.$next->id.'.json',$this->record($next->id,$next->job,$next->attempts,$next->availableAt));
    }
    public function acknowledge(JobEnvelope $envelope): void { if ($envelope->source && is_file($envelope->source)) @unlink($envelope->source); }
    public function fail(JobEnvelope $envelope, \Throwable $error): void
    {
        if ($envelope->source && is_file($envelope->source)) @unlink($envelope->source);
        $record=$this->record($envelope->id,$envelope->job,$envelope->attempts,time());
        $record['failed_at']=date(DATE_ATOM); $record['error']=['class'=>$error::class,'message'=>$error->getMessage(),'trace'=>$error->getTraceAsString()];
        $this->write($this->failed.'/'.$envelope->id.'.json',$record);
    }
    public function size(): int { return count(glob($this->pending.'/*.json') ?: []); }
    public function clear(): void { foreach ([$this->pending,$this->processing] as $dir) foreach (glob($dir.'/*.json') ?: [] as $file) @unlink($file); }
    public function statistics(): array
    {
        return [
            'pending'=>count(glob($this->pending.'/*.json') ?: []),
            'processing'=>count(glob($this->processing.'/*.json') ?: []),
            'failed'=>count(glob($this->failed.'/*.json') ?: []),
        ];
    }
    public function failedJobs(int $limit=100): array
    {
        $files=glob($this->failed.'/*.json') ?: [];
        usort($files,static fn(string $a,string $b):int=>(filemtime($b)?:0)<=>(filemtime($a)?:0));
        $rows=[];
        foreach(array_slice($files,0,max(1,$limit)) as $file){
            try{$row=$this->read($file);$row['_file']=basename($file);$rows[]=$row;}catch(\Throwable){}
        }
        return $rows;
    }
    public function retryFailed(string $id,int $delay=0): bool
    {
        if(!preg_match('/^[a-f0-9]{32}$/i',$id)) return false;
        $file=$this->failed.'/'.$id.'.json'; if(!is_file($file)) return false;
        $record=$this->read($file);
        unset($record['failed_at'],$record['error']);
        $record['available_at']=time()+max(0,$delay);
        $record['attempts']=0;
        $target=$this->pending.'/'.$id.'.json';
        $this->write($target,$record);
        @unlink($file);
        return true;
    }
    public function deleteFailed(string $id): bool
    {
        if(!preg_match('/^[a-f0-9]{32}$/i',$id)) return false;
        $file=$this->failed.'/'.$id.'.json';
        return is_file($file) ? @unlink($file) : false;
    }
    private function record(string $id,JobInterface $job,int $attempts,int $availableAt):array{return ['id'=>$id,'job'=>$job::class,'payload'=>$job->payload(),'attempts'=>$attempts,'available_at'=>$availableAt,'created_at'=>date(DATE_ATOM)];}
    private function hydrate(array $record,string $source):JobEnvelope
    {
        $class=(string)($record['job']??'');
        if ($class==='' || !class_exists($class)) throw new QueueException("Jobklasse '{$class}' ist nicht verfügbar.");
        $job=$this->container->get($class);
        if (!$job instanceof JobInterface) throw new QueueException("Jobklasse '{$class}' implementiert JobInterface nicht.");
        $job->restore((array)($record['payload']??[]));
        return new JobEnvelope((string)$record['id'],$job,(int)($record['attempts']??0),(int)($record['available_at']??0),$source);
    }
    private function read(string $file):array{$data=json_decode((string)file_get_contents($file),true);if(!is_array($data))throw new QueueException("Ungültiger Queue-Datensatz: {$file}");return $data;}
    private function write(string $file,array $record):void{$tmp=$file.'.tmp.'.bin2hex(random_bytes(4));$json=json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$file))throw new QueueException("Queue-Datensatz kann nicht geschrieben werden: {$file}");}
}
