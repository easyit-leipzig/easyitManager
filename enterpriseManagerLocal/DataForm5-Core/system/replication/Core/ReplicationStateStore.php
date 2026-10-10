<?php
declare(strict_types=1);

namespace DataForm5\Replication\Core;

final class ReplicationStateStore
{
    public function __construct(private readonly string $file) {}

    public function get(string $channel,string $source='default'): string
    {
        $data=$this->all();
        return (string)($data[$channel][$source]??'');
    }

    public function set(string $channel,string $source,string $eventId): void
    {
        $data=$this->all();
        $data[$channel][$source]=$eventId;
        $dir=dirname($this->file);
        if(!is_dir($dir) && !@mkdir($dir,0775,true) && !is_dir($dir)) throw new \RuntimeException('Replication state dir unavailable.');
        $tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false || !@rename($tmp,$this->file)){
            @unlink($tmp); throw new \RuntimeException('Replication state could not be persisted.');
        }
    }

    public function all(): array
    {
        if(!is_file($this->file)) return [];
        $data=json_decode((string)@file_get_contents($this->file),true);
        return is_array($data)?$data:[];
    }
}
