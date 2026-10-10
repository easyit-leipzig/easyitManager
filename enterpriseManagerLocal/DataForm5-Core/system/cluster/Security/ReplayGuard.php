<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Security;

final class ReplayGuard
{
    public function __construct(private readonly string $file,private readonly int $ttl=120) {}

    public function accept(string $nodeId,string $nonce,int $timestamp): bool
    {
        $now=time();
        if($nonce===''||abs($now-$timestamp)>max(1,$this->ttl)) return false;
        $data=$this->load();
        $cutoff=$now-max(1,$this->ttl)*2;
        foreach($data as $key=>$seenAt) if((int)$seenAt<$cutoff) unset($data[$key]);

        $key=hash('sha256',$nodeId.'|'.$nonce);
        if(isset($data[$key])) return false;
        $data[$key]=$now;
        $this->save($data);
        return true;
    }

    private function load(): array
    {
        if(!is_file($this->file)) return [];
        $data=json_decode((string)@file_get_contents($this->file),true);
        return is_array($data)?$data:[];
    }

    private function save(array $data): void
    {
        $dir=dirname($this->file);
        if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)) throw new \RuntimeException('Replay-Store-Verzeichnis nicht verfügbar.');
        $tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));
        $json=json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false||!@rename($tmp,$this->file)){
            @unlink($tmp); throw new \RuntimeException('Replay Store konnte nicht gespeichert werden.');
        }
    }
}
