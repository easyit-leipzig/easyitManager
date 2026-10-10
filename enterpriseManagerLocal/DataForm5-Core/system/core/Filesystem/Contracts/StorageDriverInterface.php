<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem\Contracts;

interface StorageDriverInterface
{
    public function name(): string;
    public function root(): string;
    public function exists(string $path): bool;
    public function read(string $path): string;
    public function write(string $path,string $content,bool $atomic=true): int;
    public function append(string $path,string $content): int;
    public function delete(string $path): void;
    public function copy(string $source,string $destination): void;
    public function move(string $source,string $destination): void;
    public function files(string $directory='',bool $recursive=false): array;
    public function health(): array;
}
