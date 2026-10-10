<?php
declare(strict_types=1);
namespace DataForm5\Security\Contracts;
interface SessionStoreInterface {
    public function start(): void;
    public function get(string $key, mixed $default = null): mixed;
    public function put(string $key, mixed $value): void;
    public function forget(string $key): void;
    public function regenerate(): void;
    public function invalidate(): void;
}
