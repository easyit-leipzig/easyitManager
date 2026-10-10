<?php
declare(strict_types=1);
namespace DataForm5\Security\Session;
use DataForm5\Security\Contracts\SessionStoreInterface;
final class ArraySessionStore implements SessionStoreInterface {
    private array $data = [];
    public function start(): void {}
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function put(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function regenerate(): void {}
    public function invalidate(): void { $this->data = []; }
}
