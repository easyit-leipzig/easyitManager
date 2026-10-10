<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

final class ClusterNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $host,
        public readonly string $version,
        public readonly string $status,
        public readonly string $heartbeatAt,
        public readonly array $meta = []
    ) {}

    public function isOnline(int $ttl): bool
    {
        $ts = strtotime($this->heartbeatAt);
        return $ts !== false && (time() - $ts) <= max(1, $ttl);
    }

    public function toArray(): array
    {
        return [
            'id'=>$this->id,'name'=>$this->name,'host'=>$this->host,'version'=>$this->version,
            'status'=>$this->status,'heartbeat_at'=>$this->heartbeatAt,'meta'=>$this->meta,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string)($row['id']??''),
            (string)($row['name']??''),
            (string)($row['host']??''),
            (string)($row['version']??''),
            (string)($row['status']??'unknown'),
            (string)($row['heartbeat_at']??''),
            is_array($row['meta']??null)?$row['meta']:[]
        );
    }
}
