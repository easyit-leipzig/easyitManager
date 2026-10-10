<?php
declare(strict_types=1);

namespace DataForm\Database\Sqlite;

use DataForm\Database\Pdo\AbstractPdoAdapter;

final class SqliteAdapter extends AbstractPdoAdapter
{
    public function driver(): string { return 'sqlite'; }
    protected function identifierQuote(): string { return '"'; }
    protected function dsn(): string { return 'sqlite:' . ($this->config['path'] ?? ':memory:'); }
    public function connect(): void { parent::connect(); $this->connection()->exec('PRAGMA foreign_keys = ON'); }
    public function tableExists(string $table): bool
    {
        $stmt = $this->connection()->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:table");
        $stmt->execute(['table' => $table]); return (int)$stmt->fetchColumn() > 0;
    }
    public function columns(string $table): array
    {
        $rows = $this->connection()->query('PRAGMA table_info(' . $this->qi($table) . ')')->fetchAll();
        return array_column($rows, 'name');
    }
    public function createTable(string $table, array $columns): void
    {
        $defs = ['"id" INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach ($columns as $column) { if ($column === 'id') continue; $defs[] = $this->qi((string)$column) . ' TEXT NULL'; }
        $this->connection()->exec('CREATE TABLE ' . $this->qi($table) . ' (' . implode(', ', $defs) . ')');
    }
}
