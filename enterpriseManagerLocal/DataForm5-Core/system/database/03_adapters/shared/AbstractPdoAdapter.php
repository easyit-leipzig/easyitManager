<?php
declare(strict_types=1);

namespace DataForm\Database\Pdo;

use DataForm\Database\Contracts\DatabaseInterface;
use DataForm\Database\DatabaseException;
use PDO;
use PDOException;

abstract class AbstractPdoAdapter implements DatabaseInterface
{
    protected ?PDO $pdo = null;
    protected array $config;

    public function __construct(array $config) { $this->config = $config; }
    abstract protected function dsn(): string;
    abstract protected function identifierQuote(): string;

    public function connect(): void
    {
        if ($this->pdo instanceof PDO) return;
        try {
            $this->pdo = new PDO(
                $this->dsn(),
                $this->config['username'] ?? null,
                $this->config['password'] ?? null,
                $this->config['options'] ?? []
            );
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new DatabaseException('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e);
        }
    }

    public function disconnect(): void { $this->pdo = null; }
    public function isConnected(): bool { return $this->pdo instanceof PDO; }
    protected function connection(): PDO { $this->connect(); return $this->pdo; }

    protected function qi(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new DatabaseException("Ungültiger SQL-Bezeichner: {$identifier}");
        }
        $q = $this->identifierQuote();
        return $q . $identifier . $q;
    }

    public function all(string $table): array
    {
        return $this->connection()->query('SELECT * FROM ' . $this->qi($table) . ' ORDER BY id')->fetchAll();
    }

    public function find(string $table, int $id): ?array
    {
        $stmt = $this->connection()->prepare('SELECT * FROM ' . $this->qi($table) . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function where(string $table, array $criteria): array
    {
        if ($criteria === []) return $this->all($table);
        $parts = []; $params = [];
        foreach ($criteria as $column => $value) {
            $parts[] = $this->qi((string)$column) . ' = :' . $column;
            $params[(string)$column] = $value;
        }
        $stmt = $this->connection()->prepare('SELECT * FROM ' . $this->qi($table) . ' WHERE ' . implode(' AND ', $parts) . ' ORDER BY id');
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function insert(string $table, array $data): int
    {
        unset($data['id']);
        if ($data === []) throw new DatabaseException('Keine Daten zum Einfügen angegeben.');
        $columns = array_keys($data);
        $sql = 'INSERT INTO ' . $this->qi($table) . ' (' . implode(', ', array_map(fn($c) => $this->qi((string)$c), $columns)) . ') VALUES (' . implode(', ', array_map(fn($c) => ':' . $c, $columns)) . ')';
        $stmt = $this->connection()->prepare($sql);
        $stmt->execute($data);
        return (int)$this->connection()->lastInsertId();
    }

    public function update(string $table, int $id, array $data): bool
    {
        unset($data['id']);
        if ($data === []) return false;
        $parts = [];
        foreach (array_keys($data) as $column) $parts[] = $this->qi((string)$column) . ' = :' . $column;
        $data['__id'] = $id;
        $stmt = $this->connection()->prepare('UPDATE ' . $this->qi($table) . ' SET ' . implode(', ', $parts) . ' WHERE id = :__id');
        $stmt->execute($data);
        return $stmt->rowCount() > 0;
    }

    public function delete(string $table, int $id): bool
    {
        $stmt = $this->connection()->prepare('DELETE FROM ' . $this->qi($table) . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function beginTransaction(): void { if (!$this->connection()->beginTransaction()) throw new DatabaseException('Transaktion konnte nicht gestartet werden.'); }
    public function commit(): void { if (!$this->connection()->commit()) throw new DatabaseException('Commit fehlgeschlagen.'); }
    public function rollBack(): void { if ($this->connection()->inTransaction() && !$this->connection()->rollBack()) throw new DatabaseException('Rollback fehlgeschlagen.'); }
}
