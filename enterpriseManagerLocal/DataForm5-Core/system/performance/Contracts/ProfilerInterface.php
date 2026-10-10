<?php
declare(strict_types=1);
namespace DataForm5\Performance\Contracts;
interface ProfilerInterface
{
    public function start(string $name, array $tags = []): string;
    public function stop(string $token, array $metadata = []): array;
    public function measure(string $name, callable $callback, array $tags = []): mixed;
    public function mark(string $name, array $metadata = []): void;
    public function report(): array;
    public function reset(): void;
}
