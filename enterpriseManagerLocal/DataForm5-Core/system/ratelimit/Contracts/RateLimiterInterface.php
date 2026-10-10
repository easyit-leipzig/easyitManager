<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Contracts;
use DataForm5\RateLimit\Core\RateLimitResult;
interface RateLimiterInterface
{
    public function attempt(string $key, int $limit, int $windowSeconds, int $cost = 1): RateLimitResult;
    public function inspect(string $key, int $limit, int $windowSeconds): RateLimitResult;
    public function clear(string $key): void;
    public function quota(string $key, int $limit, int $periodSeconds, int $cost = 1): RateLimitResult;
}
