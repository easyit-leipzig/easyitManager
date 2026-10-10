<?php
declare(strict_types=1);
namespace DataForm5\Secrets\Contracts;
interface SecretStoreInterface
{
    public function has(string $name): bool;
    public function get(string $name, ?string $default = null): ?string;
    public function set(string $name, string $value): void;
    public function delete(string $name): void;
    /** @return list<string> */
    public function names(): array;
}
