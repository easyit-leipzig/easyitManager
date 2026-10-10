<?php
declare(strict_types=1);

require_once __DIR__.'/DataFormRecordSetEventRepository.php';
require_once __DIR__ . '/TableWorkspaceManager.php';
require_once __DIR__ . '/DataFormFieldTypes.php';

final class DataFormManager
{
    /**
     * HF62: Persistiert DataForm-weite Einstellungen fuer die tabellarische
     * Datensatzbearbeitung direkt am DataForm. Der Standard bleibt bewusst
     * 'manual', damit bestehende Installationen ihr bisheriges Verhalten
     * behalten. Die Erfolgsmeldung ist standardmaessig aktiv.
     */
    public static function ensureRuntimeSettingsSchema(PDO $pdo): void
    {
        if (!self::columnExists($pdo, 'dataforms', 'table_save_mode')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN table_save_mode VARCHAR(20) NOT NULL DEFAULT 'manual' AFTER status");
        }
        if (!self::columnExists($pdo, 'dataforms', 'show_save_success')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN show_save_success TINYINT(1) NOT NULL DEFAULT 1 AFTER table_save_mode");
        }
        if (!self::columnExists($pdo, 'dataforms', 'view_mode')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN view_mode VARCHAR(20) NOT NULL DEFAULT 'table' AFTER show_save_success");
        }
        if (!self::columnExists($pdo, 'dataforms', 'default_per_page')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN default_per_page INT UNSIGNED NOT NULL DEFAULT 20 AFTER view_mode");
        }
        if (!self::columnExists($pdo, 'dataforms', 'show_search')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN show_search TINYINT(1) NOT NULL DEFAULT 1 AFTER default_per_page");
        }
        if (!self::columnExists($pdo, 'dataforms', 'show_filter')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN show_filter TINYINT(1) NOT NULL DEFAULT 1 AFTER show_search");
        }
        if (!self::columnExists($pdo, 'dataforms', 'show_pagination')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN show_pagination TINYINT(1) NOT NULL DEFAULT 1 AFTER show_filter");
        }
        if (!self::columnExists($pdo, 'dataforms', 'allow_create')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN allow_create TINYINT(1) NOT NULL DEFAULT 1 AFTER show_pagination");
        }
        if (!self::columnExists($pdo, 'dataforms', 'allow_edit')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN allow_edit TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_create");
        }
        if (!self::columnExists($pdo, 'dataforms', 'allow_delete')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN allow_delete TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_edit");
        }
        if (!self::columnExists($pdo, 'dataforms', 'dialog_size')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN dialog_size VARCHAR(20) NOT NULL DEFAULT 'large' AFTER allow_delete");
        }
        if (!self::columnExists($pdo, 'dataforms', 'css_class')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN css_class VARCHAR(160) NULL AFTER dialog_size");
        }
        if (!self::columnExists($pdo, 'dataforms', 'additional_css')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN additional_css LONGTEXT NULL AFTER css_class");
        }
        if (!self::columnExists($pdo, 'dataforms', 'event_handlers_json')) {
            $pdo->exec("ALTER TABLE dataforms ADD COLUMN event_handlers_json LONGTEXT NULL AFTER additional_css");
        }

        // STAND 4: DS-/RecordSet-Ereignisse besitzen eine eigene Persistenz.
        DataFormRecordSetEventRepository::ensureSchema($pdo);
        DataFormRecordSetEventRepository::migrateLegacyDataformsColumn($pdo);
        DataFormRecordSetEventRepository::splitMixedDataFormHandlers($pdo);
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt=$pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?"
        );
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn()>0;
    }

    public static function ensureTableBindingSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS dataform_table_bindings (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                dataform_id BIGINT UNSIGNED NOT NULL,
                source_kind VARCHAR(30) NOT NULL DEFAULT 'system',
                source_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                table_name VARCHAR(64) NOT NULL,
                binding_mode VARCHAR(30) NOT NULL DEFAULT 'schema',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_dataform_table_binding_form(dataform_id),
                UNIQUE KEY uq_dataform_table_binding_source(source_kind,source_id,table_name),
                CONSTRAINT fk_dataform_table_binding_form
                    FOREIGN KEY(dataform_id) REFERENCES dataforms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function tableBinding(
        PDO $pdo,
        string $table,
        string $sourceKind='system',
        ?int $sourceId=null
    ): ?array {
        self::ensureTableBindingSchema($pdo);
        self::assertSqlIdentifier($table);

        if ($sourceKind === 'system') {
            $stmt=$pdo->prepare(
                "SELECT b.*,d.name AS dataform_name,d.slug AS dataform_slug
                 FROM dataform_table_bindings b
                 JOIN dataforms d ON d.id=b.dataform_id
                 WHERE b.source_kind='system'
                   AND b.source_id=0
                   AND b.table_name=?
                 LIMIT 1"
            );
            $stmt->execute([$table]);
        } else {
            $stmt=$pdo->prepare(
                "SELECT b.*,d.name AS dataform_name,d.slug AS dataform_slug
                 FROM dataform_table_bindings b
                 JOIN dataforms d ON d.id=b.dataform_id
                 WHERE b.source_kind=?
                   AND b.source_id=?
                   AND b.table_name=?
                 LIMIT 1"
            );
            $stmt->execute([$sourceKind,$sourceId,$table]);
        }

        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function createFromManagedTable(
        PDO $pdo,
        string $table,
        ?string $requestedName=null
    ): array {
        self::ensureTableBindingSchema($pdo);
        self::assertSqlIdentifier($table);

        if (!TableWorkspaceManager::fieldCrudAllowed($pdo,$table)) {
            throw new RuntimeException(
                'Ein DataForm kann nicht aus einer geschützten internen Systemtabelle erzeugt werden.'
            );
        }

        $existing=self::tableBinding($pdo,$table,'system',null);
        if ($existing !== null) {
            return [
                'created'=>false,
                'dataform_id'=>(int)$existing['dataform_id'],
                'name'=>(string)$existing['dataform_name'],
                'slug'=>(string)$existing['dataform_slug'],
                'fields'=>0,
                'table'=>$table,
            ];
        }

        $columns=$pdo->query(
            'SHOW FULL COLUMNS FROM '.self::quoteIdentifier($table)
        )->fetchAll(PDO::FETCH_ASSOC);
        if (!$columns) {
            throw new RuntimeException('Die Tabelle besitzt keine auswertbare Felddefinition.');
        }

        $name=trim((string)$requestedName);
        if ($name === '') {
            $name=self::humanize($table);
        }
        if ($name === '') {
            $name=$table;
        }
        if (mb_strlen($name)>160) {
            $name=mb_substr($name,0,160);
        }

        $slug=self::uniqueSlug($pdo,$table);
        $description='Automatisch aus der Projekttabelle „'.$table.'“ erzeugt.';

        $pdo->beginTransaction();
        try {
            $insert=$pdo->prepare(
                'INSERT INTO dataforms(name,slug,description,status) VALUES(?,?,?,?)'
            );
            $insert->execute([$name,$slug,$description,'draft']);
            $dataformId=(int)$pdo->lastInsertId();

            $fieldInsert=$pdo->prepare(
                'INSERT INTO dataform_fields('
                .'dataform_id,name,label,field_type,position,is_required,configuration_json'
                .') VALUES(?,?,?,?,?,?,?)'
            );

            $position=10;
            $fieldCount=0;
            foreach ($columns as $column) {
                if (self::isTechnicalIdColumn($column)) {
                    continue;
                }

                $mapped=self::mapColumn($table,$column);
                $fieldInsert->execute([
                    $dataformId,
                    $mapped['name'],
                    $mapped['label'],
                    $mapped['field_type'],
                    $position,
                    $mapped['is_required'] ? 1 : 0,
                    json_encode(
                        $mapped['configuration'],
                        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                    ),
                ]);
                $position+=10;
                $fieldCount++;
            }

            $binding=$pdo->prepare(
                "INSERT INTO dataform_table_bindings(
                    dataform_id,source_kind,source_id,table_name,binding_mode
                 ) VALUES(?,'system',0,?,'schema')"
            );
            $binding->execute([$dataformId,$table]);

            $pdo->commit();

            return [
                'created'=>true,
                'dataform_id'=>$dataformId,
                'name'=>$name,
                'slug'=>$slug,
                'fields'=>$fieldCount,
                'table'=>$table,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * RC1.1 / CSV: Erzeugt ein DataForm direkt aus einer Tabelle einer
     * registrierten CSV-Datenquelle. Die DataForm-Metadaten bleiben bewusst in
     * der Projekt-Metadatenbank; die Datensaetze selbst werden ueber den
     * DataForm-CSV-Adapter gelesen und geschrieben.
     */
    public static function createFromCsvTable(
        PDO $pdo,
        int $sourceId,
        string $table,
        ?string $requestedName=null
    ): array {
        self::ensureTableBindingSchema($pdo);
        if ($sourceId<1) {
            throw new RuntimeException('Ungueltige CSV-Datenquelle.');
        }
        self::assertSqlIdentifier($table);

        $existing=self::tableBinding($pdo,$table,'external',$sourceId);
        if ($existing !== null) {
            return [
                'created'=>false,
                'dataform_id'=>(int)$existing['dataform_id'],
                'name'=>(string)$existing['dataform_name'],
                'slug'=>(string)$existing['dataform_slug'],
                'fields'=>0,
                'table'=>$table,
                'source_id'=>$sourceId,
            ];
        }

        [$source,$db]=self::csvSourceDatabase($pdo,$sourceId);
        try {
            if (!$db->tableExists($table)) {
                throw new RuntimeException('Die CSV-Tabelle „'.$table.'“ existiert nicht.');
            }
            $columnNames=$db->columns($table);
        } finally {
            $db->disconnect();
        }

        if ($columnNames===[] || ($columnNames[0]??'')!=='id') {
            throw new RuntimeException('Die CSV-Tabelle besitzt keine gueltige DataForm-Kopfzeile mit Pflichtspalte id.');
        }

        $columns=[];
        foreach ($columnNames as $columnName) {
            self::assertSqlIdentifier($columnName);
            $columns[]=[
                'Field'=>$columnName,
                'Type'=>$columnName==='id'?'bigint':'text',
                'Null'=>$columnName==='id'?'NO':'YES',
                'Key'=>$columnName==='id'?'PRI':'',
                'Default'=>null,
                'Extra'=>$columnName==='id'?'auto_increment':'csv',
            ];
        }

        $name=trim((string)$requestedName);
        if ($name==='') {
            $name=self::humanize($table);
        }
        if ($name==='') {
            $name=$table;
        }
        if (mb_strlen($name)>160) {
            $name=mb_substr($name,0,160);
        }

        $slug=self::uniqueSlug($pdo,$table);
        $description='Automatisch aus der CSV-Tabelle „'.$table.'“ der Datenquelle „'.(string)$source['name'].'“ erzeugt.';

        $pdo->beginTransaction();
        try {
            $insert=$pdo->prepare(
                'INSERT INTO dataforms(name,slug,description,status) VALUES(?,?,?,?)'
            );
            $insert->execute([$name,$slug,$description,'draft']);
            $dataformId=(int)$pdo->lastInsertId();

            $fieldInsert=$pdo->prepare(
                'INSERT INTO dataform_fields('
                .'dataform_id,name,label,field_type,position,is_required,configuration_json'
                .') VALUES(?,?,?,?,?,?,?)'
            );

            $position=10;
            $fieldCount=0;
            foreach ($columns as $column) {
                if (self::isTechnicalIdColumn($column)) {
                    continue;
                }
                $mapped=self::mapColumn($table,$column,'external',$sourceId);
                $mapped['configuration']['table_binding']['storage']='csv';
                $fieldInsert->execute([
                    $dataformId,
                    $mapped['name'],
                    $mapped['label'],
                    $mapped['field_type'],
                    $position,
                    $mapped['is_required']?1:0,
                    json_encode(
                        $mapped['configuration'],
                        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
                    ),
                ]);
                $position+=10;
                $fieldCount++;
            }

            $binding=$pdo->prepare(
                "INSERT INTO dataform_table_bindings(
                    dataform_id,source_kind,source_id,table_name,binding_mode
                 ) VALUES(?,'external',?,?,'csv')"
            );
            $binding->execute([$dataformId,$sourceId,$table]);
            $pdo->commit();

            return [
                'created'=>true,
                'dataform_id'=>$dataformId,
                'name'=>$name,
                'slug'=>$slug,
                'fields'=>$fieldCount,
                'table'=>$table,
                'source_id'=>$sourceId,
                'source_name'=>(string)$source['name'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * RC1.1 / CSV: Haelt die logische DataForm-Feldbindung stabil, wenn eine
     * physische CSV-Spalte im Tabellenarbeitsbereich umbenannt wird. Der interne
     * Feldname bleibt bewusst unveraendert; nur die physische Spaltenadresse wird
     * aktualisiert. Dadurch bleiben Events und bestehende Formularlogik stabil.
     */
    public static function renameCsvBoundColumn(
        PDO $pdo,
        int $sourceId,
        string $table,
        string $oldColumn,
        string $newColumn
    ): int {
        self::ensureTableBindingSchema($pdo);
        self::assertSqlIdentifier($table);
        self::assertSqlIdentifier($oldColumn);
        self::assertSqlIdentifier($newColumn);
        if ($sourceId<1 || $oldColumn==='id' || $newColumn==='id') {
            throw new RuntimeException('Ungueltige CSV-Spaltenbindung.');
        }

        $binding=self::tableBinding($pdo,$table,'external',$sourceId);
        if ($binding===null) {
            return 0;
        }
        $dataformId=(int)$binding['dataform_id'];
        $stmt=$pdo->prepare(
            'SELECT id,configuration_json FROM dataform_fields WHERE dataform_id=? ORDER BY id'
        );
        $stmt->execute([$dataformId]);
        $update=$pdo->prepare(
            'UPDATE dataform_fields SET configuration_json=? WHERE id=? AND dataform_id=?'
        );
        $changed=0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $raw=$field['configuration_json']??null;
            $cfg=is_string($raw)&&trim($raw)!=='' ? json_decode($raw,true) : [];
            if (!is_array($cfg) || !is_array($cfg['table_binding']??null)) {
                continue;
            }
            $tb=$cfg['table_binding'];
            if (
                (string)($tb['source_kind']??'')!=='external'
                || (int)($tb['source_id']??0)!==$sourceId
                || (string)($tb['table']??'')!==$table
                || strcasecmp((string)($tb['column']??''),$oldColumn)!==0
            ) {
                continue;
            }
            $cfg['table_binding']['column']=$newColumn;
            $cfg['table_binding']['sql_type']='text';
            $cfg['table_binding']['storage']='csv';
            $update->execute([
                json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                (int)$field['id'],
                $dataformId,
            ]);
            $changed++;
        }
        self::synchronizeCsvBoundTableFields($pdo,$binding);
        return $changed;
    }

    /**
     * RC1.1 / CSV: Entfernt bei einem ausdruecklichen physischen Spalten-Delete
     * genau die unmittelbar daran gebundenen DataForm-Felder. Nicht gebundene
     * Designerfelder bleiben unangetastet.
     */
    public static function removeCsvBoundColumnField(
        PDO $pdo,
        int $sourceId,
        string $table,
        string $column
    ): int {
        self::ensureTableBindingSchema($pdo);
        self::assertSqlIdentifier($table);
        self::assertSqlIdentifier($column);
        if ($sourceId<1 || $column==='id') {
            throw new RuntimeException('Die CSV-Pflichtspalte id kann nicht entfernt werden.');
        }
        $binding=self::tableBinding($pdo,$table,'external',$sourceId);
        if ($binding===null) {
            return 0;
        }
        $dataformId=(int)$binding['dataform_id'];
        $stmt=$pdo->prepare(
            'SELECT id,configuration_json FROM dataform_fields WHERE dataform_id=? ORDER BY id'
        );
        $stmt->execute([$dataformId]);
        $delete=$pdo->prepare('DELETE FROM dataform_fields WHERE id=? AND dataform_id=?');
        $removed=0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $raw=$field['configuration_json']??null;
            $cfg=is_string($raw)&&trim($raw)!=='' ? json_decode($raw,true) : [];
            $tb=is_array($cfg)&&is_array($cfg['table_binding']??null) ? $cfg['table_binding'] : [];
            if (
                (string)($tb['source_kind']??'')==='external'
                && (int)($tb['source_id']??0)===$sourceId
                && (string)($tb['table']??'')===$table
                && strcasecmp((string)($tb['column']??''),$column)===0
            ) {
                $delete->execute([(int)$field['id'],$dataformId]);
                $removed+=$delete->rowCount();
            }
        }
        return $removed;
    }

    /**
     * HF38: Synchronisiert alle persistierend tabellengebundenen DataForms mit
     * ihrer realen physischen Tabellenstruktur.
     *
     * Fehlende physische Spalten werden als DataForm-Felder registriert. Ein
     * bereits vorhandenes Feld wird niemals dupliziert. Besitzt ein passendes
     * Feld noch keine table_binding-Metadaten, werden diese ergänzt. Seit HF41
     * werden Änderungen des realen SQL-Datentyps außerdem konservativ in den
     * automatisch abgeleiteten DataForm-Feldtyp übernommen.
     */
    public static function synchronizeAllBoundTableFields(PDO $pdo): array
    {
        self::ensureTableBindingSchema($pdo);
        $bindings=$pdo->query(
            'SELECT dataform_id,source_kind,source_id,table_name '
            .'FROM dataform_table_bindings ORDER BY dataform_id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $summary=[
            'bindings'=>0,
            'created'=>0,
            'linked'=>0,
            'updated'=>0,
            'warnings'=>[],
        ];
        foreach ($bindings as $binding) {
            $summary['bindings']++;
            try {
                $result=self::synchronizeBoundTableFields(
                    $pdo,
                    (int)$binding['dataform_id']
                );
                $summary['created']+=(int)$result['created'];
                $summary['linked']+=(int)$result['linked'];
                $summary['updated']+=(int)($result['updated']??0);
            } catch (Throwable $e) {
                $summary['warnings'][]='DataForm #'.(int)$binding['dataform_id']
                    .': '.$e->getMessage();
            }
        }
        return $summary;
    }

    /**
     * HF76: Creates a physical storage column only for fields that are newly
     * added in the Designer to a DataForm bound to the project database.
     * Existing schema columns are never destructively altered here.
     *
     * @return array{configuration:array,created:bool,table:?string,column:?string}
     */
    public static function ensureDesignerStorageColumn(
        PDO $pdo,
        int $dataformId,
        string $fieldName,
        string $fieldType,
        array $configuration
    ): array {
        self::ensureTableBindingSchema($pdo);
        self::assertSqlIdentifier($fieldName);
        $stmt=$pdo->prepare(
            'SELECT source_kind,source_id,table_name FROM dataform_table_bindings WHERE dataform_id=? LIMIT 1'
        );
        $stmt->execute([$dataformId]);
        $binding=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$binding || (string)$binding['source_kind']!=='system' || (int)$binding['source_id']!==0) {
            return ['configuration'=>$configuration,'created'=>false,'table'=>null,'column'=>null];
        }
        $table=(string)$binding['table_name'];
        self::assertSqlIdentifier($table);
        if (!self::tableExists($pdo,$table)) {
            throw new RuntimeException('Die gebundene Tabelle „'.$table.'“ existiert nicht.');
        }
        $columnStmt=$pdo->prepare(
            'SELECT COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $columnStmt->execute([$table,$fieldName]);
        $column=$columnStmt->fetch(PDO::FETCH_ASSOC);
        $created=false;
        if (!$column) {
            $storageDriver=($pdo instanceof \EnterpriseSqlitePdo || (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite')?'sqlite':'mysql';
            $sqlType=DataFormFieldTypeRegistry::sqlType($fieldType,$storageDriver,$configuration);
            if (preg_match('/^[A-Z]+(?:\([0-9,]+\))?$/i',$sqlType)!==1 && !in_array(strtoupper($sqlType),['TEXT','LONGTEXT','DATE','TIME','DATETIME','JSON'],true)) {
                throw new RuntimeException('Der SQL-Speichertyp für „'.$fieldName.'“ ist ungültig.');
            }
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table)
                .' ADD COLUMN '.self::quoteIdentifier($fieldName).' '.$sqlType.' NULL'
            );
            $created=true;
            $columnStmt->execute([$table,$fieldName]);
            $column=$columnStmt->fetch(PDO::FETCH_ASSOC) ?: ['COLUMN_TYPE'=>strtolower($sqlType)];
        }
        $wasManaged=!empty($configuration['table_binding']['managed_field_column']??false);
        $configuration['table_binding']=[
            'source_kind'=>'system',
            'table'=>$table,
            'column'=>$fieldName,
            'sql_type'=>strtolower((string)($column['COLUMN_TYPE']??'')),
        ];
        if ($created || $wasManaged) $configuration['table_binding']['managed_field_column']=true;
        return ['configuration'=>$configuration,'created'=>$created,'table'=>$table,'column'=>$fieldName];
    }

    public static function rollbackDesignerStorageColumn(PDO $pdo,?string $table,?string $column,bool $created): void
    {
        if (!$created || !$table || !$column) return;
        try {
            self::assertSqlIdentifier($table); self::assertSqlIdentifier($column);
            if (self::tableExists($pdo,$table) && self::columnExists($pdo,$table,$column)) {
                $pdo->exec('ALTER TABLE '.self::quoteIdentifier($table).' DROP COLUMN '.self::quoteIdentifier($column));
            }
        } catch (Throwable) {
            // Rollback is best-effort; the original exception remains authoritative.
        }
    }

    public static function dropManagedDesignerStorageColumn(PDO $pdo,array $configuration): bool
    {
        $binding=is_array($configuration['table_binding']??null)?$configuration['table_binding']:[];
        if (empty($binding['managed_field_column'])) return false;
        $table=(string)($binding['table']??''); $column=(string)($binding['column']??'');
        if ($table==='' || $column==='') return false;
        self::assertSqlIdentifier($table); self::assertSqlIdentifier($column);
        if (!self::tableExists($pdo,$table) || !self::columnExists($pdo,$table,$column)) return false;
        $pdo->exec('ALTER TABLE '.self::quoteIdentifier($table).' DROP COLUMN '.self::quoteIdentifier($column));
        return true;
    }

    /**
     * Synchronisiert ein einzelnes tabellengebundenes DataForm.
     */
    public static function synchronizeBoundTableFields(
        PDO $pdo,
        int $dataformId
    ): array {
        self::ensureTableBindingSchema($pdo);
        if ($dataformId<1) {
            throw new RuntimeException('Ungültiges DataForm für die Tabellensynchronisation.');
        }

        $bindingStmt=$pdo->prepare(
            'SELECT dataform_id,source_kind,source_id,table_name,binding_mode '
            .'FROM dataform_table_bindings WHERE dataform_id=? LIMIT 1'
        );
        $bindingStmt->execute([$dataformId]);
        $binding=$bindingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$binding) {
            return ['created'=>0,'linked'=>0,'updated'=>0,'table'=>''];
        }

        $sourceKind=(string)($binding['source_kind']??'system');
        $bindingMode=(string)($binding['binding_mode']??'');
        if ($sourceKind==='external' && $bindingMode==='csv') {
            return self::synchronizeCsvBoundTableFields($pdo,$binding);
        }
        if ($sourceKind!=='system' || (int)($binding['source_id']??0)!==0) {
            throw new RuntimeException('Dieser externe Datenquellentyp wird in dieser Phase noch nicht fuer schreibende DataForms unterstuetzt.');
        }

        $table=(string)$binding['table_name'];
        self::assertSqlIdentifier($table);
        $exists=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            .'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $exists->execute([$table]);
        if ((int)$exists->fetchColumn()!==1) {
            throw new RuntimeException('Die gebundene Tabelle „'.$table.'“ existiert nicht.');
        }

        $columns=$pdo->query(
            'SHOW FULL COLUMNS FROM '.self::quoteIdentifier($table)
        )->fetchAll(PDO::FETCH_ASSOC);
        $physical=[];
        foreach ($columns as $column) {
            if (self::isTechnicalIdColumn($column)) {
                continue;
            }
            $name=(string)($column['Field']??'');
            if ($name==='') {
                continue;
            }
            $physical[strtolower($name)]=$column;
        }

        $fieldStmt=$pdo->prepare(
            'SELECT id,name,label,field_type,position,is_required,configuration_json '
            .'FROM dataform_fields WHERE dataform_id=? ORDER BY position,id'
        );
        $fieldStmt->execute([$dataformId]);
        $fields=$fieldStmt->fetchAll(PDO::FETCH_ASSOC);

        $covered=[];
        $linked=0;
        $configUpdate=$pdo->prepare(
            'UPDATE dataform_fields SET configuration_json=? '
            .'WHERE id=? AND dataform_id=?'
        );
        $fieldAndConfigUpdate=$pdo->prepare(
            'UPDATE dataform_fields SET field_type=?,configuration_json=? '
            .'WHERE id=? AND dataform_id=?'
        );
        $updated=0;

        foreach ($fields as $field) {
            $fieldName=(string)($field['name']??'');
            $raw=$field['configuration_json']??null;
            $cfg=[];
            if (is_string($raw) && trim($raw)!=='') {
                $decoded=json_decode($raw,true);
                if (is_array($decoded)) {
                    $cfg=$decoded;
                }
            }

            $configuredColumn='';
            if (isset($cfg['table_binding']) && is_array($cfg['table_binding'])) {
                $configuredColumn=trim((string)($cfg['table_binding']['column']??''));
            }
            $candidate=$configuredColumn!=='' ? $configuredColumn : $fieldName;
            $candidateKey=strtolower($candidate);
            $fieldKey=strtolower($fieldName);

            // Stale/fehlende Binding-Metadaten dürfen auf den identischen
            // realen Spaltennamen zurückfallen. Phantomfelder ohne physische
            // Spalte werden dabei bewusst nicht legitimiert.
            if (!isset($physical[$candidateKey]) && isset($physical[$fieldKey])) {
                $candidate=$fieldName;
                $candidateKey=$fieldKey;
            }
            if (!isset($physical[$candidateKey])) {
                continue;
            }

            $covered[$candidateKey]=true;
            $expected=[
                'source_kind'=>(string)($binding['source_kind']??'system'),
                'table'=>$table,
                'column'=>(string)($physical[$candidateKey]['Field']??$candidate),
                'sql_type'=>strtolower((string)($physical[$candidateKey]['Type']??'')),
            ];
            if ((int)($binding['source_id']??0)>0) {
                $expected['source_id']=(int)$binding['source_id'];
            }

            $current=isset($cfg['table_binding']) && is_array($cfg['table_binding'])
                ? $cfg['table_binding']
                : [];
            if (!empty($current['managed_field_column'])) {
                $expected['managed_field_column']=true;
            }
            $oldSqlType=strtolower(trim((string)($current['sql_type']??'')));
            $newSqlType=strtolower(trim((string)$expected['sql_type']));
            $currentFieldType=(string)($field['field_type']??'text');
            $expectedFieldType=self::fieldTypeForSqlType($newSqlType);
            $oldExpectedFieldType=$oldSqlType!==''
                ? self::fieldTypeForSqlType($oldSqlType)
                : null;

            // HF41: Eine physische SQL-Typänderung wird in den automatisch
            // abgeleiteten DataForm-Feldtyp übernommen. Benutzerdefinierte
            // Spezialtypen bleiben erhalten, weil nur der unveränderte alte
            // Standardtyp automatisch ersetzt wird.
            $syncFieldType=$oldSqlType!==''
                && $oldSqlType!==$newSqlType
                && $oldExpectedFieldType!==null
                && $currentFieldType===$oldExpectedFieldType
                && $currentFieldType!==$expectedFieldType;

            $needsLink=(string)($current['table']??'')!==$expected['table']
                || strcasecmp((string)($current['column']??''),$expected['column'])!==0
                || (string)($current['source_kind']??'')!==$expected['source_kind']
                || $oldSqlType!==$newSqlType
                || (int)($current['source_id']??0)!==(int)($expected['source_id']??0);
            if ($needsLink || $syncFieldType) {
                $cfg['table_binding']=$expected;
                $encoded=json_encode(
                    $cfg,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                );
                if ($syncFieldType) {
                    $fieldAndConfigUpdate->execute([
                        $expectedFieldType,
                        $encoded,
                        (int)$field['id'],
                        $dataformId,
                    ]);
                    $updated++;
                } else {
                    $configUpdate->execute([
                        $encoded,
                        (int)$field['id'],
                        $dataformId,
                    ]);
                }
                $linked++;
            }
        }

        $posStmt=$pdo->prepare(
            'SELECT COALESCE(MAX(position),0)+10 FROM dataform_fields WHERE dataform_id=?'
        );
        $posStmt->execute([$dataformId]);
        $position=(int)$posStmt->fetchColumn();
        if ($position<10) {
            $position=10;
        }

        $insert=$pdo->prepare(
            'INSERT INTO dataform_fields('
            .'dataform_id,name,label,field_type,position,is_required,configuration_json'
            .') VALUES(?,?,?,?,?,?,?)'
        );
        $created=0;
        foreach ($physical as $key=>$column) {
            if (isset($covered[$key])) {
                continue;
            }
            $mapped=self::mapColumn(
                $table,
                $column,
                (string)($binding['source_kind']??'system'),
                (int)($binding['source_id']??0)
            );

            // Sicherheitsnetz gegen doppelte Feldnamen, falls alte Metadaten
            // denselben Namen mit einer defekten Spaltenbindung enthalten.
            $sameName=$pdo->prepare(
                'SELECT id,configuration_json FROM dataform_fields '
                .'WHERE dataform_id=? AND LOWER(name)=LOWER(?) LIMIT 1'
            );
            $sameName->execute([$dataformId,$mapped['name']]);
            $existing=$sameName->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $cfg=[];
                $raw=$existing['configuration_json']??null;
                if (is_string($raw) && trim($raw)!=='') {
                    $decoded=json_decode($raw,true);
                    if (is_array($decoded)) {
                        $cfg=$decoded;
                    }
                }
                $cfg['table_binding']=$mapped['configuration']['table_binding'];
                $configUpdate->execute([
                    json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    (int)$existing['id'],
                    $dataformId,
                ]);
                $linked++;
                $covered[$key]=true;
                continue;
            }

            $insert->execute([
                $dataformId,
                $mapped['name'],
                $mapped['label'],
                $mapped['field_type'],
                $position,
                $mapped['is_required']?1:0,
                json_encode(
                    $mapped['configuration'],
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                ),
            ]);
            $position+=10;
            $created++;
            $covered[$key]=true;
        }

        if ($created>0 || $linked>0 || $updated>0) {
            self::syncFieldPositionsFromTable($pdo,$table);
        }

        return [
            'created'=>$created,
            'linked'=>$linked,
            'updated'=>$updated,
            'table'=>$table,
        ];
    }

    public static function syncFieldPositionsFromTable(
        PDO $pdo,
        string $table
    ): int {
        self::assertSqlIdentifier($table);
        $binding=self::tableBinding($pdo,$table,'system',null);
        if ($binding === null) return 0;

        $dataformId=(int)$binding['dataform_id'];
        $columns=$pdo->query(
            'SHOW FULL COLUMNS FROM '.self::quoteIdentifier($table)
        )->fetchAll(PDO::FETCH_ASSOC);

        $stmt=$pdo->prepare(
            'SELECT id,name,position,configuration_json FROM dataform_fields '
            .'WHERE dataform_id=? ORDER BY position,id'
        );
        $stmt->execute([$dataformId]);
        $fields=$stmt->fetchAll(PDO::FETCH_ASSOC);

        $bySourceColumn=[];
        foreach ($fields as $field) {
            $sourceColumn='';
            $raw=$field['configuration_json']??null;
            if (is_string($raw) && trim($raw) !== '') {
                $cfg=json_decode($raw,true);
                if (is_array($cfg) && isset($cfg['table_binding']) && is_array($cfg['table_binding'])) {
                    $sourceColumn=(string)($cfg['table_binding']['column']??'');
                }
            }
            if ($sourceColumn === '') $sourceColumn=(string)$field['name'];
            $bySourceColumn[strtolower($sourceColumn)]=$field;
        }

        $orderedIds=[];
        foreach ($columns as $column) {
            if (self::isTechnicalIdColumn($column)) continue;
            $sourceName=strtolower((string)($column['Field']??''));
            if ($sourceName !== '' && isset($bySourceColumn[$sourceName])) {
                $orderedIds[]=(int)$bySourceColumn[$sourceName]['id'];
            }
        }
        foreach ($fields as $field) {
            $fieldId=(int)$field['id'];
            if (!in_array($fieldId,$orderedIds,true)) $orderedIds[]=$fieldId;
        }

        $update=$pdo->prepare(
            'UPDATE dataform_fields SET position=? WHERE id=? AND dataform_id=?'
        );
        $position=10;
        foreach ($orderedIds as $fieldId) {
            $update->execute([$position,$fieldId,$dataformId]);
            $position+=10;
        }
        return count($orderedIds);
    }

    /**
     * Synchronisiert Feldmetadaten eines an CSV gebundenen DataForms mit der
     * aktuellen Kopfzeile der CSV-Tabelle. CSV besitzt absichtlich keinen
     * impliziten SQL-Typ; alle physischen Werte werden als Text transportiert.
     */
    private static function synchronizeCsvBoundTableFields(PDO $pdo,array $binding): array
    {
        $sourceId=(int)($binding['source_id']??0);
        $dataformId=(int)($binding['dataform_id']??0);
        $table=(string)($binding['table_name']??'');
        self::assertSqlIdentifier($table);
        if ($dataformId<1 || $sourceId<1) {
            throw new RuntimeException('Ungueltige CSV-Tabellenbindung.');
        }

        [, $db]=self::csvSourceDatabase($pdo,$sourceId);
        try {
            if (!$db->tableExists($table)) {
                throw new RuntimeException('Die gebundene CSV-Tabelle „'.$table.'“ existiert nicht.');
            }
            $columnNames=$db->columns($table);
        } finally {
            $db->disconnect();
        }

        if ($columnNames===[] || ($columnNames[0]??'')!=='id') {
            throw new RuntimeException('Die gebundene CSV-Tabelle besitzt keine gueltige Pflichtspalte id.');
        }
        foreach ($columnNames as $name) {
            self::assertSqlIdentifier($name);
        }

        $fieldStmt=$pdo->prepare(
            'SELECT id,name,label,field_type,position,is_required,configuration_json '
            .'FROM dataform_fields WHERE dataform_id=? ORDER BY position,id'
        );
        $fieldStmt->execute([$dataformId]);
        $fields=$fieldStmt->fetchAll(PDO::FETCH_ASSOC);

        $physical=[];
        foreach ($columnNames as $name) {
            if ($name==='id') {
                continue;
            }
            $physical[strtolower($name)]=$name;
        }

        $covered=[];
        $linked=0;
        $updated=0;
        $configUpdate=$pdo->prepare(
            'UPDATE dataform_fields SET configuration_json=? WHERE id=? AND dataform_id=?'
        );

        foreach ($fields as $field) {
            $fieldName=(string)($field['name']??'');
            $cfg=[];
            $raw=$field['configuration_json']??null;
            if (is_string($raw) && trim($raw)!=='') {
                $decoded=json_decode($raw,true);
                if (is_array($decoded)) {
                    $cfg=$decoded;
                }
            }

            $current=is_array($cfg['table_binding']??null)?$cfg['table_binding']:[];
            $configured=trim((string)($current['column']??''));
            $candidate=$configured!==''?$configured:$fieldName;
            $candidateKey=strtolower($candidate);
            $fieldKey=strtolower($fieldName);
            if (!isset($physical[$candidateKey]) && isset($physical[$fieldKey])) {
                $candidate=$fieldName;
                $candidateKey=$fieldKey;
            }
            if (!isset($physical[$candidateKey])) {
                continue;
            }

            $columnName=$physical[$candidateKey];
            $covered[$candidateKey]=true;
            $expected=[
                'source_kind'=>'external',
                'source_id'=>$sourceId,
                'table'=>$table,
                'column'=>$columnName,
                'sql_type'=>'text',
                'storage'=>'csv',
            ];
            $needsLink=(string)($current['source_kind']??'')!=='external'
                || (int)($current['source_id']??0)!==$sourceId
                || (string)($current['table']??'')!==$table
                || strcasecmp((string)($current['column']??''),$columnName)!==0
                || strtolower((string)($current['sql_type']??''))!=='text'
                || (string)($current['storage']??'')!=='csv';
            if ($needsLink) {
                $cfg['table_binding']=$expected;
                $configUpdate->execute([
                    json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),
                    (int)$field['id'],
                    $dataformId,
                ]);
                $linked++;
            }
        }

        $posStmt=$pdo->prepare(
            'SELECT COALESCE(MAX(position),0)+10 FROM dataform_fields WHERE dataform_id=?'
        );
        $posStmt->execute([$dataformId]);
        $position=max(10,(int)$posStmt->fetchColumn());
        $insert=$pdo->prepare(
            'INSERT INTO dataform_fields('
            .'dataform_id,name,label,field_type,position,is_required,configuration_json'
            .') VALUES(?,?,?,?,?,?,?)'
        );
        $created=0;
        foreach ($columnNames as $columnName) {
            if ($columnName==='id') {
                continue;
            }
            $key=strtolower($columnName);
            if (isset($covered[$key])) {
                continue;
            }
            $synthetic=[
                'Field'=>$columnName,
                'Type'=>'text',
                'Null'=>'YES',
                'Key'=>'',
                'Default'=>null,
                'Extra'=>'csv',
            ];
            $mapped=self::mapColumn($table,$synthetic,'external',$sourceId);
            $mapped['configuration']['table_binding']['storage']='csv';
            $insert->execute([
                $dataformId,
                $mapped['name'],
                $mapped['label'],
                $mapped['field_type'],
                $position,
                0,
                json_encode(
                    $mapped['configuration'],
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR
                ),
            ]);
            $position+=10;
            $created++;
        }

        if ($created>0 || $linked>0) {
            self::syncFieldPositionsByColumns($pdo,$dataformId,$columnNames);
        }

        return [
            'created'=>$created,
            'linked'=>$linked,
            'updated'=>$updated,
            'table'=>$table,
            'source_kind'=>'external',
            'source_id'=>$sourceId,
        ];
    }

    private static function syncFieldPositionsByColumns(PDO $pdo,int $dataformId,array $columnNames): int
    {
        $stmt=$pdo->prepare(
            'SELECT id,name,position,configuration_json FROM dataform_fields '
            .'WHERE dataform_id=? ORDER BY position,id'
        );
        $stmt->execute([$dataformId]);
        $fields=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $bySource=[];
        foreach ($fields as $field) {
            $sourceColumn='';
            $raw=$field['configuration_json']??null;
            if (is_string($raw) && trim($raw)!=='') {
                $cfg=json_decode($raw,true);
                if (is_array($cfg) && is_array($cfg['table_binding']??null)) {
                    $sourceColumn=(string)($cfg['table_binding']['column']??'');
                }
            }
            if ($sourceColumn==='') {
                $sourceColumn=(string)$field['name'];
            }
            $bySource[strtolower($sourceColumn)]=$field;
        }

        $ordered=[];
        foreach ($columnNames as $columnName) {
            if ($columnName==='id') {
                continue;
            }
            $key=strtolower((string)$columnName);
            if (isset($bySource[$key])) {
                $ordered[]=(int)$bySource[$key]['id'];
            }
        }
        foreach ($fields as $field) {
            $id=(int)$field['id'];
            if (!in_array($id,$ordered,true)) {
                $ordered[]=$id;
            }
        }

        $update=$pdo->prepare('UPDATE dataform_fields SET position=? WHERE id=? AND dataform_id=?');
        $position=10;
        foreach ($ordered as $id) {
            $update->execute([$position,$id,$dataformId]);
            $position+=10;
        }
        return count($ordered);
    }

    /**
     * @return array{0:array,1:\DataForm\Database\Contracts\DatabaseInterface}
     */
    private static function csvSourceDatabase(PDO $pdo,int $sourceId): array
    {
        $source=DataSourceManager::find($pdo,$sourceId);
        if (!$source) {
            throw new RuntimeException('Die CSV-Datenquelle wurde nicht gefunden.');
        }
        if (empty($source['is_enabled'])) {
            throw new RuntimeException('Die CSV-Datenquelle ist deaktiviert.');
        }
        if ((string)$source['driver']!=='csv') {
            throw new RuntimeException('Die gewaehlte externe Datenquelle ist keine CSV-Datenquelle.');
        }
        $config=DataSourceManager::runtimeConfig($source,'');
        $db=\DataForm\Database\DatabaseFactory::create($config+['auto_connect'=>true]);
        return [$source,$db];
    }

    private static function mapColumn(
        string $table,
        array $column,
        string $sourceKind='system',
        int $sourceId=0
    ): array
    {
        $sourceColumn=(string)($column['Field']??'');
        self::assertSqlIdentifier($sourceColumn);
        $name=strtolower($sourceColumn);
        $type=strtolower((string)($column['Type']??''));
        $nullable=strtoupper((string)($column['Null']??'YES'))==='YES';
        $default=$column['Default']??null;
        $extra=strtolower((string)($column['Extra']??''));

        $fieldType='text';
        if (preg_match('/^tinyint\(1\)/',$type)===1) {
            $fieldType='checkbox';
        } elseif (preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)/',$type)===1) {
            $fieldType='integer';
        } elseif (preg_match('/^(?:decimal|numeric|float|double|real)/',$type)===1) {
            $fieldType='number';
        } elseif (preg_match('/^date(?:$|\s)/',$type)===1) {
            $fieldType='date';
        } elseif (preg_match('/^time(?:$|\s)/',$type)===1) {
            $fieldType='time';
        } elseif (preg_match('/^(?:datetime|timestamp)/',$type)===1) {
            $fieldType='datetime';
        } elseif (preg_match('/^json(?:$|\s)/',$type)===1) {
            $fieldType='json';
        } elseif (preg_match('/(?:tinyblob|blob|mediumblob|longblob|binary|varbinary)/',$type)===1) {
            $fieldType='file';
        } elseif (preg_match('/(?:tinytext|text|mediumtext|longtext)/',$type)===1) {
            $fieldType='textarea';
        } elseif (preg_match('/^enum\((.*)\)$/',$type,$enumMatch)===1) {
            $fieldType='select';
        }

        $configuration=[
            'width'=>'100',
            'trim'=>true,
            'list_visible'=>true,
            'searchable'=>true,
            'filterable'=>true,
            'sortable'=>true,
            'table_binding'=>[
                'source_kind'=>$sourceKind,
                'table'=>$table,
                'column'=>$sourceColumn,
                'sql_type'=>$type,
            ],
        ];
        if ($sourceId>0) {
            $configuration['table_binding']['source_id']=$sourceId;
        }

        if ($default !== null) {
            $defaultString=(string)$default;
            if (preg_match('/^current_timestamp(?:\\(\\))?$/i',trim($defaultString))===1) {
                $configuration['default_mode']='current_timestamp';
                $configuration['default_value']='';
            } else {
                $configuration['default_mode']='defined';
                $configuration['default_value']=$defaultString;
            }
        } else {
            $configuration['default_mode']='defined';
            $configuration['default_value']='';
        }

        if ($fieldType==='select' && isset($enumMatch[1])) {
            $configuration['options']=self::parseEnumOptions((string)$enumMatch[1]);
        }
        if (str_contains($extra,'auto_increment')) {
            $configuration['readonly']=true;
        }

        return [
            'name'=>$name,
            'label'=>self::humanize($sourceColumn),
            'field_type'=>$fieldType,
            'is_required'=>!$nullable && !str_contains($extra,'auto_increment'),
            'configuration'=>$configuration,
        ];
    }

    /**
     * HF41: Kanonische Abbildung eines physischen SQL-Typs auf den
     * automatisch erzeugten DataForm-Feldtyp.
     */
    private static function fieldTypeForSqlType(string $type): string
    {
        $type=strtolower(trim($type));
        if (preg_match('/^tinyint\(1\)/',$type)===1) return 'checkbox';
        if (preg_match('/^(?:tinyint|smallint|mediumint|int|integer|bigint)/',$type)===1) return 'number';
        if (preg_match('/^(?:decimal|numeric|float|double|real)/',$type)===1) return 'number';
        if (preg_match('/^date(?:$|\s)/',$type)===1) return 'date';
        if (preg_match('/^time(?:$|\s)/',$type)===1) return 'time';
        if (preg_match('/^(?:datetime|timestamp)/',$type)===1) return 'datetime';
        if (preg_match('/^json(?:$|\s)/',$type)===1) return 'json';
        if (preg_match('/(?:tinyblob|blob|mediumblob|longblob|binary|varbinary)/',$type)===1) return 'file';
        if (preg_match('/(?:tinytext|text|mediumtext|longtext)/',$type)===1) return 'textarea';
        if (preg_match('/^enum\((.*)\)$/',$type)===1) return 'select';
        return 'text';
    }

    private static function parseEnumOptions(string $raw): array
    {
        $options=str_getcsv($raw,',',"'",'\\\\');
        return array_values(array_filter(
            array_map(static fn($v): string => trim((string)$v),$options),
            static fn(string $v): bool => $v!==''
        ));
    }

    private static function isTechnicalIdColumn(array $column): bool
    {
        return strtolower((string)($column['Field']??''))==='id'
            && strtoupper((string)($column['Key']??''))==='PRI'
            && str_contains(strtolower((string)($column['Extra']??'')),'auto_increment');
    }

    private static function uniqueSlug(PDO $pdo,string $table): string
    {
        $base=strtolower($table);
        $base=preg_replace('/[^a-z0-9]+/','-',$base)??'';
        $base=trim($base,'-');
        if ($base==='') {
            $base='dataform';
        }
        $base=substr($base,0,145);

        $candidate=$base;
        $suffix=2;
        $check=$pdo->prepare('SELECT COUNT(*) FROM dataforms WHERE slug=?');
        while (true) {
            $check->execute([$candidate]);
            if ((int)$check->fetchColumn()===0) {
                return $candidate;
            }
            $candidate=substr($base,0,145-strlen((string)$suffix)-1).'-'.$suffix;
            $suffix++;
        }
    }

    private static function humanize(string $value): string
    {
        $value=preg_replace('/[_-]+/',' ',$value)??$value;
        $value=preg_replace('/\\s+/',' ',trim($value))??trim($value);
        if ($value==='') return '';
        return mb_convert_case($value,MB_CASE_TITLE,'UTF-8');
    }

    private static function assertSqlIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/',$identifier)) {
            throw new RuntimeException('Ungültiger Tabellen- oder Feldname.');
        }
    }

    public static function delete(PDO $pdo, int $dataformId): array
    {
        if ($dataformId < 1) {
            throw new RuntimeException('Ungültige DataForm-ID.');
        }

        $stmt=$pdo->prepare('SELECT id,name,slug FROM dataforms WHERE id=? LIMIT 1');
        $stmt->execute([$dataformId]);
        $dataform=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dataform) {
            throw new RuntimeException('Das DataForm wurde nicht gefunden oder bereits gelöscht.');
        }

        $summary=[
            'name'=>(string)$dataform['name'],
            'relations'=>0,
            'lookup_fields'=>0,
            'records'=>0,
            'fields'=>0,
            'queries'=>0,
            'reports'=>0,
            'api_endpoints'=>0,
            'workflow'=>0,
            'configuration'=>0,
        ];

        $pdo->beginTransaction();
        try {
            // Relations may point to the DataForm from either side and have no FK.
            $relationIds=[];
            $lookupFieldIds=[];
            if (self::tableExists($pdo,'dataform_relations')) {
                $stmt=$pdo->prepare(
                    'SELECT id,lookup_field_id FROM dataform_relations '
                    .'WHERE source_dataform_id=? OR target_dataform_id=?'
                );
                $stmt->execute([$dataformId,$dataformId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $relationIds[]=(int)$row['id'];
                    if ((int)($row['lookup_field_id']??0)>0) {
                        $lookupFieldIds[]=(int)$row['lookup_field_id'];
                    }
                }
                if ($relationIds) {
                    $summary['relations']=self::deleteByIds(
                        $pdo,'dataform_relations','id',$relationIds
                    );
                }
            }

            // Auto-generated lookup fields can live in another DataForm when the
            // deleted DataForm was the parent/target.
            if ($lookupFieldIds && self::tableExists($pdo,'dataform_fields')) {
                $summary['lookup_fields']=self::deleteByIds(
                    $pdo,
                    'dataform_fields',
                    'id',
                    $lookupFieldIds,
                    "field_type='lookup'"
                );
            }

            // Reset stale parent-default references in surviving fields.
            if ($relationIds && self::tableExists($pdo,'dataform_fields')) {
                $stmt=$pdo->query(
                    "SELECT id,configuration_json FROM dataform_fields "
                    ."WHERE configuration_json IS NOT NULL AND configuration_json<>''"
                );
                $update=$pdo->prepare(
                    'UPDATE dataform_fields SET configuration_json=? WHERE id=?'
                );
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cfg=json_decode((string)$row['configuration_json'],true);
                    if (!is_array($cfg)) continue;
                    if (
                        ($cfg['default_mode']??'')==='parent_field'
                        && in_array((int)($cfg['parent_relation_id']??0),$relationIds,true)
                    ) {
                        $cfg['default_mode']='defined';
                        $cfg['default_value']='';
                        $cfg['parent_relation_id']=0;
                        $cfg['parent_field_id']=0;
                        $update->execute([
                            json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                            (int)$row['id'],
                        ]);
                        $summary['configuration']++;
                    }
                }
            }

            // Queries are dependencies of reports and API endpoints, so collect
            // them before deleting any source configuration.
            $queryIds=[];
            if (self::tableExists($pdo,'dataform_queries')) {
                $stmt=$pdo->prepare('SELECT id FROM dataform_queries WHERE dataform_id=?');
                $stmt->execute([$dataformId]);
                $queryIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            // Reports can directly use the DataForm or one of its saved queries.
            $reportIds=[];
            if (self::tableExists($pdo,'reports')) {
                $where=['(source_type=? AND source_id=?)'];
                $params=['dataform',$dataformId];
                if ($queryIds) {
                    $where[]='(source_type=? AND source_id IN ('.self::placeholders($queryIds).'))';
                    $params[]='query';
                    array_push($params,...$queryIds);
                }
                $stmt=$pdo->prepare('SELECT id FROM reports WHERE '.implode(' OR ',$where));
                $stmt->execute($params);
                $reportIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($reportIds) {
                foreach (['report_exports','report_parameters','report_elements','report_pages'] as $table) {
                    if (self::tableExists($pdo,$table)) {
                        self::deleteByIds($pdo,$table,'report_id',$reportIds);
                    }
                }
                if (self::tableExists($pdo,'reports')) {
                    $summary['reports']=self::deleteByIds($pdo,'reports','id',$reportIds);
                }
            }

            // API endpoints can directly reference the DataForm or one of its queries.
            $endpointIds=[];
            if (self::tableExists($pdo,'api_endpoints')) {
                $where=['dataform_id=?'];
                $params=[$dataformId];
                if ($queryIds) {
                    $where[]='query_id IN ('.self::placeholders($queryIds).')';
                    array_push($params,...$queryIds);
                }
                $stmt=$pdo->prepare('SELECT id FROM api_endpoints WHERE '.implode(' OR ',$where));
                $stmt->execute($params);
                $endpointIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
                if ($endpointIds && self::tableExists($pdo,'api_request_log')) {
                    self::updateNullByIds($pdo,'api_request_log','endpoint_id',$endpointIds);
                }
                if ($endpointIds) {
                    $summary['api_endpoints']=self::deleteByIds(
                        $pdo,'api_endpoints','id',$endpointIds
                    );
                }
            }

            if ($queryIds && self::tableExists($pdo,'dataform_queries')) {
                $summary['queries']=self::deleteByIds(
                    $pdo,'dataform_queries','id',$queryIds
                );
            }

            // Workflow tables were historically created without FKs.
            $transitionIds=[];
            if (self::tableExists($pdo,'workflow_transitions')) {
                $stmt=$pdo->prepare('SELECT id FROM workflow_transitions WHERE dataform_id=?');
                $stmt->execute([$dataformId]);
                $transitionIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($transitionIds && self::tableExists($pdo,'workflow_actions')) {
                $summary['workflow']+=self::deleteByIds(
                    $pdo,'workflow_actions','transition_id',$transitionIds
                );
            }
            foreach (['workflow_logs','workflow_permissions','workflow_transitions','workflow_states'] as $table) {
                if (self::tableExists($pdo,$table)) {
                    $summary['workflow']+=self::deleteWhereDataform($pdo,$table,$dataformId);
                }
            }

            // Import changes reference import runs rather than the DataForm directly.
            $importRunIds=[];
            if (self::tableExists($pdo,'dataform_import_runs')) {
                $stmt=$pdo->prepare('SELECT id FROM dataform_import_runs WHERE dataform_id=?');
                $stmt->execute([$dataformId]);
                $importRunIds=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN));
            }
            if ($importRunIds && self::tableExists($pdo,'dataform_import_changes')) {
                self::deleteByIds($pdo,'dataform_import_changes','import_run_id',$importRunIds);
            }

            // Remaining DataForm-scoped tables. Some have ON DELETE CASCADE in
            // newer builds, but explicit cleanup also handles older project schemas.
            foreach ([
                'dataform_import_profiles','dataform_import_runs',
                'dataform_saved_filters','dataform_list_settings',
                'dataform_layout_nodes','dataform_behaviors','dataform_versions'
            ] as $table) {
                if (self::tableExists($pdo,$table)) {
                    $summary['configuration']+=self::deleteWhereDataform(
                        $pdo,$table,$dataformId
                    );
                }
            }

            if (self::tableExists($pdo,'dataform_records')) {
                $summary['records']=self::deleteWhereDataform(
                    $pdo,'dataform_records',$dataformId
                );
            }
            if (self::tableExists($pdo,'dataform_fields')) {
                $summary['fields']=self::deleteWhereDataform(
                    $pdo,'dataform_fields',$dataformId
                );
            }

            $delete=$pdo->prepare('DELETE FROM dataforms WHERE id=?');
            $delete->execute([$dataformId]);
            if ($delete->rowCount() !== 1) {
                throw new RuntimeException('Das DataForm konnte nicht gelöscht werden.');
            }

            $pdo->commit();
            return $summary;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function deleteWhereDataform(
        PDO $pdo,
        string $table,
        int $dataformId
    ): int {
        $sql='DELETE FROM '.self::quoteIdentifier($table)
            .' WHERE dataform_id=?';
        $stmt=$pdo->prepare($sql);
        $stmt->execute([$dataformId]);
        return $stmt->rowCount();
    }

    private static function deleteByIds(
        PDO $pdo,
        string $table,
        string $column,
        array $ids,
        string $extraWhere=''
    ): int {
        if (!$ids) return 0;
        $sql='DELETE FROM '.self::quoteIdentifier($table)
            .' WHERE '.self::quoteIdentifier($column)
            .' IN ('.self::placeholders($ids).')';
        if ($extraWhere !== '') $sql.=' AND '.$extraWhere;
        $stmt=$pdo->prepare($sql);
        $stmt->execute(array_values($ids));
        return $stmt->rowCount();
    }

    private static function updateNullByIds(
        PDO $pdo,
        string $table,
        string $column,
        array $ids
    ): void {
        if (!$ids) return;
        $sql='UPDATE '.self::quoteIdentifier($table)
            .' SET '.self::quoteIdentifier($column).'=NULL'
            .' WHERE '.self::quoteIdentifier($column)
            .' IN ('.self::placeholders($ids).')';
        $stmt=$pdo->prepare($sql);
        $stmt->execute(array_values($ids));
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            .'WHERE table_schema=DATABASE() AND table_name=?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn()===1;
    }

    private static function placeholders(array $values): string
    {
        return implode(',',array_fill(0,count($values),'?'));
    }

    private static function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/',$identifier)) {
            throw new RuntimeException('Ungültiger interner SQL-Bezeichner.');
        }
        return '`'.$identifier.'`';
    }
}
