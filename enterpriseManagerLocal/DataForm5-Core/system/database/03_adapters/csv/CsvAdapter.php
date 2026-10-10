<?php
declare(strict_types=1);

namespace DataForm\Database\Csv;

use DataForm\Database\Contracts\RelationalDatabaseInterface;
use DataForm\Database\DatabaseException;

require_once __DIR__ . '/CsvDatabase.php';

final class CsvAdapter implements RelationalDatabaseInterface
{
    private array $config;
    private ?\CsvDatabase $engine = null;
    /** @var array<string,string> */
    private array $transactionSnapshot = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function driver(): string
    {
        return 'csv';
    }

    public function connect(): void
    {
        if ($this->engine instanceof \CsvDatabase) {
            return;
        }
        $basePath = trim((string)($this->config['base_path'] ?? ''));
        if ($basePath === '') {
            $basePath = dirname(__DIR__, 3) . '/storage/csv';
        }
        $database = trim((string)($this->config['database'] ?? 'default'));
        if ($database === '') {
            throw new DatabaseException('CSV-Datenbankname fehlt.');
        }
        $this->engine = new \CsvDatabase($basePath, $database);
    }

    public function disconnect(): void
    {
        if ($this->transactionSnapshot !== []) {
            throw new DatabaseException('CSV-Verbindung kann waehrend einer aktiven Transaktion nicht getrennt werden.');
        }
        $this->engine = null;
    }

    public function isConnected(): bool
    {
        return $this->engine instanceof \CsvDatabase;
    }

    private function engine(): \CsvDatabase
    {
        $this->connect();
        return $this->engine;
    }

    public function databasePath(): string
    {
        return $this->engine()->getDatabasePath();
    }

    /** @return list<string> */
    public function listTables(): array
    {
        return $this->engine()->listTables();
    }

    public function tableExists(string $table): bool
    {
        return $this->engine()->tableExists($table);
    }

    public function createTable(string $table, array $columns): void
    {
        $this->engine()->createTable($table, $columns);
    }

    public function dropTable(string $table, bool $dropRelations = false): void
    {
        $this->engine()->dropTable($table, $dropRelations);
    }

    public function columns(string $table): array
    {
        return $this->engine()->columns($table);
    }

    public function addColumn(string $table, string $column, mixed $default = ''): void
    {
        $this->engine()->addColumn($table, $column, $default);
    }

    public function renameColumn(string $table, string $column, string $newName): void
    {
        $this->engine()->renameColumn($table, $column, $newName);
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->engine()->dropColumn($table, $column);
    }

    public function all(string $table): array
    {
        return $this->engine()->all($table);
    }

    public function find(string $table, int $id): ?array
    {
        return $this->engine()->find($table, $id);
    }

    public function where(string $table, array $criteria): array
    {
        return $this->engine()->where($table, $criteria);
    }

    public function insert(string $table, array $data): int
    {
        return $this->engine()->insert($table, $data);
    }

    public function insertWithId(string $table, int $id, array $data): int
    {
        return $this->engine()->insertWithId($table, $id, $data);
    }

    public function update(string $table, int $id, array $data): bool
    {
        return $this->engine()->update($table, $id, $data);
    }

    public function delete(string $table, int $id): bool
    {
        return $this->engine()->delete($table, $id);
    }

    public function createOneToManyRelation(
        string $parentTable,
        string $childTable,
        string $foreignKey,
        string $onDelete = 'RESTRICT'
    ): void {
        $this->engine()->createOneToManyRelation($parentTable, $childTable, $foreignKey, $onDelete);
    }

    public function createManyToManyRelation(
        string $leftTable,
        string $rightTable,
        ?string $pivotTable = null,
        ?string $leftKey = null,
        ?string $rightKey = null,
        string $onDelete = 'CASCADE'
    ): void {
        $this->engine()->createManyToManyRelation(
            $leftTable,
            $rightTable,
            $pivotTable,
            $leftKey,
            $rightKey,
            $onDelete
        );
    }

    public function attach(
        string $leftTable,
        int $leftId,
        string $rightTable,
        int $rightId,
        ?string $pivotTable = null
    ): int {
        return $this->engine()->attach($leftTable, $leftId, $rightTable, $rightId, $pivotTable);
    }

    public function detach(
        string $leftTable,
        int $leftId,
        string $rightTable,
        int $rightId,
        ?string $pivotTable = null
    ): bool {
        return $this->engine()->detach($leftTable, $leftId, $rightTable, $rightId, $pivotTable);
    }

    public function sync(
        string $leftTable,
        int $leftId,
        string $rightTable,
        array $rightIds,
        ?string $pivotTable = null
    ): void {
        $this->engine()->sync($leftTable, $leftId, $rightTable, $rightIds, $pivotTable);
    }

    public function related(
        string $sourceTable,
        int $sourceId,
        string $targetTable,
        ?string $pivotTable = null
    ): array {
        return $this->engine()->related($sourceTable, $sourceId, $targetTable, $pivotTable);
    }

    public function beginTransaction(): void
    {
        if ($this->transactionSnapshot !== []) {
            throw new DatabaseException('CSV-Transaktion ist bereits aktiv.');
        }

        $path = $this->engine()->getDatabasePath();
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $content = file_get_contents($file);
            if ($content === false) {
                throw new DatabaseException('CSV-Transaktionssnapshot konnte nicht gelesen werden: ' . $file);
            }
            $this->transactionSnapshot[basename($file)] = $content;
        }
        // Leere Datenbanken benoetigen trotzdem einen aktiven Marker.
        if ($this->transactionSnapshot === []) {
            $this->transactionSnapshot['.__easyit_empty_transaction__'] = '';
        }
    }

    public function commit(): void
    {
        $this->transactionSnapshot = [];
    }

    public function rollBack(): void
    {
        if ($this->transactionSnapshot === []) {
            return;
        }

        $snapshot = $this->transactionSnapshot;
        $this->transactionSnapshot = [];
        unset($snapshot['.__easyit_empty_transaction__']);

        $path = $this->engine()->getDatabasePath();
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new DatabaseException('CSV-Rollback konnte Datei nicht entfernen: ' . $file);
            }
        }
        foreach ($snapshot as $name => $content) {
            if (file_put_contents($path . DIRECTORY_SEPARATOR . $name, $content, LOCK_EX) === false) {
                throw new DatabaseException('CSV-Rollback konnte Datei nicht wiederherstellen: ' . $name);
            }
        }
        // _relations.json muss auch in einer zuvor leeren DB existieren.
        if (!is_file($path . DIRECTORY_SEPARATOR . '_relations.json')) {
            file_put_contents($path . DIRECTORY_SEPARATOR . '_relations.json', "[]\n", LOCK_EX);
        }
    }
}
