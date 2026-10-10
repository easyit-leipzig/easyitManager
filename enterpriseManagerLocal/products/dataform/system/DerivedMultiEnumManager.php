<?php
declare(strict_types=1);

/**
 * HF36 – Derived Multi-Enum / Multi-Lookup.
 *
 * A derived_multienum stores stable source values (normally IDs) as one
 * comma-separated string while the selectable captions are read dynamically
 * from another project table.
 */
final class DerivedMultiEnumManager
{
    public const FIELD_TYPE = 'derived_multienum';
    public const SEPARATOR = ',';

    public static function normalizeConfig(array $configuration): array
    {
        $derived = $configuration['derived_multienum'] ?? [];
        if (!is_array($derived)) {
            $derived = [];
        }

        foreach (
            [
                'source_table',
                'value_column',
                'label_column',
                'filter_source_column',
                'filter_field_name',
            ] as $key
        ) {
            $derived[$key] = isset($derived[$key]) && is_scalar($derived[$key])
                ? trim((string)$derived[$key])
                : '';
        }

        $filterMode = isset($derived['filter_mode']) && is_scalar($derived['filter_mode'])
            ? (string)$derived['filter_mode']
            : 'none';
        $derived['filter_mode'] = in_array(
            $filterMode,
            ['none','current_record_id','parent_record_id','field'],
            true
        ) ? $filterMode : 'none';

        $derived['separator'] = self::SEPARATOR;
        $derived['min_selected'] = isset($derived['min_selected']) && $derived['min_selected'] !== ''
            ? max(0, (int)$derived['min_selected'])
            : 0;
        $derived['max_selected'] = isset($derived['max_selected']) && $derived['max_selected'] !== ''
            ? max(0, (int)$derived['max_selected'])
            : 0;
        if (
            $derived['max_selected'] > 0
            && $derived['min_selected'] > $derived['max_selected']
        ) {
            $derived['min_selected'] = $derived['max_selected'];
        }
        $derived['max_options'] = max(
            1,
            min(5000, (int)($derived['max_options'] ?? 1000))
        );
        $derived['managed_storage'] = !empty($derived['managed_storage']);

        $configuration['derived_multienum'] = $derived;
        return $configuration;
    }

    public static function sourceTables(PDO $pdo): array
    {
        $tables = [];

        // Prefer explicit domain tables managed by the DataForm table workspace.
        if (self::tableExists($pdo, 'dataform_managed_tables')) {
            $stmt = $pdo->query(
                'SELECT table_name FROM dataform_managed_tables ORDER BY table_name'
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $name = (string)$name;
                if (self::validIdentifier($name) && self::sourceTableAllowed($name)) {
                    $tables[$name] = $name;
                }
            }
        }

        // Also include schema-bound domain tables in case they pre-date the registry.
        if (self::tableExists($pdo, 'dataform_table_bindings')) {
            $stmt = $pdo->query(
                "SELECT DISTINCT table_name
                 FROM dataform_table_bindings
                 WHERE source_kind='system'
                   AND source_id=0
                 ORDER BY table_name"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $name = (string)$name;
                if (self::validIdentifier($name) && self::sourceTableAllowed($name)) {
                    $tables[$name] = $name;
                }
            }
        }

        ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($tables);
    }

