<?php
declare(strict_types=1);
namespace DataForm5\Observability\Contracts;
use DataForm5\Observability\Core\Timer;
interface MetricsInterface
{
    public function increment(string $name, float $value=1.0, array $tags=[]): void;
    public function gauge(string $name, float $value, array $tags=[]): void;
    public function observe(string $name, float $value, array $tags=[]): void;
    public function timer(string $name, array $tags=[]): Timer;
    public function measure(string $name, callable $callback, array $tags=[]): mixed;
    public function snapshot(): array;
    public function clear(): void;
}
