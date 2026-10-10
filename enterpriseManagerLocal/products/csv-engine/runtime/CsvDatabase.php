<?php
declare(strict_types=1);

/**
 * Dateibasierte CSV-Datenbank fuer DataForm 5.
 *
 * Vertrag:
 * - ein Verzeichnis entspricht einer Datenbank,
 * - jede Tabelle ist eine UTF-8-CSV-Datei,
 * - Trennzeichen ist |,
 * - die erste Spalte ist immer die von der Engine verwaltete positive ID,
 * - Schreiboperationen verwenden Dateisperren,
 * - 1:n- und n:m-Beziehungen werden in _relations.json verwaltet.
 */
final class CsvDatabase
{
    private const DELIMITER = '|';
    private const ENCLOSURE = '"';
    private const ESCAPE = '\\';
    private const RELATION_FILE = '_relations.json';

    private string $databasePath;

    public function __construct(string $basePath, string $databaseName)
    {
        $basePath = rtrim(trim($basePath), "/\\");
        if ($basePath === '') {
            throw new InvalidArgumentException('Der Basispfad darf nicht leer sein.');
        }

        $this->assertValidIdentifier($databaseName, 'Datenbankname');

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new RuntimeException('Der CSV-Basispfad konnte nicht erstellt werden: ' . $basePath);
        }
        if (!is_writable($basePath)) {
            throw new RuntimeException('Der CSV-Basispfad ist nicht beschreibbar: ' . $basePath);
        }

        $this->databasePath = $basePath . DIRECTORY_SEPARATOR . $databaseName;
        if (!is_dir($this->databasePath)
            && !mkdir($this->databasePath, 0775, true)
            && !is_dir($this->databasePath)) {
            throw new RuntimeException('Der CSV-Datenbankordner konnte nicht erstellt werden.');
        }
        if (!is_readable($this->databasePath) || !is_writable($this->databasePath)) {
            throw new RuntimeException('Der CSV-Datenbankordner muss lesbar und beschreibbar sein: ' . $this->databasePath);
        }