    public static function columns(PDO $pdo, string $table): array
    {
        self::assertSourceTable($pdo, $table);

        $stmt = $pdo->prepare(
            "SELECT column_name,data_type,column_type,is_nullable,column_key,extra
             FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name=?
             ORDER BY ordinal_position"
        );
        $stmt->execute([$table]);

        return array_map(
            static function (array $row): array {
                // PDO::ATTR_CASE may be configured globally (e.g. CASE_UPPER).
                // information_schema metadata must therefore be consumed
                // independently of the PDO column-name case setting.
                $row = array_change_key_case($row, CASE_LOWER);

                return [
                    'name'=>(string)($row['column_name'] ?? ''),
                    'data_type'=>(string)($row['data_type'] ?? ''),
                    'column_type'=>(string)($row['column_type'] ?? ''),
                    'nullable'=>strtoupper((string)($row['is_nullable'] ?? 'NO')) === 'YES',
                    'key'=>(string)($row['column_key'] ?? ''),
                    'extra'=>(string)($row['extra'] ?? ''),
                ];
            },
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public static function catalog(PDO $pdo): array
    {
        $result = [];
        foreach (self::sourceTables($pdo) as $table) {
            try {
                $result[$table] = self::columns($pdo, $table);
            } catch (Throwable) {
                $result[$table] = [];
            }
        }
        return $result;
    }

    public static function validateDefinition(
        PDO $pdo,
        array $configuration,
        int $dataformId = 0
    ): array {
        $configuration = self::normalizeConfig($configuration);
        $cfg = $configuration['derived_multienum'];

        $table = (string)$cfg['source_table'];
        $valueColumn = (string)$cfg['value_column'];
        $labelColumn = (string)$cfg['label_column'];
        $filterColumn = (string)$cfg['filter_source_column'];
        $filterMode = (string)$cfg['filter_mode'];
        $filterFieldName = (string)$cfg['filter_field_name'];

        if ($table === '' || $valueColumn === '' || $labelColumn === '') {
            throw new RuntimeException(
                'Für „Abgeleitete Mehrfachauswahl“ müssen Quelltabelle, Wertspalte und Anzeigespalte gewählt werden.'
            );
        }

        self::assertSourceTable($pdo, $table);
        $columnNames = array_map(
            static fn(array $row): string => (string)$row['name'],
            self::columns($pdo, $table)
        );

        foreach (
            [
                'Wertspalte'=>$valueColumn,
                'Anzeigespalte'=>$labelColumn,
            ] as $label=>$column
        ) {
            if (!in_array($column, $columnNames, true)) {
                throw new RuntimeException(
                    $label.' „'.$column.'“ existiert nicht in der Quelltabelle „'.$table.'“.'
                );
            }
        }

        if ($filterMode !== 'none') {
            if ($filterColumn === '' || !in_array($filterColumn, $columnNames, true)) {
                throw new RuntimeException(
                    'Für den Abhängigkeitsfilter muss eine gültige Filterspalte der Quelltabelle gewählt werden.'
                );
            }
        } else {
            $configuration['derived_multienum']['filter_source_column'] = '';
            $configuration['derived_multienum']['filter_field_name'] = '';
        }

        if ($filterMode === 'field') {
            if ($filterFieldName === '') {
                throw new RuntimeException(
                    'Für „Wert eines aktuellen Feldes“ muss ein Feld dieses DataForms gewählt werden.'
                );
            }
            if ($dataformId > 0) {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM dataform_fields WHERE dataform_id=? AND name=?'
                );
                $stmt->execute([$dataformId, $filterFieldName]);
                if ((int)$stmt->fetchColumn() !== 1) {
                    throw new RuntimeException(
                        'Das gewählte Abhängigkeitsfeld gehört nicht zum aktuellen DataForm.'
                    );
                }
            }
        } else {
            $configuration['derived_multienum']['filter_field_name'] = '';
        }

        return $configuration;
    }

    /**
     * Ensures a true storage column for a derived_multienum field in a
     * schema-bound DataForm. Returns updated configuration + creation flag.
     */
    public static function ensureStorageColumn(
        PDO $pdo,
        int $dataformId,
        string $fieldName,
        array $configuration
    ): array {
        self::assertIdentifier($fieldName, 'Feldname');
        $configuration = self::normalizeConfig($configuration);

        if (!self::tableExists($pdo, 'dataform_table_bindings')) {
            return [
                'configuration'=>$configuration,
                'created'=>false,
                'table'=>null,
                'column'=>null,
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT table_name
             FROM dataform_table_bindings
             WHERE dataform_id=?
               AND source_kind='system'
               AND source_id=0
             LIMIT 1"
        );
        $stmt->execute([$dataformId]);
        $table = $stmt->fetchColumn();
        if ($table === false) {
            return [
                'configuration'=>$configuration,
                'created'=>false,
                'table'=>null,
                'column'=>null,
            ];
        }

        $table = (string)$table;
        self::assertIdentifier($table, 'Tabellenname');

        $storageColumn=$fieldName;
        if (
            isset($configuration['table_binding'])
            && is_array($configuration['table_binding'])
            && (string)($configuration['table_binding']['table']??'')===$table
            && self::validIdentifier((string)($configuration['table_binding']['column']??''))
        ) {
            $storageColumn=(string)$configuration['table_binding']['column'];
        }
        self::assertIdentifier($storageColumn,'Feldname');

        $exists = $pdo->prepare(
            "SELECT column_type
             FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name=?
               AND column_name=?
             LIMIT 1"
        );
        $exists->execute([$table, $storageColumn]);
        $existingType = $exists->fetchColumn();
        $created = false;

        if ($existingType === false) {
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table)
                .' ADD COLUMN '.self::quoteIdentifier($storageColumn)
                ." TEXT NULL COMMENT 'easyIT derived_multienum'"
            );
            $created = true;
        } elseif (
            preg_match(
                '/^(?:char|varchar|tinytext|text|mediumtext|longtext)/i',
                (string)$existingType
            ) !== 1
        ) {
            throw new RuntimeException(
                'Das physische Feld „'.$storageColumn.'“ muss für derived_multienum ein CHAR/VARCHAR/TEXT-Feld sein.'
            );
        }

        $configuration['table_binding'] = [
            'source_kind'=>'system',
            'table'=>$table,
            'column'=>$storageColumn,
            'sql_type'=>'text',
        ];
        $configuration['derived_multienum']['managed_storage'] = $created
            || !empty($configuration['derived_multienum']['managed_storage']);

        return [
            'configuration'=>$configuration,
            'created'=>$created,
            'table'=>$table,
            'column'=>$storageColumn,
        ];
    }

