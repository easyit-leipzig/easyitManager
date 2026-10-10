<?php
declare(strict_types=1);

namespace DataForm5\Replication\Drivers;

use DataForm5\Core\Filesystem\Contracts\StorageDriverInterface;
use DataForm5\Replication\Contracts\ReplicationTransportInterface;
use DataForm5\Replication\Core\ReplicationEvent;

final class SharedStorageReplicationTransport implements ReplicationTransportInterface
{
    public function __construct(
        private readonly StorageDriverInterface $storage,
        private readonly string $basePath='replication'
    ) {}

    public function publish(ReplicationEvent $event): void
    {
        $dir=trim($this->basePath,'/').'/'.rawurlencode($event->channel);
        $file=$dir.'/'.$event->createdAt.'-'.$event->id.'.json';
        $safe=str_replace([':', '+'], ['-', '_'], $file);
        $this->storage->write($safe,json_encode($event->toArray(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
    }

    public function consume(string $channel,string $afterId='',int $limit=100): array
    {
        $dir=trim($this->basePath,'/').'/'.rawurlencode($channel);
        $files=$this->storage->files($dir,false);
        sort($files,SORT_STRING);
        $events=[];
        $seenAfter=$afterId==='';
        foreach($files as $file){
            if(!str_ends_with($file,'.json')) continue;
            $row=json_decode($this->storage->read($this->relative($file)),true);
            if(!is_array($row)) continue;
            $event=ReplicationEvent::fromArray($row);
            if(!$event->verify()) continue;
            if(!$seenAfter){
                if($event->id===$afterId) $seenAfter=true;
                continue;
            }
            if($event->id===$afterId) continue;
            $events[]=$event;
            if(count($events)>=max(1,$limit)) break;
        }
        return $events;
    }

    public function health(): array
    {
        return ['driver'=>'shared-storage','storage'=>$this->storage->health(),'base_path'=>$this->basePath];
    }

    private function relative(string $path): string
    {
        $root=rtrim(str_replace('\\','/',$this->storage->root()),'/');
        $path=str_replace('\\','/',$path);
        return str_starts_with($path,$root.'/')?substr($path,strlen($root)+1):$path;
    }
}
