<?php
declare(strict_types=1);

namespace DataForm5\Scheduler\Contracts;

interface MutexInterface
{
    public function acquire(string $name,int $ttl): bool;
    public function release(string $name): void;
}
