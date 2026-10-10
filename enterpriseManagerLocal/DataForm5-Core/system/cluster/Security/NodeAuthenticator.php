<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Security;

final class NodeAuthenticator
{
    public function __construct(
        private readonly NodeTrustStore $trust,
        private readonly ReplayGuard $replay,
        private readonly bool $enabled=true
    ) {}

    public function verify(array $payload,array $security,bool $consumeNonce=true): array
    {
        if(!$this->enabled) return ['valid'=>true,'reason'=>'security_disabled'];

        $nodeId=(string)($security['node_id']??'');
        $timestamp=(int)($security['timestamp']??0);
        $nonce=(string)($security['nonce']??'');
        $signature=(string)($security['signature']??'');
        if($nodeId===''||$timestamp<=0||$nonce===''||$signature==='') return ['valid'=>false,'reason'=>'signature_fields_missing'];

        $secret=$this->trust->secret($nodeId);
        if($secret===null) return ['valid'=>false,'reason'=>'node_not_trusted'];

        $canonical=NodeSigner::canonical($payload,$nodeId,$timestamp,$nonce);
        $expected=hash_hmac('sha256',$canonical,$secret);
        if(!hash_equals($expected,$signature)) return ['valid'=>false,'reason'=>'signature_invalid'];

        if($consumeNonce && !$this->replay->accept($nodeId,$nonce,$timestamp)) return ['valid'=>false,'reason'=>'replay_or_expired'];

        return ['valid'=>true,'reason'=>'ok','node_id'=>$nodeId];
    }
}
