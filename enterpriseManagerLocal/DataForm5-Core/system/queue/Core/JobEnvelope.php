<?php
declare(strict_types=1);
namespace DataForm5\Queue\Core;
use DataForm5\Queue\Contracts\JobInterface;
final class JobEnvelope
{
    public function __construct(
        public readonly string $id,
        public readonly JobInterface $job,
        public readonly int $attempts = 0,
        public readonly int $availableAt = 0,
        public readonly ?string $source = null,
    ) {}
    public function nextAttempt(int $availableAt): self
    {
        return new self($this->id, $this->job, $this->attempts + 1, $availableAt, $this->source);
    }
}
