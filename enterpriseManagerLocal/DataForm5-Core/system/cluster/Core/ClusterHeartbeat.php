<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

use DataForm5\Cluster\Security\NodeSigner;

final class ClusterHeartbeat
{
    public function __construct(
        private readonly ClusterRegistry $registry,
        private readonly string $nodeId,
        private readonly string $nodeName,
        private readonly string $version,
        private readonly ?NodeSigner $signer=null
    ) {}

    public function beat(array $meta=[]): ClusterNode
    {
        $heartbeatAt=date(DATE_ATOM);
        $payload=[
            'id'=>$this->nodeId,
            'name'=>$this->nodeName,
            'host'=>gethostname() ?: 'localhost',
            'version'=>trim($this->version),
            'status'=>'online',
            'heartbeat_at'=>$heartbeatAt,
            'meta'=>$meta,
        ];
        if($this->signer!==null) $meta['_security']=$this->signer->sign($payload);
        $node=new ClusterNode(
            $this->nodeId,
            $this->nodeName,
            $payload['host'],
            $payload['version'],
            'online',
            $heartbeatAt,
            $meta
        );
        $this->registry->upsert($node);
        return $node;
    }
}
