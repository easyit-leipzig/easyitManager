<?php
declare(strict_types=1);

namespace DataForm5\Replication\Contracts;

use DataForm5\Replication\Core\ReplicationEvent;

interface ReplicationTransportInterface
{
    public function publish(ReplicationEvent $event): void;
    /** @return list<ReplicationEvent> */
    public function consume(string $channel,string $afterId='',int $limit=100): array;
    public function health(): array;
}
