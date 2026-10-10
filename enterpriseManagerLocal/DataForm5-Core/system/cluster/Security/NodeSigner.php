<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Security;

final class NodeSigner
{
    public function __construct(private readonly string $nodeId,private readonly string $secret) {}

    public function sign(array $payload): array
    {
        if($this->secret==='') throw new \RuntimeException('CLUSTER_NODE_SECRET ist nicht gesetzt.');
        $timestamp=time();
        $nonce=bin2hex(random_bytes(16));
        $canonical=self::canonical($payload,$this->nodeId,$timestamp,$nonce);
        return [
            'node_id'=>$this->nodeId,
            'timestamp'=>$timestamp,
            'nonce'=>$nonce,
            'algorithm'=>'hmac-sha256',
            'signature'=>hash_hmac('sha256',$canonical,$this->secret),
            'fingerprint'=>substr(hash('sha256',$this->secret),0,16),
        ];
    }

    public static function canonical(array $payload,string $nodeId,int $timestamp,string $nonce): string
    {
        $normalize=static function(mixed $value) use (&$normalize): mixed {
            if(!is_array($value)) return $value;
            if(array_is_list($value)) return array_map($normalize,$value);
            ksort($value,SORT_STRING);
            foreach($value as $k=>$v) $value[$k]=$normalize($v);
            return $value;
        };
        return json_encode([
            'node_id'=>$nodeId,
            'timestamp'=>$timestamp,
            'nonce'=>$nonce,
            'payload'=>$normalize($payload),
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    }
}
