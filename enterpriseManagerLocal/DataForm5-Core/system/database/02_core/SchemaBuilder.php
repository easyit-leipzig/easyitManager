<?php
declare(strict_types=1);

namespace DataForm\Database\Core;

use DataForm\Database\Contracts\DatabaseInterface;

final class SchemaBuilder
{
    public function __construct(private readonly DatabaseInterface $database) {}

    public function hasTable(string $table): bool
    {
        return $this->database->tableExists($table);
    }

    public function create(string $table, array $columns): void
    {
        if (!$this->database->tableExists($table)) {
            $this->database->createTable($table, $columns);
        }
    }

    public function columns(string $table): array
    {
        return $this->database->columns($table);
    }
}
