<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

final class CoreDatabaseBridge implements DataFormDatabaseInterface
{
    private const REQUIRED_METHODS = ['connect', 'tables', 'query', 'insert', 'update', 'delete'];

    public function __construct(private object $coreDatabase)
    {
        foreach (self::REQUIRED_METHODS as $method) {
            if (!is_callable([$coreDatabase, $method])) {
                throw new \InvalidArgumentException('Core database object does not satisfy DataForm contract; missing method: ' . $method);
            }
        }
    }

    public function connect(): bool
    {
        return (bool) $this->coreDatabase->connect();
    }

    public function tables(): array
    {
        $tables = $this->coreDatabase->tables();
        return is_array($tables) ? $tables : [];
    }

    public function query(string $query, array $params = []): mixed
    {
        return $this->coreDatabase->query($query, $params);
    }

    public function insert(string $table, array $data): mixed
    {
        return $this->coreDatabase->insert($table, $data);
    }

    public function update(string $table, array $data, array $where): bool
    {
        return (bool) $this->coreDatabase->update($table, $data, $where);
    }

    public function delete(string $table, array $where): bool
    {
        return (bool) $this->coreDatabase->delete($table, $where);
    }

    public function getCoreDatabase(): object
    {
        return $this->coreDatabase;
    }
}
