<?php
declare(strict_types=1);
namespace DataForm5\Queue\Contracts;
use DataForm5\Core\Container\ServiceContainer;
interface JobInterface
{
    public function handle(ServiceContainer $container): void;
    public function payload(): array;
    public function restore(array $payload): void;
    public function maxAttempts(): int;
    public function retryDelay(): int;
}
