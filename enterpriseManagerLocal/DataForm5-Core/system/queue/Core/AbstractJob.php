<?php
declare(strict_types=1);
namespace DataForm5\Queue\Core;
use DataForm5\Queue\Contracts\JobInterface;
abstract class AbstractJob implements JobInterface
{
    protected array $data = [];
    public function __construct(array $data = []) { $this->data = $data; }
    public function payload(): array { return $this->data; }
    public function restore(array $payload): void { $this->data = $payload; }
    public function maxAttempts(): int { return 3; }
    public function retryDelay(): int { return 5; }
}
