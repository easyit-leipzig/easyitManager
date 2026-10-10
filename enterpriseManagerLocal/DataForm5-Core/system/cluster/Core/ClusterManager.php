<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

final class ClusterManager
{
    public function __construct(
        private readonly ClusterHeartbeat $heartbeat,
        private readonly ClusterHealth $health,
        private readonly ClusterRegistry $registry
    ) {}

    public function heartbeat(array $meta=[]): ClusterNode { return $this->heartbeat->beat($meta); }
    public function health(): array { return $this->health->snapshot(); }
    public function removeNode(string $nodeId): bool { return $this->registry->remove($nodeId); }
}
