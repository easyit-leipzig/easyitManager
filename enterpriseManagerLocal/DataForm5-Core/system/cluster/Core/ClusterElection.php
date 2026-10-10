<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

final class ClusterElection
{
    public function __construct(private readonly int $ttl=90) {}

    public function leader(array $nodes): ?ClusterNode
    {
        $online=array_filter($nodes,fn(ClusterNode $n)=>$n->isOnline($this->ttl) && $n->status!=='maintenance');
        if($online===[]) return null;
        uasort($online,fn(ClusterNode $a,ClusterNode $b)=>strcmp($a->id,$b->id));
        return reset($online) ?: null;
    }
}
