<?php
declare(strict_types=1);
namespace DataForm5\Cache\Contracts;
interface CacheInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): bool;
    public function has(string $key): bool;
    public function delete(string $key): bool;
    public function clear(): bool;
    public function remember(string $key, ?int $ttl, callable $resolver): mixed;
}
