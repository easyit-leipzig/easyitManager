<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

/**
 * Stable DataForm-side database contract. Existing Enterprise/DataForm5 core
 * database drivers can be wrapped by CoreDatabaseBridge without replacing
 * their concrete implementation.
 */
interface DataFormDatabaseInterface
{
    public function connect(): bool;
    public function tables(): array;
    public function query(string $query, array $params = []): mixed;
    public function insert(string $table, array $data): mixed;
    public function update(string $table, array $data, array $where): bool;
    public function delete(string $table, array $where): bool;
}
