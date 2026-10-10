<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Core;
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $limit,
        public readonly int $remaining,
        public readonly int $used,
        public readonly int $resetAt,
        public readonly int $retryAfter
    ) {}
    public function toArray(): array
    {
        return [
            'allowed'=>$this->allowed,'limit'=>$this->limit,'remaining'=>$this->remaining,
            'used'=>$this->used,'reset_at'=>$this->resetAt,'retry_after'=>$this->retryAfter,
        ];
    }
    public function headers(): array
    {
        $headers=['X-RateLimit-Limit'=>(string)$this->limit,'X-RateLimit-Remaining'=>(string)$this->remaining,'X-RateLimit-Reset'=>(string)$this->resetAt];
        if(!$this->allowed)$headers['Retry-After']=(string)$this->retryAfter;
        return $headers;
    }
}
