<?php
declare(strict_types=1);
namespace DataForm5\Queue\Drivers;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Queue\Contracts\JobInterface;
use DataForm5\Queue\Contracts\QueueInterface;
use DataForm5\Queue\Core\JobEnvelope;
final class SyncQueue implements QueueInterface
{
    public function __construct(private readonly ServiceContainer $container) {}
    public function push(JobInterface $job, int $delay = 0): string
    {
        $id = bin2hex(random_bytes(16));
        if ($delay > 0) { usleep($delay * 1000000); }
        $job->handle($this->container);
        return $id;
    }
    public function pop(): ?JobEnvelope { return null; }
    public function release(JobEnvelope $envelope, int $delay): void { $envelope->job->handle($this->container); }
    public function acknowledge(JobEnvelope $envelope): void {}
    public function fail(JobEnvelope $envelope, \Throwable $error): void { throw $error; }
    public function size(): int { return 0; }
    public function clear(): void {}
}
