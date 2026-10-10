<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

use DataForm5\Cluster\Security\NodeAuthenticator;

final class ClusterHealth
{
    public function __construct(
        private readonly ClusterRegistry $registry,
        private readonly ClusterElection $election,
        private readonly int $ttl,
        private readonly ?NodeAuthenticator $authenticator=null
    ) {}

    public function snapshot(): array
    {
        $nodes=$this->registry->all();
        $leader=$this->election->leader($nodes);
        $rows=[];
        $online=0;
        foreach($nodes as $node){
            $isOnline=$node->isOnline($this->ttl);
            $security=is_array($node->meta['_security']??null)?$node->meta['_security']:[];
            $payload=$node->toArray();
            $payload['meta']=$node->meta;
            unset($payload['meta']['_security']);
            $auth=$this->authenticator?->verify($payload,$security,false) ?? ['valid'=>true,'reason'=>'not_configured'];
            $trusted=(bool)($auth['valid']??false);
            if($isOnline && $trusted) $online++;
            $rows[]=$node->toArray()+[
                'online'=>$isOnline,
                'trusted'=>$trusted,
                'security_reason'=>(string)($auth['reason']??'unknown'),
                'leader'=>$leader?->id===$node->id,
                'age_seconds'=>max(0,time()-(strtotime($node->heartbeatAt)?:time())),
            ];
        }
        return [
            'status'=>$nodes===[]?'standalone':($online===count($nodes)?'healthy':'degraded'),
            'leader'=>$leader?->toArray(),
            'node_count'=>count($nodes),
            'online_count'=>$online,
            'nodes'=>$rows,
        ];
    }
}