        if (!is_file($this->relationPath())) {
            $this->writeRelations([]);
        } else {
            // Fruehe Integritaetspruefung statt spaeter schwer erklaerbarer Fehler.
            $this->readRelations();
        }
    }

    public function getDatabasePath(): string
    {
        return $this->databasePath;
    }

    /** @return list<string> */
    public function listTables(): array
    {
        $tables = [];
        foreach (glob($this->databasePath . DIRECTORY_SEPARATOR . '*.csv') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $name = pathinfo($file, PATHINFO_FILENAME);
            $this->assertValidIdentifier($name, 'Tabellenname');
            $tables[] = $name;
        }
        natcasesort($tables);
        return array_values($tables);
    }

    public function createTable(string $table, array $columns): void
    {
        $path = $this->tablePath($table);
        if (is_file($path)) {
            throw new RuntimeException("Tabelle \"{$table}\" existiert bereits.");
        }

        $columns = $this->normalizeColumns($columns);
        $handle = $this->openFile($path, 'x+b');
        try {
            $this->lock($handle, LOCK_EX);
            if (!$this->writeCsvRow($handle, $columns)) {
                throw new RuntimeException('CSV-Kopfzeile konnte nicht geschrieben werden.');
            }
            fflush($handle);
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    public function dropTable(string $table, bool $dropRelations = false): void
    {
        $path = $this->requireTable($table);
        $relations = $this->readRelations();
        $affected = array_values(array_filter(
            $relations,
            static function (array $relation) use ($table): bool {
                return in_array($table, [
                    (string)($relation['parent_table'] ?? ''),
                    (string)($relation['child_table'] ?? ''),
                    (string)($relation['left_table'] ?? ''),
                    (string)($relation['right_table'] ?? ''),
                    (string)($relation['pivot_table'] ?? ''),
                ], true);
            }
        ));

        if ($affected !== [] && !$dropRelations) {
            throw new RuntimeException('Die CSV-Tabelle ist noch Bestandteil definierter Beziehungen.');
        }

        if ($dropRelations && $affected !== []) {
            $relations = array_values(array_filter(
                $relations,
                static function (array $relation) use ($table): bool {
                    return !in_array($table, [
                        (string)($relation['parent_table'] ?? ''),
                        (string)($relation['child_table'] ?? ''),
                        (string)($relation['left_table'] ?? ''),
                        (string)($relation['right_table'] ?? ''),
                        (string)($relation['pivot_table'] ?? ''),
                    ], true);
                }
            ));
            $this->writeRelations($relations);
        }

        if (!unlink($path)) {
            throw new RuntimeException('Die CSV-Tabelle konnte nicht geloescht werden.');
        }
    }

    public function tableExists(string $table): bool
    {
        return is_file($this->tablePath($table));
    }

    /** @return list<string> */
    public function columns(string $table): array
    {
        return $this->readTable($table)['columns'];
    }

    public function addColumn(string $table, string $column, mixed $default = ''): void
    {
        $this->assertValidIdentifier($column, 'Spaltenname');
        if ($column === 'id') {
            throw new InvalidArgumentException('Die Pflichtspalte id wird von der CSV-Engine verwaltet.');
        }

        $this->mutateTable($table, function (array $state) use ($column, $default): array {
            if (in_array($column, $state['columns'], true)) {
                throw new RuntimeException('Die CSV-Spalte existiert bereits.');
            }
            $state['columns'][] = $column;
            $value = $this->scalarToString($default);
            foreach ($state['records'] as &$record) {
                $record[$column] = $value;
            }
            unset($record);
            return $state;
        });
    }

    public function renameColumn(string $table, string $column, string $newName): void
    {
        $this->assertValidIdentifier($column, 'Spaltenname');
        $this->assertValidIdentifier($newName, 'Neuer Spaltenname');
        if ($column === 'id' || $newName === 'id') {
            throw new InvalidArgumentException('Die Pflichtspalte id darf nicht umbenannt werden.');
        }
        if ($column === $newName) {
            return;
        }

        $this->mutateTable($table, function (array $state) use ($column, $newName): array {
            $index = array_search($column, $state['columns'], true);
            if ($index === false) {
                throw new RuntimeException('Die CSV-Spalte wurde nicht gefunden.');
            }
            if (in_array($newName, $state['columns'], true)) {
                throw new RuntimeException('Der neue CSV-Spaltenname ist bereits vorhanden.');
            }
            $state['columns'][$index] = $newName;
            foreach ($state['records'] as &$record) {
                $record[$newName] = $record[$column] ?? '';
                unset($record[$column]);
            }
            unset($record);
            return $state;
        });

        $relations = $this->readRelations();
        $changed = false;
        foreach ($relations as &$relation) {
            if (($relation['type'] ?? '') === 'one_to_many'
                && ($relation['child_table'] ?? '') === $table
                && ($relation['foreign_key'] ?? '') === $column) {
                $relation['foreign_key'] = $newName;
                $changed = true;
            }
            if (($relation['type'] ?? '') === 'many_to_many'
                && ($relation['pivot_table'] ?? '') === $table) {
                if (($relation['left_key'] ?? '') === $column) {
                    $relation['left_key'] = $newName;
                    $changed = true;
                }
                if (($relation['right_key'] ?? '') === $column) {
                    $relation['right_key'] = $newName;
                    $changed = true;
                }
            }
        }
        unset($relation);
        if ($changed) {
            $this->writeRelations($relations);
        }
    }

    public function dropColumn(string $table, string $column): void
    {
        $this->assertValidIdentifier($column, 'Spaltenname');
        if ($column === 'id') {
            throw new InvalidArgumentException('Die Pflichtspalte id darf nicht geloescht werden.');
        }

        foreach ($this->readRelations() as $relation) {
            if (($relation['type'] ?? '') === 'one_to_many'
                && ($relation['child_table'] ?? '') === $table
                && ($relation['foreign_key'] ?? '') === $column) {
                throw new RuntimeException('Die CSV-Spalte wird als Fremdschluessel einer 1:n-Beziehung verwendet.');
            }
            if (($relation['type'] ?? '') === 'many_to_many'
                && ($relation['pivot_table'] ?? '') === $table
                && in_array($column, [
                    (string)($relation['left_key'] ?? ''),
                    (string)($relation['right_key'] ?? ''),
                ], true)) {
                throw new RuntimeException('Die CSV-Spalte wird als Fremdschluessel einer n:m-Beziehung verwendet.');
            }
        }

        $this->mutateTable($table, function (array $state) use ($column): array {
            $index = array_search($column, $state['columns'], true);
            if ($index === false) {
                throw new RuntimeException('Die CSV-Spalte wurde nicht gefunden.');
            }
            array_splice($state['columns'], $index, 1);
            foreach ($state['records'] as &$record) {
                unset($record[$column]);
            }
            unset($record);
            return $state;
        });
    }

    /** @return list<array<string,string>> */
    public function all(string $table): array
    {
        return $this->readTable($table)['records'];
    }

    public function find(string $table, int $id): ?array
    {
        $this->assertPositiveId($id);
        foreach ($this->all($table) as $row) {
            if ((int)$row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    public function where(string $table, array $criteria): array
    {
        return array_values(array_filter(
            $this->all($table),
            function (array $row) use ($criteria): bool {
                foreach ($criteria as $column => $value) {
                    if (!array_key_exists($column, $row)
                        || $row[$column] !== $this->scalarToString($value)) {
                        return false;
                    }
                }
                return true;
            }
        ));
    }

    public function insert(string $table, array $data): int
    {
        $path = $this->requireTable($table);
        $handle = $this->openFile($path, 'c+b');
        try {
            $this->lock($handle, LOCK_EX);
            $state = $this->readAllFromHandle($handle);
            unset($data['id']);
            $this->assertKnownColumns($state['columns'], $data);
            $this->validateForeignKeys($table, $data);

            $id = $this->nextId($state['records']);
            $row = ['id' => (string)$id];
            foreach ($state['columns'] as $column) {
                if ($column !== 'id') {
                    $row[$column] = $this->scalarToString($data[$column] ?? null);
                }
            }
            $state['records'][] = $row;
            $this->rewriteHandle($handle, $state['columns'], $state['records']);
            return $id;
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    /**
     * Inserts a row with an explicit positive ID. Intended for portable metadata
     * restore/import use cases such as the Enterprise CSV administration store.
     */
    public function insertWithId(string $table, int $id, array $data): int
    {
        $this->assertPositiveId($id);
        $path = $this->requireTable($table);
        $handle = $this->openFile($path, 'c+b');
        try {
            $this->lock($handle, LOCK_EX);
            $state = $this->readAllFromHandle($handle);
            unset($data['id']);
            $this->assertKnownColumns($state['columns'], $data);
            $this->validateForeignKeys($table, $data);
            foreach ($state['records'] as $existing) {
                if ((int)($existing['id'] ?? 0) === $id) {
                    throw new RuntimeException('Die CSV-ID existiert bereits: ' . $id);
                }
            }
            $row = ['id' => (string)$id];
            foreach ($state['columns'] as $column) {
                if ($column !== 'id') {
                    $row[$column] = $this->scalarToString($data[$column] ?? null);
                }
            }
            $state['records'][] = $row;
            usort($state['records'], static fn(array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
            $this->rewriteHandle($handle, $state['columns'], $state['records']);
            return $id;
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    public function update(string $table, int $id, array $data): bool
    {
        $this->assertPositiveId($id);
        unset($data['id']);
        if ($data === []) {
            return false;
        }

        $path = $this->requireTable($table);
        $handle = $this->openFile($path, 'c+b');
        try {
            $this->lock($handle, LOCK_EX);
            $state = $this->readAllFromHandle($handle);
            $this->assertKnownColumns($state['columns'], $data);
            $this->validateForeignKeys($table, $data);

            $updated = false;
            foreach ($state['records'] as &$row) {
                if ((int)$row['id'] !== $id) {
                    continue;
                }
                foreach ($data as $column => $value) {
                    $row[$column] = $this->scalarToString($value);
                }
                $updated = true;
                break;
            }
            unset($row);

            if ($updated) {
                $this->rewriteHandle($handle, $state['columns'], $state['records']);
            }
            return $updated;
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    public function delete(string $table, int $id): bool
    {
        $this->assertPositiveId($id);
        if ($this->find($table, $id) === null) {
            return false;
        }
        $this->applyDeleteRules($table, $id);
        return $this->deleteDirect($table, $id);
    }

    public function createOneToManyRelation(
        string $parentTable,
        string $childTable,
        string $foreignKey,
        string $onDelete = 'RESTRICT'
    ): void {
        $this->requireTable($parentTable);
        $this->requireTable($childTable);
        if (!in_array($foreignKey, $this->columns($childTable), true)) {
            throw new InvalidArgumentException('Fremdschluesselspalte fehlt.');
        }
        $onDelete = $this->normalizeDeleteRule($onDelete);
        $relations = $this->readRelations();
        foreach ($relations as $relation) {
            if (($relation['type'] ?? '') === 'one_to_many'
                && ($relation['parent_table'] ?? '') === $parentTable
                && ($relation['child_table'] ?? '') === $childTable
                && ($relation['foreign_key'] ?? '') === $foreignKey) {
                throw new RuntimeException('Beziehung existiert bereits.');
            }
        }
        $relations[] = [
            'type' => 'one_to_many',
            'parent_table' => $parentTable,
            'parent_key' => 'id',
            'child_table' => $childTable,
            'foreign_key' => $foreignKey,
            'on_delete' => $onDelete,
        ];
        $this->writeRelations($relations);
    }

    public function createManyToManyRelation(
        string $leftTable,
        string $rightTable,
        ?string $pivotTable = null,
        ?string $leftKey = null,
        ?string $rightKey = null,
        string $onDelete = 'CASCADE'
    ): void {
        $this->requireTable($leftTable);
        $this->requireTable($rightTable);
        $pivotTable ??= $leftTable . '_' . $rightTable;
        $leftKey ??= $leftTable . '_id';
        $rightKey ??= $rightTable . '_id';
        foreach ([$pivotTable, $leftKey, $rightKey] as $identifier) {
            $this->assertValidIdentifier($identifier, 'Bezeichner');
        }
        if ($leftKey === $rightKey) {
            throw new InvalidArgumentException('Pivot-Fremdschluessel muessen verschieden sein.');
        }

        $onDelete = $this->normalizeDeleteRule($onDelete);
        if (!$this->tableExists($pivotTable)) {
            $this->createTable($pivotTable, [$leftKey, $rightKey]);
        } else {
            $columns = $this->columns($pivotTable);
            if (!in_array($leftKey, $columns, true) || !in_array($rightKey, $columns, true)) {
                throw new RuntimeException('Vorhandene Pivot-Tabelle besitzt nicht die erwarteten Spalten.');
            }
        }

        $relations = $this->readRelations();
        foreach ($relations as $relation) {
            if (($relation['type'] ?? '') === 'many_to_many'
                && ($relation['left_table'] ?? '') === $leftTable
                && ($relation['right_table'] ?? '') === $rightTable
                && ($relation['pivot_table'] ?? '') === $pivotTable) {
                throw new RuntimeException('n:m-Beziehung existiert bereits.');
            }
        }
        $relations[] = [
            'type' => 'many_to_many',
            'left_table' => $leftTable,
            'left_key' => $leftKey,
            'right_table' => $rightTable,
            'right_key' => $rightKey,
            'pivot_table' => $pivotTable,
            'on_delete' => $onDelete,
        ];
        $this->writeRelations($relations);
    }

    public function attach(
        string $leftTable,
        int $leftId,
        string $rightTable,
        int $rightId,
        ?string $pivotTable = null
    ): int {
        $relation = $this->manyToManyRelation($leftTable, $rightTable, $pivotTable);
        $this->assertPositiveId($leftId);
        $this->assertPositiveId($rightId);
        if ($this->find($relation['left_table'], $leftId) === null
            || $this->find($relation['right_table'], $rightId) === null) {
            throw new RuntimeException('Zu verknuepfender Datensatz existiert nicht.');
        }

        $existing = $this->where($relation['pivot_table'], [
            $relation['left_key'] => $leftId,
            $relation['right_key'] => $rightId,
        ]);
        if ($existing !== []) {
            return (int)$existing[0]['id'];
        }
        return $this->insert($relation['pivot_table'], [
            $relation['left_key'] => $leftId,
            $relation['right_key'] => $rightId,
        ]);
    }

    public function detach(
        string $leftTable,
        int $leftId,
        string $rightTable,
        int $rightId,
        ?string $pivotTable = null
    ): bool {
        $relation = $this->manyToManyRelation($leftTable, $rightTable, $pivotTable);
        $rows = $this->where($relation['pivot_table'], [
            $relation['left_key'] => $leftId,
            $relation['right_key'] => $rightId,
        ]);
        $changed = false;
        foreach ($rows as $row) {
            $changed = $this->deleteDirect($relation['pivot_table'], (int)$row['id']) || $changed;
        }
        return $changed;
    }

    public function sync(
        string $leftTable,
        int $leftId,
        string $rightTable,
        array $rightIds,
        ?string $pivotTable = null
    ): void {
        $relation = $this->manyToManyRelation($leftTable, $rightTable, $pivotTable);
        $this->assertPositiveId($leftId);
        $wanted = array_values(array_unique(array_map('intval', $rightIds)));
        foreach ($wanted as $id) {
            $this->assertPositiveId($id);
        }
        $current = $this->where($relation['pivot_table'], [$relation['left_key'] => $leftId]);
        $currentIds = array_map(static fn(array $row): int => (int)$row[$relation['right_key']], $current);
        foreach (array_diff($currentIds, $wanted) as $id) {
            $this->detach($leftTable, $leftId, $rightTable, (int)$id, $relation['pivot_table']);
        }
        foreach (array_diff($wanted, $currentIds) as $id) {
            $this->attach($leftTable, $leftId, $rightTable, (int)$id, $relation['pivot_table']);
        }
    }

    public function related(
        string $sourceTable,
        int $sourceId,
        string $targetTable,
        ?string $pivotTable = null
    ): array {
        $relation = $this->manyToManyRelation($sourceTable, $targetTable, $pivotTable);
        $reverse = $relation['left_table'] !== $sourceTable;
        $sourceKey = $reverse ? $relation['right_key'] : $relation['left_key'];
        $targetKey = $reverse ? $relation['left_key'] : $relation['right_key'];
        $rows = $this->where($relation['pivot_table'], [$sourceKey => $sourceId]);
        $result = [];
        foreach ($rows as $pivot) {
            $target = $this->find($targetTable, (int)$pivot[$targetKey]);
            if ($target !== null) {
                $result[] = $target;
            }
        }
        return $result;
    }

    public function withRelated(
        string $sourceTable,
        string $targetTable,
        string $resultKey = 'related',
        ?string $pivotTable = null
    ): array {
        $out = [];
        foreach ($this->all($sourceTable) as $row) {
            $row[$resultKey] = $this->related($sourceTable, (int)$row['id'], $targetTable, $pivotTable);
            $out[] = $row;
        }
        return $out;
    }

    public function relations(): array
    {
        return $this->readRelations();
    }

    private function manyToManyRelation(string $a, string $b, ?string $pivot): array
    {
        foreach ($this->readRelations() as $relation) {
            if (($relation['type'] ?? '') !== 'many_to_many') {
                continue;
            }
            $tables = (($relation['left_table'] ?? '') === $a && ($relation['right_table'] ?? '') === $b)
                || (($relation['left_table'] ?? '') === $b && ($relation['right_table'] ?? '') === $a);
            if ($tables && ($pivot === null || ($relation['pivot_table'] ?? '') === $pivot)) {
                return $relation;
            }
        }
        throw new RuntimeException('Die angeforderte n:m-Beziehung ist nicht definiert.');
    }

    private function validateForeignKeys(string $table, array $data): void
    {
        foreach ($this->readRelations() as $relation) {
            if (($relation['type'] ?? '') === 'one_to_many'
                && ($relation['child_table'] ?? '') === $table) {
                $this->validateFkValue($data, $relation['foreign_key'], $relation['parent_table']);
            }
            if (($relation['type'] ?? '') === 'many_to_many'
                && ($relation['pivot_table'] ?? '') === $table) {
                $this->validateFkValue($data, $relation['left_key'], $relation['left_table']);
                $this->validateFkValue($data, $relation['right_key'], $relation['right_table']);
            }
        }
    }

    private function validateFkValue(array $data, string $key, string $parentTable): void
    {
        if (!array_key_exists($key, $data)) {
            return;
        }
        $value = $this->scalarToString($data[$key]);
        if ($value === '') {
            return;
        }
        if (!ctype_digit($value) || (int)$value < 1) {
            throw new InvalidArgumentException("Fremdschluessel {$key} muss positive ID sein.");
        }
        if ($this->find($parentTable, (int)$value) === null) {
            throw new RuntimeException("Fremdschluesselverletzung fuer {$key}.");
        }
    }

    private function applyDeleteRules(string $table, int $id): void
    {
        foreach ($this->readRelations() as $relation) {
            if (($relation['type'] ?? '') === 'one_to_many'
                && ($relation['parent_table'] ?? '') === $table) {
                $children = $this->where($relation['child_table'], [$relation['foreign_key'] => $id]);
                if ($children !== []) {
                    if (($relation['on_delete'] ?? 'RESTRICT') === 'RESTRICT') {
                        throw new RuntimeException('Loeschen wegen abhaengiger Datensaetze nicht moeglich.');
                    }
                    foreach ($children as $child) {
                        if (($relation['on_delete'] ?? '') === 'CASCADE') {
                            $this->delete($relation['child_table'], (int)$child['id']);
                        } else {
                            $this->update($relation['child_table'], (int)$child['id'], [$relation['foreign_key'] => null]);
                        }
                    }
                }
            }

            if (($relation['type'] ?? '') === 'many_to_many') {
                $key = null;
                if (($relation['left_table'] ?? '') === $table) {
                    $key = $relation['left_key'];
                } elseif (($relation['right_table'] ?? '') === $table) {
                    $key = $relation['right_key'];
                }
                if ($key === null) {
                    continue;
                }
                $links = $this->where($relation['pivot_table'], [$key => $id]);
                if ($links === []) {
                    continue;
                }
                if (($relation['on_delete'] ?? 'RESTRICT') === 'RESTRICT') {
                    throw new RuntimeException('Loeschen wegen bestehender n:m-Zuordnungen nicht moeglich.');
                }
                foreach ($links as $link) {
                    $this->deleteDirect($relation['pivot_table'], (int)$link['id']);
                }
            }
        }
    }

    private function deleteDirect(string $table, int $id): bool
    {
        $path = $this->requireTable($table);
        $handle = $this->openFile($path, 'c+b');
        try {
            $this->lock($handle, LOCK_EX);
            $state = $this->readAllFromHandle($handle);
            $before = count($state['records']);
            $state['records'] = array_values(array_filter(
                $state['records'],
                static fn(array $row): bool => (int)$row['id'] !== $id
            ));
            if (count($state['records']) === $before) {
                return false;
            }
            $this->rewriteHandle($handle, $state['columns'], $state['records']);
            return true;
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    private function mutateTable(string $table, callable $mutator): void
    {
        $path = $this->requireTable($table);
        $handle = $this->openFile($path, 'c+b');
        try {
            $this->lock($handle, LOCK_EX);
            $state = $this->readAllFromHandle($handle);
            $next = $mutator($state);
            if (!is_array($next)
                || !isset($next['columns'], $next['records'])
                || !is_array($next['columns'])
                || !is_array($next['records'])) {
                throw new RuntimeException('Ungueltiger interner CSV-Schema-Zustand.');
            }
            $this->rewriteHandle($handle, $next['columns'], $next['records']);
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    private function readTable(string $table): array
    {
        $handle = $this->openFile($this->requireTable($table), 'rb');
        try {
            $this->lock($handle, LOCK_SH);
            return $this->readAllFromHandle($handle);
        } finally {
            $this->unlockAndClose($handle);
        }
    }

    private function readAllFromHandle($handle): array
    {
        rewind($handle);
        $header = fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
        if (!$header || ($header[0] ?? null) !== 'id') {
            throw new RuntimeException('Ungueltige CSV-Kopfzeile: erste Spalte muss id sein.');
        }

        $header = array_map(static fn(mixed $value): string => trim((string)$value), $header);
        $seenColumns = [];
        foreach ($header as $column) {
            $this->assertValidIdentifier($column, 'CSV-Spaltenname');
            $lower = strtolower($column);
            if (isset($seenColumns[$lower])) {
                throw new RuntimeException('Doppelte CSV-Spalte: ' . $column);
            }
            $seenColumns[$lower] = true;
        }

        $records = [];
        $seenIds = [];
        while (($row = fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE)) !== false) {
            if ($row === [null] || $row === []) {
                continue;
            }
            $row = array_slice(array_pad($row, count($header), ''), 0, count($header));
            $record = array_combine($header, array_map('strval', $row));
            if (!is_array($record)
                || !ctype_digit((string)$record['id'])
                || (int)$record['id'] < 1) {
                throw new RuntimeException('Ungueltige CSV-ID; erwartet wird eine positive Ganzzahl.');
            }
            $id = (int)$record['id'];
            if (isset($seenIds[$id])) {
                throw new RuntimeException('Doppelte CSV-ID: ' . $id);
            }
            $seenIds[$id] = true;
            $records[] = $record;
        }
        return ['columns' => $header, 'records' => $records];
    }

    private function rewriteHandle($handle, array $columns, array $records): void
    {
        $columns = $this->normalizeColumns(array_values(array_filter(
            $columns,
            static fn(string $column): bool => $column !== 'id'
        )));
        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException('CSV-Datei konnte nicht geleert werden.');
        }
        if (!$this->writeCsvRow($handle, $columns)) {
            throw new RuntimeException('CSV-Kopfzeile konnte nicht geschrieben werden.');
        }
        foreach ($records as $record) {
            $row = [];
            foreach ($columns as $column) {
                $row[] = (string)($record[$column] ?? '');
            }
            if (!$this->writeCsvRow($handle, $row)) {
                throw new RuntimeException('CSV-Datensatz konnte nicht geschrieben werden.');
            }
        }
        fflush($handle);
    }

    private function writeCsvRow($handle, array $row): bool
    {
        return fputcsv(
            $handle,
            $row,
            self::DELIMITER,
            self::ENCLOSURE,
            self::ESCAPE,
            "\n"
        ) !== false;
    }

    private function nextId(array $rows): int
    {
        $max = 0;
        foreach ($rows as $row) {
            $max = max($max, (int)$row['id']);
        }
        return $max + 1;
    }

    /** @return list<string> */
    private function normalizeColumns(array $columns): array
    {
        $out = ['id'];
        $seen = ['id' => true];
        foreach ($columns as $column) {
            if (!is_string($column)) {
                throw new InvalidArgumentException('Spaltenname muss Text sein.');
            }
            $column = trim($column);
            $this->assertValidIdentifier($column, 'Spaltenname');
            $key = strtolower($column);
            if ($key === 'id') {
                continue;
            }
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Doppelter Spaltenname: ' . $column);
            }
            $seen[$key] = true;
            $out[] = $column;
        }
        return $out;
    }

    private function assertKnownColumns(array $columns, array $data): void
    {
        foreach (array_keys($data) as $column) {
            if (!is_string($column) || !in_array($column, $columns, true)) {
                throw new InvalidArgumentException('Unbekannte Spalte ' . (string)$column . '.');
            }
        }
    }

    private function scalarToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (!is_scalar($value)) {
            throw new InvalidArgumentException('Werte muessen skalar oder null sein.');
        }
        return (string)$value;
    }

    private function normalizeDeleteRule(string $rule): string
    {
        $rule = strtoupper(trim($rule));
        if (!in_array($rule, ['RESTRICT', 'CASCADE', 'SET_NULL'], true)) {
            throw new InvalidArgumentException('Ungueltige Loeschregel.');
        }
        return $rule;
    }

    private function assertPositiveId(int $id): void
    {
        if ($id < 1) {
            throw new InvalidArgumentException('ID muss groesser als 0 sein.');
        }
    }

    private function assertValidIdentifier(string $identifier, string $label): void
    {
        if ($identifier === ''
            || strlen($identifier) > 120
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $identifier)) {
            throw new InvalidArgumentException("{$label} ist ungueltig: {$identifier}");
        }
    }

    private function tablePath(string $table): string
    {
        $this->assertValidIdentifier($table, 'Tabellenname');
        return $this->databasePath . DIRECTORY_SEPARATOR . $table . '.csv';
    }

    private function requireTable(string $table): string
    {
        $path = $this->tablePath($table);
        if (!is_file($path)) {
            throw new RuntimeException("Tabelle {$table} existiert nicht.");
        }
        if (!is_readable($path)) {
            throw new RuntimeException("Tabelle {$table} ist nicht lesbar.");
        }
        return $path;
    }

    private function relationPath(): string
    {
        return $this->databasePath . DIRECTORY_SEPARATOR . self::RELATION_FILE;
    }

    private function readRelations(): array
    {
        $path = $this->relationPath();
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('CSV-Relationsdatei konnte nicht gelesen werden.');
        }
        try {
            $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('CSV-Relationsdatei ist ungueltig: ' . $e->getMessage(), 0, $e);
        }
        if (!is_array($value)) {
            throw new RuntimeException('CSV-Relationsdatei muss ein JSON-Array enthalten.');
        }
        return $value;
    }

    private function writeRelations(array $relations): void
    {
        $json = json_encode(
            $relations,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($this->relationPath(), $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Relationen konnten nicht gespeichert werden.');
        }
    }

    private function openFile(string $path, string $mode)
    {
        $handle = fopen($path, $mode);
        if ($handle === false) {
            throw new RuntimeException('Datei konnte nicht geoeffnet werden: ' . $path);
        }
        return $handle;
    }

    private function lock($handle, int $operation): void
    {
        if (!flock($handle, $operation)) {
            throw new RuntimeException('Dateisperre fehlgeschlagen.');
        }
    }

    private function unlockAndClose($handle): void
    {
        @flock($handle, LOCK_UN);
        fclose($handle);
    }
}
