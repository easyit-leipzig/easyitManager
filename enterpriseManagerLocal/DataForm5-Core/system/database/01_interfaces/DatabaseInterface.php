<?php
declare(strict_types=1);

namespace DataForm\Database\Contracts;

interface DatabaseInterface
{
    public function driver(): string;
    public function connect(): void;
    public function disconnect(): void;
    public function isConnected(): bool;

    public function tableExists(string $table): bool;
    public function createTable(string $table, array $columns): void;
    public function columns(string $table): array;

    public function all(string $table): array;
    public function find(string $table, int $id): ?array;
    public function where(string $table, array $criteria): array;
    public function insert(string $table, array $data): int;
    public function update(string $table, int $id, array $data): bool;
    public function delete(string $table, int $id): bool;

    public function beginTransaction(): void;
    public function commit(): void;
    public function rollBack(): void;
}
