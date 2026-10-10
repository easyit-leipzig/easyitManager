<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Security;

final class NodeTrustStore
{
    public function __construct(private readonly string $file) {}

    public function all(): array
    {
        if(!is_file($this->file)) return [];
        $data=json_decode((string)@file_get_contents($this->file),true);
        return is_array($data)?$data:[];
    }

    public function trust(string $nodeId,string $secret,string $label=''): void
    {
        if($nodeId===''||$secret==='') throw new \InvalidArgumentException('Node-ID und Secret sind Pflicht.');
        $data=$this->all();
        $data[$nodeId]=[
            'secret'=>$secret,
            'label'=>$label,
            'trusted_at'=>date(DATE_ATOM),
            'fingerprint'=>substr(hash('sha256',$secret),0,16),
        ];
        $this->write($data);
    }

    public function revoke(string $nodeId): bool
    {
        $data=$this->all();
        if(!isset($data[$nodeId])) return false;
        unset($data[$nodeId]);
        $this->write($data);
        return true;
    }

    public function secret(string $nodeId): ?string
    {
        $data=$this->all();
        $secret=$data[$nodeId]['secret']??null;
        return is_string($secret)&&$secret!==''?$secret:null;
    }

    public function isTrusted(string $nodeId): bool
    {
        return $this->secret($nodeId)!==null;
    }

    public function publicView(): array
    {
        $rows=[];
        foreach($this->all() as $id=>$row){
            if(!is_array($row)) continue;
            $rows[$id]=[
                'label'=>(string)($row['label']??''),
                'trusted_at'=>(string)($row['trusted_at']??''),
                'fingerprint'=>(string)($row['fingerprint']??''),
            ];
        }
        ksort($rows);
        return $rows;
    }

    private function write(array $data): void
    {
        $dir=dirname($this->file);
        if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) throw new \RuntimeException('Trust-Store-Verzeichnis nicht verfügbar.');
        $tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false||!@rename($tmp,$this->file)){
            @unlink($tmp); throw new \RuntimeException('Trust Store konnte nicht gespeichert werden.');
        }
        @chmod($this->file,0600);
    }
}
