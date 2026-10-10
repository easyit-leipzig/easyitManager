<?php
declare(strict_types=1);

namespace DataForm\Database\Core;

use DataForm\Database\Contracts\DatabaseInterface;

final class QueryBuilder
{
    private array $criteria = [];
    public function __construct(private readonly DatabaseInterface $database, private readonly string $table) {}
    public function where(string $column, mixed $value): self { $clone = clone $this; $clone->criteria[$column] = $value; return $clone; }
    public function get(): array { return $this->criteria === [] ? $this->database->all($this->table) : $this->database->where($this->table, $this->criteria); }
    public function find(int $id): ?array { return $this->database->find($this->table, $id); }
    public function insert(array $data): int { return $this->database->insert($this->table, $data); }
    public function update(int $id, array $data): bool { return $this->database->update($this->table, $id, $data); }
    public function delete(int $id): bool { return $this->database->delete($this->table, $id); }
}
