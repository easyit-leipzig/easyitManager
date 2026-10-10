<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

use DataForm5\Scheduler\Contracts\MutexInterface;

final class ClusterLock
{
    public function __construct(private readonly MutexInterface $mutex) {}

    public function acquire(string $name,int $ttl=60): bool
    {
        return $this->mutex->acquire('cluster:'.$name,$ttl);
    }

    public function release(string $name): void
    {
        $this->mutex->release('cluster:'.$name);
    }

    public function synchronized(string $name,callable $callback,int $ttl=60): mixed
    {
        if(!$this->acquire($name,$ttl)) throw new \RuntimeException("Cluster-Lock '{$name}' ist bereits belegt.");
        try{return $callback();}finally{$this->release($name);}
    }
}
