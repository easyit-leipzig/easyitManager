<?php
declare(strict_types=1);
namespace DataForm5\Queue\Contracts;
use DataForm5\Queue\Core\JobEnvelope;
interface QueueInterface
{
    public function push(JobInterface $job, int $delay = 0): string;
    public function pop(): ?JobEnvelope;
    public function release(JobEnvelope $envelope, int $delay): void;
    public function acknowledge(JobEnvelope $envelope): void;
    public function fail(JobEnvelope $envelope, \Throwable $error): void;
    public function size(): int;
    public function clear(): void;
}