    public static function rollbackStorageColumn(
        PDO $pdo,
        ?string $table,
        ?string $column,
        bool $created
    ): void {
        if (!$created || $table === null || $column === null) {
            return;
        }
        self::assertIdentifier($table, 'Tabellenname');
        self::assertIdentifier($column, 'Feldname');
        try {
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table)
                .' DROP COLUMN '.self::quoteIdentifier($column)
            );
        } catch (Throwable) {
            // Best-effort rollback. The original exception remains authoritative.
        }
    }

    public static function options(
        PDO $pdo,
        array $configuration,
        array $context = []
    ): array {
        $configuration = self::validateDefinition(
            $pdo,
            $configuration,
            (int)($context['dataform_id'] ?? 0)
        );
        $cfg = $configuration['derived_multienum'];

        $table = (string)$cfg['source_table'];
        $valueColumn = (string)$cfg['value_column'];
        $labelColumn = (string)$cfg['label_column'];
        $filterMode = (string)$cfg['filter_mode'];
        $filterColumn = (string)$cfg['filter_source_column'];
        $limit = (int)$cfg['max_options'];

        $filterValue = null;
        $requiresFilter = $filterMode !== 'none';

        if ($filterMode === 'current_record_id') {
            $recordId = (int)($context['record_id'] ?? 0);
            if ($recordId < 1) {
                return [];
            }
            $filterValue = (string)$recordId;
        } elseif ($filterMode === 'parent_record_id') {
            $parentId = (int)($context['parent_record_id'] ?? 0);
            if ($parentId < 1) {
                return [];
            }
            $filterValue = (string)$parentId;
        } elseif ($filterMode === 'field') {
            $fieldName = (string)$cfg['filter_field_name'];
            $currentData = $context['current_data'] ?? [];
            if (!is_array($currentData)) {
                $currentData = [];
            }
            $candidate = $currentData[$fieldName] ?? null;
            if (is_array($candidate)) {
                $candidate = reset($candidate);
            }
            if ($candidate === null || (string)$candidate === '') {
                return [];
            }
            $filterValue = (string)$candidate;
        }

        $sql = 'SELECT '
            .self::quoteIdentifier($valueColumn).' AS derived_value, '
            .self::quoteIdentifier($labelColumn).' AS derived_label '
            .'FROM '.self::quoteIdentifier($table);
        $params = [];

        if ($requiresFilter) {
            $sql .= ' WHERE '.self::quoteIdentifier($filterColumn).' = ?';
            $params[] = $filterValue;
        }

        $sql .= ' ORDER BY '.self::quoteIdentifier($labelColumn)
            .', '.self::quoteIdentifier($valueColumn)
            .' LIMIT '.$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = trim((string)($row['derived_value'] ?? ''));
            if ($value === '' || str_contains($value, self::SEPARATOR)) {
                continue;
            }
            $label = trim((string)($row['derived_label'] ?? ''));
            if ($label === '') {
                $label = $value;
            }
            if (isset($result[$value])) {
                throw new RuntimeException(
                    'Die Wertspalte „'.$valueColumn.'“ der Tabelle „'.$table
                    .'“ enthält den Wert „'.$value.'“ mehrfach. Eine abgeleitete Mehrfachauswahl benötigt eindeutige Quellwerte.'
                );
            }
            $result[$value] = [
                'value'=>$value,
                'label'=>$label,
            ];
        }

        return array_values($result);
    }

    public static function canonicalizeSelection(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif ($value === null || $value === '') {
            $parts = [];
        } else {
            $parts = explode(self::SEPARATOR, (string)$value);
        }

        $result = [];
        foreach ($parts as $part) {
            if (!is_scalar($part)) {
                continue;
            }
            $item = trim((string)$part);
            if ($item === '' || str_contains($item, self::SEPARATOR)) {
                continue;
            }
            $result[$item] = $item;
        }

        return array_values($result);
    }

    public static function csv(array $values): string
    {
        return implode(self::SEPARATOR, self::canonicalizeSelection($values));
    }

    public static function validateSelection(
        array $selected,
        array $options,
        array $configuration,
        string $fieldLabel
    ): string {
        $configuration = self::normalizeConfig($configuration);
        $cfg = $configuration['derived_multienum'];
        $selected = self::canonicalizeSelection($selected);

        $allowed = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['value'])) {
                $allowed[(string)$option['value']] = true;
            }
        }

        foreach ($selected as $value) {
            if (!isset($allowed[$value])) {
                throw new RuntimeException(
                    'Das Feld „'.$fieldLabel.'“ enthält den nicht zulässigen abgeleiteten Wert „'.$value.'“.'
                );
            }
        }

        $count = count($selected);
        $min = (int)$cfg['min_selected'];
        $max = (int)$cfg['max_selected'];
        if ($count < $min) {
            throw new RuntimeException(
                'Im Feld „'.$fieldLabel.'“ müssen mindestens '.$min.' Werte gewählt werden.'
            );
        }
        if ($max > 0 && $count > $max) {
            throw new RuntimeException(
                'Im Feld „'.$fieldLabel.'“ dürfen höchstens '.$max.' Werte gewählt werden.'
            );
        }

        return implode(self::SEPARATOR, $selected);
    }

    public static function labelsForCsv(
        PDO $pdo,
        array $configuration,
        string $csv
    ): array {
        $configuration = self::normalizeConfig($configuration);
        $cfg = $configuration['derived_multienum'];
        $values = self::canonicalizeSelection($csv);
        if ($values === []) {
            return [];
        }

        $table = (string)$cfg['source_table'];
        $valueColumn = (string)$cfg['value_column'];
        $labelColumn = (string)$cfg['label_column'];
        self::assertSourceTable($pdo, $table);
        $columnNames = array_map(
            static fn(array $row): string => (string)$row['name'],
            self::columns($pdo, $table)
        );
        if (
            !in_array($valueColumn, $columnNames, true)
            || !in_array($labelColumn, $columnNames, true)
        ) {
            return array_map(
                static fn(string $value): array => [
                    'value'=>$value,
                    'label'=>$value.' (Quelle ungültig)',
                    'missing'=>true,
                ],
                $values
            );
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $pdo->prepare(
            'SELECT '.self::quoteIdentifier($valueColumn).' AS derived_value, '
            .self::quoteIdentifier($labelColumn).' AS derived_label '
            .'FROM '.self::quoteIdentifier($table)
            .' WHERE '.self::quoteIdentifier($valueColumn)
            .' IN ('.$placeholders.')'
        );
        $stmt->execute($values);

        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = (string)$row['derived_value'];
            $label = trim((string)$row['derived_label']);
            $found[$value] = $label !== '' ? $label : $value;
        }

        $resolved = [];
        foreach ($values as $value) {
            if (array_key_exists($value, $found)) {
                $resolved[] = [
                    'value'=>$value,
                    'label'=>$found[$value],
                    'missing'=>false,
                ];
            } else {
                $resolved[] = [
                    'value'=>$value,
                    'label'=>$value.' (nicht mehr vorhanden)',
                    'missing'=>true,
                ];
            }
        }
        return $resolved;
    }

    public static function displayText(
        PDO $pdo,
        array $configuration,
        string $csv
    ): string {
        return implode(
            ', ',
            array_map(
                static fn(array $row): string => (string)$row['label'],
                self::labelsForCsv($pdo, $configuration, $csv)
            )
        );
    }

    public static function assertSourceColumnNotReferenced(
        PDO $pdo,
        string $table,
        string $column
    ): void {
        self::assertIdentifier($table, 'Quelltabelle');
        self::assertIdentifier($column, 'Quellspalte');
        if (!self::tableExists($pdo, 'dataform_fields')) {
            return;
        }

        $stmt=$pdo->query(
            "SELECT id,label,configuration_json
             FROM dataform_fields
             WHERE field_type='derived_multienum'
               AND configuration_json IS NOT NULL
               AND configuration_json<>''"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $cfg=self::normalizeConfig(
                json_decode((string)$field['configuration_json'],true)?:[]
            );
            $derived=$cfg['derived_multienum'];
            if ((string)$derived['source_table']!==$table) {
                continue;
            }
            $uses=array_filter([
                'Wertspalte'=>(string)$derived['value_column'],
                'Anzeigespalte'=>(string)$derived['label_column'],
                'Filterspalte'=>(string)$derived['filter_source_column'],
            ],static fn(string $value):bool=>$value!=='');
            foreach ($uses as $role=>$usedColumn) {
                if ($usedColumn===$column) {
                    throw new RuntimeException(
                        'Die Spalte „'.$table.'.'.$column.'“ wird als '.$role
                        .' der abgeleiteten Mehrfachauswahl „'.(string)$field['label']
                        .'“ verwendet. Ändern Sie zuerst die Felddefinition.'
                    );
                }
            }
        }
    }

    public static function assertSourceTableNotReferenced(
        PDO $pdo,
        string $table
    ): void {
        self::assertIdentifier($table, 'Quelltabelle');
        if (!self::tableExists($pdo, 'dataform_fields')) {
            return;
        }

        $stmt=$pdo->query(
            "SELECT id,label,configuration_json
             FROM dataform_fields
             WHERE field_type='derived_multienum'
               AND configuration_json IS NOT NULL
               AND configuration_json<>''"
        );
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $cfg=self::normalizeConfig(
                json_decode((string)$field['configuration_json'],true)?:[]
            );
            if ((string)$cfg['derived_multienum']['source_table']===$table) {
                throw new RuntimeException(
                    'Die Tabelle „'.$table.'“ wird noch als Quelle der abgeleiteten Mehrfachauswahl „'
                    .(string)$field['label'].'“ verwendet. Entfernen oder ändern Sie zuerst diese Felddefinition.'
                );
            }
        }
    }

    public static function referencesToSourceRecord(
        PDO $pdo,
        int $sourceDataformId,
        int $sourceRecordId
    ): array {
        if ($sourceDataformId < 1 || $sourceRecordId < 1) {
            return [];
        }
        if (!self::tableExists($pdo, 'dataform_table_bindings')) {
            return [];
        }

        $bindingStmt = $pdo->prepare(
            "SELECT table_name
             FROM dataform_table_bindings
             WHERE dataform_id=?
               AND source_kind='system'
               AND source_id=0
             LIMIT 1"
        );
        $bindingStmt->execute([$sourceDataformId]);
        $sourceTable = $bindingStmt->fetchColumn();
        if ($sourceTable === false) {
            return [];
        }
        $sourceTable = (string)$sourceTable;
        self::assertSourceTable($pdo, $sourceTable);

        $fields = $pdo->query(
            "SELECT id,dataform_id,name,label,configuration_json
             FROM dataform_fields
             WHERE field_type='derived_multienum'
               AND configuration_json IS NOT NULL
               AND configuration_json<>''"
        )->fetchAll(PDO::FETCH_ASSOC);

        $references = [];
        foreach ($fields as $field) {
            $configuration = self::normalizeConfig(
                json_decode((string)$field['configuration_json'], true) ?: []
            );
            $cfg = $configuration['derived_multienum'];
            if ((string)$cfg['source_table'] !== $sourceTable) {
                continue;
            }

            $valueColumn = (string)$cfg['value_column'];
            try {
                $columns = array_map(
                    static fn(array $row): string => (string)$row['name'],
                    self::columns($pdo, $sourceTable)
                );
            } catch (Throwable) {
                continue;
            }
            if (!in_array($valueColumn, $columns, true)) {
                continue;
            }

            $valueStmt = $pdo->prepare(
                'SELECT '.self::quoteIdentifier($valueColumn)
                .' FROM '.self::quoteIdentifier($sourceTable)
                .' WHERE `id`=? LIMIT 1'
            );
            $valueStmt->execute([$sourceRecordId]);
            $sourceValue = $valueStmt->fetchColumn();
            if ($sourceValue === false || $sourceValue === null) {
                continue;
            }
            $sourceValue = (string)$sourceValue;

            // Use DataFormRecordStore when available; it supports generic and
            // table-bound target DataForms with one API.
            if (!class_exists('DataFormRecordStore')) {
                require_once __DIR__.'/DataFormRecordStore.php';
            }
            foreach (
                DataFormRecordStore::all(
                    $pdo,
                    (int)$field['dataform_id']
                ) as $targetRecord
            ) {
                if (
                    (int)$field['dataform_id']===$sourceDataformId
                    && (int)$targetRecord['id']===$sourceRecordId
                ) {
                    continue;
                }
                $stored = (string)(
                    $targetRecord['data'][(string)$field['name']] ?? ''
                );
                if (in_array(
                    $sourceValue,
                    self::canonicalizeSelection($stored),
                    true
                )) {
                    $references[] = [
                        'field_id'=>(int)$field['id'],
                        'dataform_id'=>(int)$field['dataform_id'],
                        'field_name'=>(string)$field['name'],
                        'field_label'=>(string)$field['label'],
                        'record_id'=>(int)$targetRecord['id'],
                        'source_value'=>$sourceValue,
                    ];
                }
            }
        }

        return $references;
    }

    private static function assertSourceTable(PDO $pdo, string $table): void
    {
        self::assertIdentifier($table, 'Quelltabelle');
        if (!self::sourceTableAllowed($table)) {
            throw new RuntimeException(
                'Die gewählte Quelltabelle ist eine interne DataForm-Systemtabelle und darf nicht als abgeleitete Wertquelle verwendet werden.'
            );
        }

        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name=?
               AND table_type='BASE TABLE'"
        );
        $stmt->execute([$table]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException(
                'Die Quelltabelle „'.$table.'“ existiert nicht.'
            );
        }
    }

    private static function sourceTableAllowed(string $table): bool
    {
        $lower = strtolower($table);
        if (str_starts_with($lower, 'dataform_')) {
            return false;
        }
        return !in_array(
            $lower,
            ['migrations','migration_versions','schema_migrations'],
            true
        );
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        self::assertIdentifier($table, 'Tabellenname');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name=?"
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function validIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $identifier) === 1;
    }

    private static function assertIdentifier(string $identifier, string $label): void
    {
        if (!self::validIdentifier($identifier)) {
            throw new RuntimeException(
                $label.' enthält einen ungültigen SQL-Bezeichner.'
            );
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        self::assertIdentifier($identifier, 'SQL-Bezeichner');
        return '`'.$identifier.'`';
    }
}
