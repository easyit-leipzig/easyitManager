<?php
declare(strict_types=1);
namespace DataForm5\Observability\Contracts;
interface MetricStoreInterface
{
    public function increment(string $name, float $value=1.0, array $tags=[]): void;
    public function gauge(string $name, float $value, array $tags=[]): void;
    public function observe(string $name, float $value, array $tags=[]): void;
    public function snapshot(): array;
    public function clear(): void;
    public function healthy(): bool;
}
