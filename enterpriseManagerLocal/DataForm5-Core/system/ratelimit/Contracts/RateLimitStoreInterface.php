<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Contracts;
interface RateLimitStoreInterface
{
    public function read(string $key): ?array;
    public function write(string $key, array $state): void;
    public function delete(string $key): void;
    public function healthy(): bool;
}
