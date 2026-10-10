<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Core;
use DataForm5\Secrets\Exceptions\SecretException;
final class KeyRing
{
    /** @var array<string,string> */ private array $keys = [];
    /** @param array<string,string> $keys */
    public function __construct(private string $activeKeyId, array $keys)
    {
        foreach ($keys as $id=>$key) {
            $id=trim((string)$id); if($id==='') continue;
            $this->keys[$id]=$this->normalizeKey((string)$key);
        }
        if(!isset($this->keys[$activeKeyId])) throw new SecretException("Aktiver Schlüssel '{$activeKeyId}' fehlt.");
    }
    public function activeKeyId():string{return $this->activeKeyId;}
    public function activeKey():string{return $this->keys[$this->activeKeyId];}
    public function key(string $id):string{if(!isset($this->keys[$id]))throw new SecretException("Schlüssel '{$id}' ist nicht verfügbar.");return $this->keys[$id];}
    /** @return list<string> */ public function ids():array{return array_keys($this->keys);}
    private function normalizeKey(string $key):string
    {
        $key=trim($key);
        if(str_starts_with($key,'base64:')){$decoded=base64_decode(substr($key,7),true);if($decoded===false)throw new SecretException('Ungültiger Base64-Schlüssel.');$key=$decoded;}
        if(strlen($key)<32) throw new SecretException('Ein Schlüssel muss mindestens 32 Byte besitzen.');
        return hash('sha256',$key,true);
    }
}
