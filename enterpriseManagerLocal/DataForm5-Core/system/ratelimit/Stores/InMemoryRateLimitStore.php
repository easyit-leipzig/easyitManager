<?php
declare(strict_types=1);
namespace DataForm5\RateLimit\Stores;
use DataForm5\RateLimit\Contracts\RateLimitStoreInterface;
final class InMemoryRateLimitStore implements RateLimitStoreInterface
{
    private array $items=[];
    public function read(string $key):?array{return $this->items[$key]??null;}
    public function write(string $key,array $state):void{$this->items[$key]=$state;}
    public function delete(string $key):void{unset($this->items[$key]);}
    public function healthy():bool{return true;}
}
