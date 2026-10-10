<?php
declare(strict_types=1);

namespace DataForm\Database\MySql;

use DataForm\Database\Pdo\AbstractPdoAdapter;

final class MySqlAdapter extends AbstractPdoAdapter
{
    public function driver(): string { return 'mysql'; }
    protected function identifierQuote(): string { return '`'; }
    protected function dsn(): string
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = (int)($this->config['port'] ?? 3306);
        $db = $this->config['database'] ?? '';
        $charset = $this->config['charset'] ?? 'utf8mb4';
        return "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
    }
    public function tableExists(string $table): bool
    {
        $stmt = $this->connection()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
        $stmt->execute(['table' => $table]); return (int)$stmt->fetchColumn() > 0;
    }
    public function columns(string $table): array
    {
        $stmt = $this->connection()->query('SHOW COLUMNS FROM ' . $this->qi($table));
        return array_column($stmt->fetchAll(), 'Field');
    }
    public function createTable(string $table, array $columns): void
    {
        $defs = ['`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'];
        foreach ($columns as $column) { if ($column === 'id') continue; $defs[] = $this->qi((string)$column) . ' TEXT NULL'; }
        $this->connection()->exec('CREATE TABLE ' . $this->qi($table) . ' (' . implode(', ', $defs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
}
