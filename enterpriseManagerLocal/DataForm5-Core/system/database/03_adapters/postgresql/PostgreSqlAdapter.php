<?php
declare(strict_types=1);

namespace DataForm\Database\PostgreSql;

use DataForm\Database\Pdo\AbstractPdoAdapter;
use DataForm\Database\DatabaseException;

final class PostgreSqlAdapter extends AbstractPdoAdapter
{
    public function driver(): string { return 'pgsql'; }
    protected function identifierQuote(): string { return '"'; }
    protected function dsn(): string
    {
        $host=$this->config['host']??'127.0.0.1';
        $port=(int)($this->config['port']??5432);
        $db=$this->config['database']??'';
        return "pgsql:host={$host};port={$port};dbname={$db}";
    }

    public function connect(): void
    {
        if ($this->pdo instanceof \PDO) return;
        parent::connect();
        $schema=trim((string)($this->config['schema']??'public')) ?: 'public';
        if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)!==1) throw new DatabaseException('Ungültiger PostgreSQL-Schemaname.');
        $this->pdo?->exec('SET search_path TO "'.$schema.'"');
    }

    public function tableExists(string $table): bool
    {
        $stmt=$this->connection()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=:table");
        $stmt->execute(['table'=>$table]);
        return (int)$stmt->fetchColumn()>0;
    }

    public function columns(string $table): array
    {
        $stmt=$this->connection()->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=:table ORDER BY ordinal_position");
        $stmt->execute(['table'=>$table]);
        return array_column($stmt->fetchAll(),'column_name');
    }

    public function createTable(string $table,array $columns): void
    {
        $defs=['"id" BIGSERIAL PRIMARY KEY'];
        foreach($columns as $column){ if($column==='id')continue; $defs[]=$this->qi((string)$column).' TEXT NULL'; }
        $this->connection()->exec('CREATE TABLE '.$this->qi($table).' ('.implode(', ',$defs).')');
    }

    public function insert(string $table,array $data): int
    {
        unset($data['id']);
        if($data===[])throw new DatabaseException('Keine Daten zum Einfügen angegeben.');
        $columns=array_keys($data);
        $sql='INSERT INTO '.$this->qi($table).' ('.implode(', ',array_map(fn($c)=>$this->qi((string)$c),$columns)).') VALUES ('.implode(', ',array_map(fn($c)=>':'.$c,$columns)).') RETURNING id';
        $stmt=$this->connection()->prepare($sql);$stmt->execute($data);
        return (int)$stmt->fetchColumn();
    }
}
