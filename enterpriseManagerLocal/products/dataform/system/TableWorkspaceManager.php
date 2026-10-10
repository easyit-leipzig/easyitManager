<?php
declare(strict_types=1);

require_once __DIR__ . '/DataSourceManager.php';
require_once dirname(__DIR__, 3) . '/DataForm5-Core/system/database/autoload.php';

use DataForm\Database\DatabaseFactory;

final class TableWorkspaceManager
{
    public static function ensureRegistry(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS dataform_managed_tables (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_dataform_managed_table(table_name)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci"
        );
    }

    public static function isManaged(PDO $pdo, string $table): bool
    {
        self::ensureRegistry($pdo);
        $stmt=$pdo->prepare(
            'SELECT COUNT(*) FROM dataform_managed_tables WHERE table_name=?'
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() === 1;
    }


    /**
     * HF40: Projekt-Anwendungstabellen dürfen auf Feldebene per CRUD gepflegt
     * werden, auch wenn sie nicht ursprünglich durch den Tabellenassistenten
     * angelegt und deshalb noch nicht in dataform_managed_tables registriert
     * wurden. Interne DataForm-/Workflow-/Migrations-Tabellen bleiben strikt
     * geschützt.
     */
    public static function isProtectedInternalTable(string $table): bool
    {
        $normalized=strtolower(trim($table));
        if ($normalized === '') {
            return true;
        }

        if (
            str_starts_with($normalized,'dataform_')
            || str_starts_with($normalized,'workflow_')
        ) {
            return true;
        }

        return in_array(
            $normalized,
            ['data_sources','migrations'],
            true
        );
    }

    public static function isApplicationTable(PDO $pdo,string $table): bool
    {
        self::assertManagedIdentifier($table,'Tabellenname');
        if (self::isProtectedInternalTable($table)) {
            return false;
        }
        if (!self::systemTableExists($pdo,$table)) {
            return false;
        }

        if (self::isOraclePdo($pdo)) {
            $stmt=$pdo->prepare("SELECT 'BASE TABLE' FROM user_tables WHERE table_name=UPPER(?)");
            $stmt->execute([$table]);
            return $stmt->fetchColumn() !== false;
        }

        $stmt=$pdo->prepare(
            "SELECT table_type FROM information_schema.tables "
            ."WHERE table_schema=DATABASE() AND table_name=? LIMIT 1"
        );
        $stmt->execute([$table]);
        $type=strtoupper((string)$stmt->fetchColumn());
        return $type === 'BASE TABLE';
    }

    public static function fieldCrudAllowed(PDO $pdo,string $table): bool
    {
        return self::isManaged($pdo,$table)
            || self::isApplicationTable($pdo,$table);
    }

    public static function createManagedTable(
        PDO $pdo,
        string $table,
        string $columnSpecification
    ): void {
        self::ensureRegistry($pdo);
        self::assertManagedIdentifier($table, 'Tabellenname');

        if (self::systemTableExists($pdo, $table)) {
            throw new RuntimeException('Eine Tabelle mit diesem Namen existiert bereits.');
        }

        $columns=self::parseColumns($columnSpecification);
        if ($columns === []) {
            throw new RuntimeException(
                'Geben Sie mindestens eine zusätzliche Spalte an.'
            );
        }

        $defs=[
            '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'
        ];
        foreach ($columns as $column) {
            $defs[]='`'.str_replace('`','``',$column['name']).'` '
                .$column['sql'].' NULL';
        }

        try {
            $pdo->exec(
                'CREATE TABLE `'.str_replace('`','``',$table).'` ('
                .implode(', ', $defs)
                .') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 '
                .'COLLATE=utf8mb4_unicode_ci'
            );
            $stmt=$pdo->prepare(
                'INSERT INTO dataform_managed_tables(table_name) VALUES(?)'
            );
            $stmt->execute([$table]);
        } catch (Throwable $e) {
            if (self::systemTableExists($pdo, $table)) {
                try {
                    $pdo->exec(
                        'DROP TABLE `'.str_replace('`','``',$table).'`'
                    );
                } catch (Throwable) {
                }
            }
            throw $e;
        }
    }

    public static function dropManagedTable(PDO $pdo, string $table): void
    {
        self::assertManagedIdentifier($table, 'Tabellenname');
        if (!self::isManaged($pdo, $table)) {
            throw new RuntimeException(
                'Aus Sicherheitsgründen können nur Tabellen gelöscht werden, '
                .'die über die DataForm-Tabellenverwaltung angelegt wurden.'
            );
        }

        $bindingTableExists=(int)$pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            ."WHERE table_schema=DATABASE() AND table_name='dataform_table_bindings'"
        )->fetchColumn()===1;
        if ($bindingTableExists) {
            $binding=$pdo->prepare(
                "SELECT b.dataform_id,d.name "
                ."FROM dataform_table_bindings b "
                ."JOIN dataforms d ON d.id=b.dataform_id "
                ."WHERE b.source_kind='system' "
                ."AND b.source_id=0 AND b.table_name=? LIMIT 1"
            );
            $binding->execute([$table]);
            $bound=$binding->fetch(PDO::FETCH_ASSOC);
            if ($bound) {
                throw new RuntimeException(
                    'Die Tabelle ist mit dem DataForm „'.(string)$bound['name']
                    .'“ verbunden. Löschen Sie zuerst das zugehörige DataForm.'
                );
            }
        }
        if (!self::systemTableExists($pdo, $table)) {
            $stmt=$pdo->prepare(
                'DELETE FROM dataform_managed_tables WHERE table_name=?'
            );
            $stmt->execute([$table]);
            throw new RuntimeException(
                'Die registrierte Tabelle existiert physisch nicht mehr.'
            );
        }

        $pdo->exec(
            'DROP TABLE `'.str_replace('`','``',$table).'`'
        );
        $stmt=$pdo->prepare(
            'DELETE FROM dataform_managed_tables WHERE table_name=?'
        );
        $stmt->execute([$table]);
    }

    public static function normalizeColumnRequest(
        PDO $pdo,
        string $table,
        ?string $currentColumn,
        string $type,
        bool $nullable,
        string $defaultKind,
        ?string $defaultValue,
        array $extras,
        string $indexKind
    ): array {
        self::assertFieldCrudTable($pdo,$table);

        $baseType=strtolower(trim($type));
        $messages=[];
        $isPgsql=self::isPgsqlPdo($pdo);
        if($isPgsql && $baseType==='datetime'){
            $baseType='timestamp';
            $messages[]='PostgreSQL verwendet für DATETIME den nativen Typ TIMESTAMP.';
        }
        self::normalizeColumnType($baseType);

        $numeric=preg_match(
            '/^(?:int|integer|bigint|decimal\(\d{1,2},\d{1,2}\))$/',
            $baseType
        )===1;
        $integer=in_array($baseType,['int','integer','bigint'],true);
        $temporal=in_array($baseType,['datetime','timestamp'],true);
        $indexable=$baseType !== 'text';

        $extras=self::normalizeExtras($extras);
        $defaultKind=strtolower(trim($defaultKind));
        if (!in_array(
            $defaultKind,
            ['none','null','literal','current_timestamp'],
            true
        )) {
            $defaultKind='none';
        }
        $indexKind=self::normalizeIndexKind($indexKind);

        $removeExtra=static function(
            string $extra,
            string $message
        ) use (&$extras,&$messages): void {
            $before=count($extras);
            $extras=array_values(array_filter(
                $extras,
                static fn(string $value): bool => $value !== $extra
            ));
            if (count($extras) !== $before) {
                $messages[]=$message;
            }
        };

        if($isPgsql){
            $removeExtra('unsigned','UNSIGNED wurde entfernt: PostgreSQL unterstützt diese MySQL-Eigenschaft nicht.');
            $removeExtra('zerofill','ZEROFILL wurde entfernt: PostgreSQL unterstützt diese MySQL-Eigenschaft nicht.');
            $removeExtra('auto_increment','AUTO_INCREMENT wurde entfernt: PostgreSQL verwendet für automatisch erzeugte Schlüssel Identity/Sequenzen; normale DataForm-Felder werden hier nicht nachträglich zu AUTO_INCREMENT umgebaut.');
        }

        if (!$numeric) {
            $removeExtra(
                'unsigned',
                'UNSIGNED wurde entfernt, weil der gewählte Datentyp nicht numerisch ist.'
            );
            $removeExtra(
                'zerofill',
                'ZEROFILL wurde entfernt, weil der gewählte Datentyp nicht numerisch ist.'
            );
        }

        if (
            $numeric
            && in_array('zerofill',$extras,true)
            && !in_array('unsigned',$extras,true)
        ) {
            $extras[]='unsigned';
            $messages[]='UNSIGNED wurde automatisch ergänzt, weil ZEROFILL diese Eigenschaft voraussetzt.';
        }

        if (!$integer) {
            $removeExtra(
                'auto_increment',
                'AUTO_INCREMENT wurde entfernt, weil es nur für INT/BIGINT möglich ist.'
            );
        }

        if ($nullable) {
            $removeExtra(
                'auto_increment',
                'AUTO_INCREMENT wurde entfernt, weil das Feld NULL-Werte zulässt.'
            );
        }

        if (in_array('auto_increment',$extras,true)) {
            if (self::isOraclePdo($pdo)) {
                $stmt=$pdo->prepare(
                    "SELECT LOWER(column_name) FROM user_tab_columns
                     WHERE table_name=UPPER(?) AND identity_column='YES'
                       AND (? IS NULL OR column_name<>UPPER(?)) FETCH FIRST 1 ROWS ONLY"
                );
            } else {
                $stmt=$pdo->prepare(
                    "SELECT column_name
                     FROM information_schema.columns
                     WHERE table_schema=DATABASE()
                       AND table_name=?
                       AND LOWER(extra) LIKE '%auto_increment%'
                       AND (? IS NULL OR column_name<>?)
                     LIMIT 1"
                );
            }
            $stmt->execute([$table,$currentColumn,$currentColumn]);
            $existing=$stmt->fetchColumn();
            if ($existing !== false) {
                $removeExtra(
                    'auto_increment',
                    'AUTO_INCREMENT wurde entfernt, weil die Tabelle bereits das AUTO_INCREMENT-Feld „'
                    .(string)$existing.'“ besitzt.'
                );
            }
        }

        if (!$temporal) {
            $removeExtra(
                'on_update_current_timestamp',
                'ON UPDATE CURRENT_TIMESTAMP wurde entfernt, weil es nur für DATETIME/TIMESTAMP möglich ist.'
            );
        }

        if ($defaultKind === 'current_timestamp' && !$temporal) {
            $defaultKind='none';
            $defaultValue=null;
            $messages[]='CURRENT_TIMESTAMP wurde als Vorgabewert zurückgesetzt, weil der gewählte Datentyp nicht DATETIME/TIMESTAMP ist.';
        }

        if ($defaultKind === 'null' && !$nullable) {
            $defaultKind='none';
            $defaultValue=null;
            $messages[]='DEFAULT NULL wurde zurückgesetzt, weil das Feld keine NULL-Werte zulässt.';
        }

        if (
            in_array('auto_increment',$extras,true)
            && $defaultKind !== 'none'
        ) {
            $defaultKind='none';
            $defaultValue=null;
            $messages[]='Der Vorgabewert wurde entfernt, weil AUTO_INCREMENT keinen eigenen Vorgabewert zulässt.';
        }

        if ($indexKind !== 'none' && !$indexable) {
            $indexKind='none';
            $messages[]='Der Sekundärindex wurde entfernt, weil TEXT ohne Präfix in dieser Feldverwaltung nicht indexiert wird.';
        }

        return [
            'type'=>$baseType,
            'nullable'=>$nullable,
            'default_kind'=>$defaultKind,
            'default_value'=>$defaultValue,
            'extras'=>array_values(array_unique($extras)),
            'index_kind'=>$indexKind,
            'messages'=>array_values(array_unique($messages)),
        ];
    }

    public static function addManagedColumn(
        PDO $pdo,
        string $table,
        string $column,
        string $type,
        bool $nullable,
        string $defaultKind='none',
        ?string $defaultValue=null,
        array $extras=[],
        string $indexKind='none'
    ): void {
        self::assertFieldCrudTable($pdo,$table);
        self::assertManagedIdentifier($column,'Feldname');
        if ($column === 'id') {
            throw new RuntimeException('Das Pflichtfeld id wird von DataForm verwaltet.');
        }
        if (self::columnMetadata($pdo,$table,$column) !== null) {
            throw new RuntimeException('Ein Feld mit diesem Namen existiert bereits.');
        }

        $definition=self::buildColumnDefinition(
            $pdo,
            $table,
            null,
            $type,
            $nullable,
            $defaultKind,
            $defaultValue,
            $extras
        );
        $indexKind=self::normalizeIndexKind($indexKind);

        if (self::isSqlitePdo($pdo)) {
            $sqliteDefinition=self::sqliteRequestedColumnDefinition(
                $pdo,$type,$nullable,$defaultKind,$defaultValue,$extras
            );
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table,'sqlite')
                .' ADD COLUMN '.self::quoteIdentifier($column,'sqlite').' '.$sqliteDefinition
            );
            if ($indexKind!=='none') {
                $indexName=self::managedIndexName($table,$column,$indexKind==='unique');
                $pdo->exec(
                    'CREATE '.($indexKind==='unique'?'UNIQUE ':'').'INDEX '
                    .self::quoteIdentifier($indexName,'sqlite').' ON '
                    .self::quoteIdentifier($table,'sqlite').' ('
                    .self::quoteIdentifier($column,'sqlite').')'
                );
            }
            return;
        }

        if (self::isOraclePdo($pdo)) {
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
                .' ADD ('.self::quoteIdentifier($column,'mysql').' '.$definition.')'
            );
            if ($indexKind !== 'none') {
                $indexName=self::managedIndexName($table,$column,$indexKind==='unique');
                $pdo->exec(
                    'CREATE '.($indexKind==='unique'?'UNIQUE ':'').'INDEX '
                    .self::quoteIdentifier($indexName,'mysql').' ON '
                    .self::quoteIdentifier($table,'mysql').' ('
                    .self::quoteIdentifier($column,'mysql').')'
                );
            }
            return;
        }

        $actions=[
            'ADD COLUMN '.self::quoteIdentifier($column,'mysql').' '.$definition,
        ];
        if ($indexKind !== 'none') {
            $indexName=self::managedIndexName(
                $table,
                $column,
                $indexKind === 'unique'
            );
            $actions[]='ADD '.($indexKind==='unique'?'UNIQUE ':'')
                .'INDEX '.self::quoteIdentifier($indexName,'mysql')
                .' ('.self::quoteIdentifier($column,'mysql').')';
        }

        $pdo->exec(
            'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
            .' '.implode(', ',$actions)
        );
        self::syncPgsqlOnUpdateTrigger(
            $pdo,
            $table,
            null,
            $column,
            in_array('on_update_current_timestamp',array_map('strtolower',$extras),true)
        );
    }

    public static function updateManagedColumn(
        PDO $pdo,
        string $table,
        string $originalColumn,
        string $newColumn,
        string $type,
        bool $nullable,
        string $defaultKind='none',
        ?string $defaultValue=null,
        array $extras=[],
        string $indexKind='none'
    ): void {
        self::assertFieldCrudTable($pdo,$table);
        self::assertManagedIdentifier($originalColumn,'Bisheriger Feldname');
        self::assertManagedIdentifier($newColumn,'Feldname');

        self::assertMutableColumn($pdo,$table,$originalColumn);
        if (
            $newColumn !== $originalColumn
            && self::columnMetadata($pdo,$table,$newColumn) !== null
        ) {
            throw new RuntimeException('Ein Feld mit dem neuen Namen existiert bereits.');
        }

        $definition=self::buildColumnDefinition(
            $pdo,
            $table,
            $originalColumn,
            $type,
            $nullable,
            $defaultKind,
            $defaultValue,
            $extras
        );
        $indexKind=self::normalizeIndexKind($indexKind);

        if ($indexKind === 'unique') {
            self::assertUniqueValues($pdo,$table,$originalColumn);
        }

        $managedIndexes=self::managedSecondaryIndexes(
            $pdo,
            $table,
            $originalColumn
        );

        if (self::isSqlitePdo($pdo)) {
            self::sqliteRebuildTable(
                $pdo,
                $table,
                ['mode'=>'update','column'=>$originalColumn,'new_column'=>$newColumn,
                 'type'=>$type,'nullable'=>$nullable,'default_kind'=>$defaultKind,
                 'default_value'=>$defaultValue,'extras'=>$extras,'index_kind'=>$indexKind]
            );
            return;
        }

        if (self::isOraclePdo($pdo)) {
            foreach ($managedIndexes as $indexName) {
                $pdo->exec('DROP INDEX '.self::quoteIdentifier($indexName,'mysql'));
            }
            if ($newColumn !== $originalColumn) {
                $pdo->exec(
                    'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
                    .' RENAME COLUMN '.self::quoteIdentifier($originalColumn,'mysql')
                    .' TO '.self::quoteIdentifier($newColumn,'mysql')
                );
            }
            $pdo->exec(
                'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
                .' MODIFY ('.self::quoteIdentifier($newColumn,'mysql').' '.$definition.')'
            );
            if ($indexKind !== 'none') {
                $indexName=self::managedIndexName($table,$newColumn,$indexKind==='unique');
                $pdo->exec(
                    'CREATE '.($indexKind==='unique'?'UNIQUE ':'').'INDEX '
                    .self::quoteIdentifier($indexName,'mysql').' ON '
                    .self::quoteIdentifier($table,'mysql').' ('
                    .self::quoteIdentifier($newColumn,'mysql').')'
                );
            }
            return;
        }

        $actions=[
            'CHANGE COLUMN '.self::quoteIdentifier($originalColumn,'mysql')
            .' '.self::quoteIdentifier($newColumn,'mysql')
            .' '.$definition,
        ];

        foreach ($managedIndexes as $indexName) {
            $actions[]='DROP INDEX '.self::quoteIdentifier($indexName,'mysql');
        }

        if ($indexKind !== 'none') {
            $indexName=self::managedIndexName(
                $table,
                $newColumn,
                $indexKind === 'unique'
            );
            $actions[]='ADD '.($indexKind==='unique'?'UNIQUE ':'')
                .'INDEX '.self::quoteIdentifier($indexName,'mysql')
                .' ('.self::quoteIdentifier($newColumn,'mysql').')';
        }

        $pdo->exec(
            'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
            .' '.implode(', ',$actions)
        );
        self::syncPgsqlOnUpdateTrigger(
            $pdo,
            $table,
            $originalColumn,
            $newColumn,
            in_array('on_update_current_timestamp',array_map('strtolower',$extras),true)
        );
    }

    public static function moveManagedColumn(
        PDO $pdo,
        string $table,
        string $column,
        string $direction
    ): array {
        self::assertFieldCrudTable($pdo,$table);
        self::assertManagedIdentifier($column,'Feldname');

        if ($column === 'id') {
            throw new RuntimeException(
                'Das DataForm-Pflichtfeld id bleibt an erster Stelle und kann nicht verschoben werden.'
            );
        }

        $direction=strtolower(trim($direction));
        if (!in_array($direction,['up','down'],true)) {
            throw new RuntimeException('Ungültige Verschieberichtung.');
        }

        $stmt=$pdo->prepare(
            "SELECT column_name,ordinal_position
             FROM information_schema.columns
             WHERE table_schema=DATABASE()
               AND table_name=?
             ORDER BY ordinal_position"
        );
        $stmt->execute([$table]);
        $columns=array_map(
            static fn(array $row): string => (string)$row['column_name'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $index=array_search($column,$columns,true);
        if ($index === false) {
            throw new RuntimeException('Das zu verschiebende Feld wurde nicht gefunden.');
        }

        if ($direction === 'up') {
            if ($index <= 1) {
                throw new RuntimeException(
                    'Das Feld steht bereits direkt hinter dem geschützten id-Feld.'
                );
            }
            $after=$columns[$index-2];
        } else {
            if ($index >= count($columns)-1) {
                throw new RuntimeException(
                    'Das Feld steht bereits an der letzten möglichen Position.'
                );
            }
            $after=$columns[$index+1];
        }

        if (self::isSqlitePdo($pdo)) {
            self::sqliteRebuildTable($pdo,$table,['mode'=>'move','column'=>$column,'direction'=>$direction]);
            return ['column'=>$column,'direction'=>$direction,'after'=>$after];
        }

        if (self::isOraclePdo($pdo)) {
            throw new RuntimeException('Oracle XE unterstützt keine direkte physische Spaltenverschiebung. Die logische DataForm-Feldreihenfolge bleibt davon unberührt.');
        }

        $definition=self::exactColumnDefinition($pdo,$table,$column);
        $pdo->exec(
            'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
            .' MODIFY COLUMN '.self::quoteIdentifier($column,'mysql')
            .' '.$definition
            .' AFTER '.self::quoteIdentifier($after,'mysql')
        );

        return ['column'=>$column,'direction'=>$direction,'after'=>$after];
    }

    public static function dropManagedColumn(
        PDO $pdo,
        string $table,
        string $column
    ): void {
        self::assertFieldCrudTable($pdo,$table);
        self::assertManagedIdentifier($column,'Feldname');
        self::assertMutableColumn($pdo,$table,$column);

        if (self::isSqlitePdo($pdo)) {
            self::sqliteRebuildTable($pdo,$table,['mode'=>'drop','column'=>$column]);
            return;
        }

        self::syncPgsqlOnUpdateTrigger($pdo,$table,$column,$column,false);
        $pdo->exec(
            'ALTER TABLE '.self::quoteIdentifier($table,'mysql')
            .' DROP COLUMN '.self::quoteIdentifier($column,'mysql')
        );
    }

    public static function columnMutationState(
        PDO $pdo,
        string $table,
        string $column
    ): array {
        if (!self::fieldCrudAllowed($pdo,$table)) {
            return ['allowed'=>false,'reason'=>'geschützte Systemtabelle'];
        }
        if ($column === 'id') {
            return ['allowed'=>false,'reason'=>'DataForm-Pflichtfeld'];
        }
        if (self::columnMetadata($pdo,$table,$column) === null) {
            return ['allowed'=>false,'reason'=>'Feld nicht gefunden'];
        }

        if (self::isSqlitePdo($pdo)) {
            $quotedTable=str_replace("'","''",$table);
            foreach($pdo->query("PRAGMA table_info('{$quotedTable}')")->fetchAll(PDO::FETCH_ASSOC) as $meta){
                if((string)($meta['name']??'')===$column && (int)($meta['pk']??0)>0){
                    return ['allowed'=>false,'reason'=>'Primär-/Fremdschlüssel'];
                }
            }
            foreach($pdo->query("PRAGMA foreign_key_list('{$quotedTable}')")->fetchAll(PDO::FETCH_ASSOC) as $fk){
                if((string)($fk['from']??'')===$column){
                    return ['allowed'=>false,'reason'=>'Primär-/Fremdschlüssel'];
                }
            }
            foreach($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $other){
                $other=(string)$other;
                if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$other)!==1)continue;
                $oq=str_replace("'","''",$other);
                foreach($pdo->query("PRAGMA foreign_key_list('{$oq}')")->fetchAll(PDO::FETCH_ASSOC) as $fk){
                    if((string)($fk['table']??'')===$table && (string)($fk['to']??'')===$column){
                        return ['allowed'=>false,'reason'=>'Primär-/Fremdschlüssel'];
                    }
                }
            }
            return ['allowed'=>true,'reason'=>''];
        }

        if (self::isOraclePdo($pdo)) {
            $stmt=$pdo->prepare(
                "SELECT COUNT(*)
                 FROM user_cons_columns ucc
                 JOIN user_constraints uc ON uc.constraint_name=ucc.constraint_name
                 WHERE (ucc.table_name=UPPER(?) AND ucc.column_name=UPPER(?) AND uc.constraint_type IN ('P','R'))
                    OR (uc.constraint_type='R' AND uc.r_constraint_name IN (
                        SELECT pk.constraint_name FROM user_constraints pk
                        JOIN user_cons_columns pcc ON pcc.constraint_name=pk.constraint_name
                        WHERE pcc.table_name=UPPER(?) AND pcc.column_name=UPPER(?)
                    ))"
            );
            $stmt->execute([$table,$column,$table,$column]);
            if ((int)$stmt->fetchColumn()>0) return ['allowed'=>false,'reason'=>'Primär-/Fremdschlüssel'];
            return ['allowed'=>true,'reason'=>''];
        }

        $stmt=$pdo->prepare(
            "SELECT COUNT(*)
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA=DATABASE()
               AND (
                    (
                        TABLE_NAME=?
                        AND COLUMN_NAME=?
                        AND (
                            CONSTRAINT_NAME='PRIMARY'
                            OR REFERENCED_TABLE_NAME IS NOT NULL
                        )
                    )
                    OR
                    (
                        REFERENCED_TABLE_NAME=?
                        AND REFERENCED_COLUMN_NAME=?
                    )
               )"
        );
        $stmt->execute([$table,$column,$table,$column]);
        if ((int)$stmt->fetchColumn() > 0) {
            return ['allowed'=>false,'reason'=>'Primär-/Fremdschlüssel'];
        }

        return ['allowed'=>true,'reason'=>''];
    }

    public static function editableColumnType(array $column): string
    {
        $type=strtolower(trim((string)($column['type']??'')));
        if (preg_match('/^varchar\\((\\d+)\\)/',$type,$m)===1) {
            return 'varchar('.(int)$m[1].')';
        }
        if (preg_match('/^decimal\\((\\d+),(\\d+)\\)/',$type,$m)===1) {
            return 'decimal('.(int)$m[1].','.(int)$m[2].')';
        }
        if (preg_match('/^tinyint\\(1\\)/',$type)===1) return 'boolean';
        if (preg_match('/^int(?:\\(\\d+\\))?/',$type)===1) return 'int';
        if (preg_match('/^bigint(?:\\(\\d+\\))?/',$type)===1) return 'bigint';
        if (str_starts_with($type,'datetime')) return 'datetime';
        if (str_starts_with($type,'timestamp')) return 'timestamp';
        if ($type==='date') return 'date';
        if (str_contains($type,'text')) return 'text';
        return $type;
    }

    /**
     * RC1.1 / CSV: legt eine neue CSV-Tabelle in einer registrierten
     * CSV-Datenquelle an. Typangaben hinter ':' werden aus Kompatibilitaet zur
     * MySQL-Tabelleneingabe akzeptiert, CSV speichert jedoch alle Nutzwerte als
     * Text. Die Pflichtspalte id wird von der Engine automatisch erzeugt.
     */
    public static function createCsvTable(
        array $source,
        string $keyMaterial,
        string $table,
        string $columnSpecification
    ): void {
        self::assertManagedIdentifier($table,'Tabellenname');
        $columns=self::csvColumnNames($columnSpecification);
        if ($columns===[]) {
            throw new RuntimeException('Geben Sie mindestens eine CSV-Spalte an.');
        }
        $db=self::csvDatabase($source,$keyMaterial);
        try {
            $db->createTable($table,$columns);
        } finally {
            $db->disconnect();
        }
    }

    public static function dropCsvTable(
        array $source,
        string $keyMaterial,
        string $table
    ): void {
        self::assertManagedIdentifier($table,'Tabellenname');
        $db=self::csvDatabase($source,$keyMaterial);
        try {
            if (!method_exists($db,'dropTable')) {
                throw new RuntimeException('Der CSV-Adapter unterstuetzt das Loeschen von Tabellen nicht.');
            }
            $db->dropTable($table,false);
        } finally {
            $db->disconnect();
        }
    }

    public static function addCsvColumn(
        array $source,
        string $keyMaterial,
        string $table,
        string $column
    ): void {
        self::assertManagedIdentifier($table,'Tabellenname');
        self::assertManagedIdentifier($column,'Spaltenname');
        if ($column==='id') {
            throw new RuntimeException('Die Pflichtspalte id wird automatisch verwaltet.');
        }
        $db=self::csvDatabase($source,$keyMaterial);
        try {
            if (!method_exists($db,'addColumn')) {
                throw new RuntimeException('Der CSV-Adapter unterstuetzt das Anlegen von Spalten nicht.');
            }
            $db->addColumn($table,$column,'');
        } finally {
            $db->disconnect();
        }
    }

    public static function renameCsvColumn(
        array $source,
        string $keyMaterial,
        string $table,
        string $column,
        string $newName
    ): void {
        self::assertManagedIdentifier($table,'Tabellenname');
        self::assertManagedIdentifier($column,'Spaltenname');
        self::assertManagedIdentifier($newName,'Neuer Spaltenname');
        $db=self::csvDatabase($source,$keyMaterial);
        try {
            if (!method_exists($db,'renameColumn')) {
                throw new RuntimeException('Der CSV-Adapter unterstuetzt das Umbenennen von Spalten nicht.');
            }
            $db->renameColumn($table,$column,$newName);
        } finally {
            $db->disconnect();
        }
    }

    public static function dropCsvColumn(
        array $source,
        string $keyMaterial,
        string $table,
        string $column
    ): void {
        self::assertManagedIdentifier($table,'Tabellenname');
        self::assertManagedIdentifier($column,'Spaltenname');
        $db=self::csvDatabase($source,$keyMaterial);
        try {
            if (!method_exists($db,'dropColumn')) {
                throw new RuntimeException('Der CSV-Adapter unterstuetzt das Loeschen von Spalten nicht.');
            }
            $db->dropColumn($table,$column);
        } finally {
            $db->disconnect();
        }
    }

    public static function catalog(
        PDO $projectPdo,
        ?array $source,
        string $keyMaterial
    ): array {
        if ($source === null) {
            if (self::isSqlitePdo($projectPdo)) {
                $stmt=$projectPdo->query(
                    "SELECT name, upper(type) AS kind FROM sqlite_master "
                    ."WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name"
                );
            } elseif (self::isOraclePdo($projectPdo)) {
                $stmt=$projectPdo->query(
                    "SELECT LOWER(table_name) AS \"name\", 'BASE TABLE' AS \"kind\" FROM user_tables "
                    ."UNION ALL SELECT LOWER(view_name) AS \"name\", 'VIEW' AS \"kind\" FROM user_views ORDER BY \"name\""
                );
            } else {
                $stmt=$projectPdo->query(
                    "SELECT table_name AS name, table_type AS kind
                     FROM information_schema.tables
                     WHERE table_schema=DATABASE()
                     ORDER BY table_name"
                );
            }
            return array_map(
                static fn(array $row): array => [
                    'name'=>(string)$row['name'],
                    'type'=>(string)$row['kind'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }

        $driver=(string)$source['driver'];
        $config=DataSourceManager::runtimeConfig($source,$keyMaterial);

        if ($driver === 'csv') {
            // HF25 legacy contract: the old catalog scanned with
            // glob($path.DIRECTORY_SEPARATOR.'*.csv'); RC1.1 delegates the same
            // responsibility to CsvAdapter::listTables() so validation and path
            // rules remain centralized in the database layer.
            $db=DatabaseFactory::create($config+['auto_connect'=>true]);
            try {
                if (!method_exists($db,'listTables')) {
                    throw new RuntimeException('Der CSV-Adapter stellt keinen Tabellenkatalog bereit.');
                }
                return array_map(
                    static fn(string $name): array => ['name'=>$name,'type'=>'CSV'],
                    $db->listTables()
                );
            } finally {
                $db->disconnect();
            }
        }

        $pdo=self::externalPdo($source,$keyMaterial);

        if ($driver === 'mysql') {
            $stmt=$pdo->query(
                "SELECT table_name AS name, table_type AS kind
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                 ORDER BY table_name"
            );
            return array_map(
                static fn(array $row): array => [
                    'name'=>(string)$row['name'],
                    'type'=>(string)$row['kind'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }


        if ($driver === 'pgsql') {
            $stmt=$pdo->query(
                "SELECT table_name AS name, table_type AS kind
                 FROM information_schema.tables
                 WHERE table_schema=current_schema()
                 ORDER BY table_name"
            );
            return array_map(
                static fn(array $row): array => [
                    'name'=>(string)$row['name'],
                    'type'=>(string)$row['kind'],
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }

        if ($driver === 'mssql') {
            $stmt=$pdo->query("SELECT TABLE_NAME AS name,TABLE_TYPE AS kind FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' ORDER BY TABLE_NAME");
            return array_map(static fn(array $row): array => ['name'=>(string)$row['name'],'type'=>(string)$row['kind']],$stmt->fetchAll(PDO::FETCH_ASSOC));
        }

        if ($driver === 'sqlite') {
            $stmt=$pdo->query(
                "SELECT name,type
                 FROM sqlite_master
                 WHERE type IN ('table','view')
                   AND name NOT LIKE 'sqlite_%'
                 ORDER BY name"
            );
            return array_map(
                static fn(array $row): array => [
                    'name'=>(string)$row['name'],
                    'type'=>strtoupper((string)$row['type']),
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }

        if ($driver === 'oracle') {
            $stmt=$pdo->query(
                "SELECT table_name
                 FROM user_tables
                 ORDER BY table_name"
            );
            return array_map(
                static fn(array $row): array => [
                    'name'=>strtolower(
                        (string)($row['TABLE_NAME'] ?? $row['table_name'] ?? '')
                    ),
                    'type'=>'TABLE',
                ],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );
        }

        throw new RuntimeException('Nicht unterstützter Tabellenquellentyp.');
    }

    public static function inspect(
        PDO $projectPdo,
        ?array $source,
        string $keyMaterial,
        string $table,
        int $previewLimit=20
    ): array {
        $catalog=self::catalog($projectPdo,$source,$keyMaterial);
        $match=null;
        foreach ($catalog as $item) {
            if ((string)$item['name'] === $table) {
                $match=$item;
                break;
            }
        }
        if ($match === null) {
            throw new RuntimeException(
                'Die ausgewählte Tabelle gehört nicht zur gewählten Datenquelle.'
            );
        }

        if ($source === null) {
            $inspection=self::inspectPdo(
                $projectPdo,
                self::isSqlitePdo($projectPdo)?'sqlite':((class_exists('EnterprisePgsqlPdo',false) && $projectPdo instanceof EnterprisePgsqlPdo)?'pgsql':((class_exists('EnterpriseOraclePdo',false) && $projectPdo instanceof EnterpriseOraclePdo)?'oracle':((class_exists('EnterpriseMssqlPdo',false) && $projectPdo instanceof EnterpriseMssqlPdo)?'mssql':'mysql'))),
                $table,
                $previewLimit
            );
            $managed=self::isManaged($projectPdo,$table);
            $application=self::isApplicationTable($projectPdo,$table);
            $fieldCrud=$managed || $application;
            foreach ($inspection['columns'] as &$column) {
                $column['editable_type']=self::editableColumnType($column);
                $column['default_kind']=self::editableDefaultKind($column);
                $column['default_value']=$column['default_kind']==='literal'
                    ? (string)(
                        array_key_exists('default_raw',$column)
                            ? $column['default_raw']
                            : ($column['default']??'')
                    )
                    : '';
                $column['editable_extras']=self::editableExtras($column);
                $indexState=self::columnIndexState(
                    $projectPdo,
                    $table,
                    (string)$column['name']
                );
                $column['secondary_index']=(string)$indexState['managed_kind'];
                $column['managed_indexes']=$indexState['managed_indexes'];
                $column['external_indexes']=$indexState['external_indexes'];
                $state=$fieldCrud
                    ? self::columnMutationState($projectPdo,$table,(string)$column['name'])
                    : ['allowed'=>false,'reason'=>'geschützte Systemtabelle'];
                $column['mutable']=(bool)$state['allowed'];
                $column['protection_reason']=(string)$state['reason'];
            }
            unset($column);
            return $inspection + [
                'type'=>$match['type'],
                'managed'=>$managed,
                'application'=>$application,
                'field_crud'=>$fieldCrud,
            ];
        }

        $driver=(string)$source['driver'];

        if ($driver === 'csv') {
            $config=DataSourceManager::runtimeConfig(
                $source,
                $keyMaterial
            );
            $db=DatabaseFactory::create(
                $config+['auto_connect'=>true]
            );
            try {
                $columns=array_map(
                    static fn(string $name): array => [
                        'name'=>$name,
                        'type'=>$name==='id'?'ID':'TEXT',
                        'nullable'=>$name==='id'?'Nein':'Ja',
                        'key'=>$name==='id'?'PRIMARY':'',
                        'default'=>'',
                        'extra'=>'CSV',
                        'mutable'=>$name!=='id',
                        'protection_reason'=>$name==='id'?'DataForm-Pflichtfeld':'',
                        'editable_type'=>'text',
                    ],
                    $db->columns($table)
                );
                $all=$db->all($table);
                return [
                    'type'=>'CSV',
                    'columns'=>$columns,
                    'row_count'=>count($all),
                    'preview'=>array_slice($all,0,$previewLimit),
                    'managed'=>false,
                    'application'=>true,
                    'field_crud'=>true,
                    'external_writable'=>true,
                    'storage'=>'csv',
                ];
            } finally {
                $db->disconnect();
            }
        }

        return self::inspectPdo(
            self::externalPdo($source,$keyMaterial),
            $driver,
            $table,
            $previewLimit
        ) + [
            'type'=>$match['type'],
            'managed'=>false,
        ];
    }

    private static function inspectPdo(
        PDO $pdo,
        string $driver,
        string $table,
        int $previewLimit
    ): array {
        $previewLimit=max(1,min(100,$previewLimit));

        if ($driver === 'mysql') {
            $quoted=self::quoteIdentifier($table,'mysql');
            $raw=$pdo->query(
                'SHOW FULL COLUMNS FROM '.$quoted
            )->fetchAll(PDO::FETCH_ASSOC);
            $columns=array_map(
                static fn(array $row): array => [
                    'name'=>(string)$row['Field'],
                    'type'=>(string)$row['Type'],
                    'nullable'=>(string)$row['Null'],
                    'key'=>(string)$row['Key'],
                    'default'=>$row['Default']===null?'NULL':(string)$row['Default'],
                    'default_raw'=>$row['Default'],
                    'extra'=>(string)$row['Extra'],
                ],
                $raw
            );
            $count=(int)$pdo->query(
                'SELECT COUNT(*) FROM '.$quoted
            )->fetchColumn();
            $preview=$pdo->query(
                'SELECT * FROM '.$quoted.' LIMIT '.$previewLimit
            )->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($driver === 'pgsql') {
            $quoted=self::quoteIdentifier($table,'pgsql');
            $stmt=$pdo->prepare(
                "SELECT c.column_name,c.data_type,c.udt_name,c.character_maximum_length,c.numeric_precision,c.numeric_scale,c.is_nullable,c.column_default,c.is_identity,
                        CASE WHEN EXISTS (SELECT 1 FROM information_schema.table_constraints tc JOIN information_schema.key_column_usage kcu ON kcu.constraint_name=tc.constraint_name AND kcu.constraint_schema=tc.constraint_schema WHERE tc.table_schema=current_schema() AND tc.table_name=c.table_name AND tc.constraint_type='PRIMARY KEY' AND kcu.column_name=c.column_name) THEN 'PRIMARY' ELSE '' END AS column_key
                 FROM information_schema.columns c WHERE c.table_schema=current_schema() AND c.table_name=? ORDER BY c.ordinal_position"
            );
            $stmt->execute([$table]);
            $raw=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns=array_map(
                static function(array $row): array {
                    $type=(string)($row['data_type']??'');
                    if($type==='character varying' && !empty($row['character_maximum_length']))$type='varchar('.(int)$row['character_maximum_length'].')';
                    elseif($type==='numeric' && !empty($row['numeric_precision']))$type='numeric('.(int)$row['numeric_precision'].','.(int)($row['numeric_scale']??0).')';
                    return [
                        'name'=>(string)$row['column_name'],
                        'type'=>$type,
                        'nullable'=>(string)$row['is_nullable'],
                        'key'=>(string)($row['column_key']??''),
                        'default'=>$row['column_default']===null?'NULL':(string)$row['column_default'],
                        'default_raw'=>$row['column_default'],
                        'extra'=>((string)($row['is_identity']??'')==='YES'||str_starts_with((string)($row['column_default']??''),'nextval('))?'auto_increment':'',
                    ];
                },
                $raw
            );
            foreach($columns as &$pgsqlColumn){
                if(self::pgsqlHasOnUpdateTrigger($pdo,$table,(string)$pgsqlColumn['name'])){
                    $pgsqlColumn['extra']=trim((string)$pgsqlColumn['extra'].' on update current_timestamp');
                }
            }
            unset($pgsqlColumn);
            $count=(int)$pdo->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn();
            $preview=$pdo->query('SELECT * FROM '.$quoted.' LIMIT '.$previewLimit)->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($driver === 'mssql') {
            $quoted=self::quoteIdentifier($table,'mssql');
            $stmt=$pdo->prepare("SELECT c.COLUMN_NAME,c.DATA_TYPE,c.CHARACTER_MAXIMUM_LENGTH,c.NUMERIC_PRECISION,c.NUMERIC_SCALE,c.IS_NULLABLE,c.COLUMN_DEFAULT,COLUMNPROPERTY(OBJECT_ID(c.TABLE_SCHEMA+'.'+c.TABLE_NAME),c.COLUMN_NAME,'IsIdentity') AS is_identity,CASE WHEN EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON kcu.CONSTRAINT_NAME=tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA='dbo' AND tc.TABLE_NAME=c.TABLE_NAME AND tc.CONSTRAINT_TYPE='PRIMARY KEY' AND kcu.COLUMN_NAME=c.COLUMN_NAME) THEN 'PRIMARY' ELSE '' END AS column_key FROM INFORMATION_SCHEMA.COLUMNS c WHERE c.TABLE_SCHEMA='dbo' AND c.TABLE_NAME=? ORDER BY c.ORDINAL_POSITION");
            $stmt->execute([$table]);$raw=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns=array_map(static function(array $row): array {
                $type=strtoupper((string)$row['DATA_TYPE']);$len=(int)($row['CHARACTER_MAXIMUM_LENGTH']??0);
                if(in_array($type,['NVARCHAR','VARCHAR','NCHAR','CHAR','VARBINARY'],true) && $len!==0)$type.='('.($len<0?'MAX':$len).')';
                elseif(in_array($type,['DECIMAL','NUMERIC'],true) && !empty($row['NUMERIC_PRECISION']))$type.='('.(int)$row['NUMERIC_PRECISION'].','.(int)($row['NUMERIC_SCALE']??0).')';
                return ['name'=>(string)$row['COLUMN_NAME'],'type'=>$type,'nullable'=>(string)$row['IS_NULLABLE'],'key'=>(string)($row['column_key']??''),'default'=>$row['COLUMN_DEFAULT']===null?'NULL':(string)$row['COLUMN_DEFAULT'],'default_raw'=>$row['COLUMN_DEFAULT'],'extra'=>(int)($row['is_identity']??0)===1?'auto_increment':''];
            },$raw);
            $count=(int)$pdo->query('SELECT COUNT(*) FROM '.$quoted)->fetchColumn();
            $preview=$pdo->query('SELECT TOP '.$previewLimit.' * FROM '.$quoted)->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($driver === 'sqlite') {
            $quoted=self::quoteIdentifier($table,'sqlite');
            $raw=$pdo->query(
                'PRAGMA table_info('.$quoted.')'
            )->fetchAll(PDO::FETCH_ASSOC);
            $columns=array_map(
                static fn(array $row): array => [
                    'name'=>(string)$row['name'],
                    'type'=>(string)$row['type'],
                    'nullable'=>(int)$row['notnull']===1?'Nein':'Ja',
                    'key'=>(int)$row['pk']===1?'PRIMARY':'',
                    'default'=>$row['dflt_value']===null?'NULL':(string)$row['dflt_value'],
                    'extra'=>'',
                ],
                $raw
            );
            $count=(int)$pdo->query(
                'SELECT COUNT(*) FROM '.$quoted
            )->fetchColumn();
            $preview=$pdo->query(
                'SELECT * FROM '.$quoted.' LIMIT '.$previewLimit
            )->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($driver === 'oracle') {
            $stmt=$pdo->prepare(
                "SELECT column_name,data_type,nullable,data_default,column_id
                 FROM user_tab_columns
                 WHERE table_name=UPPER(:table)
                 ORDER BY column_id"
            );
            $stmt->execute(['table'=>$table]);
            $raw=$stmt->fetchAll(PDO::FETCH_ASSOC);
            $columns=array_map(
                static fn(array $row): array => [
                    'name'=>strtolower((string)($row['COLUMN_NAME']??'')),
                    'type'=>(string)($row['DATA_TYPE']??''),
                    'nullable'=>(string)($row['NULLABLE']??''),
                    'key'=>'',
                    'default'=>trim((string)($row['DATA_DEFAULT']??'')),
                    'extra'=>'',
                ],
                $raw
            );
            $quoted=self::quoteIdentifier(strtoupper($table),'oracle');
            $count=(int)$pdo->query(
                'SELECT COUNT(*) FROM '.$quoted
            )->fetchColumn();
            $preview=$pdo->query(
                'SELECT * FROM '.$quoted
                .' FETCH FIRST '.$previewLimit.' ROWS ONLY'
            )->fetchAll(PDO::FETCH_ASSOC);
        } else {
            throw new RuntimeException(
                'Der Tabellenadapter unterstützt diese Quelle nicht.'
            );
        }

        return [
            'columns'=>$columns,
            'row_count'=>$count,
            'preview'=>$preview,
        ];
    }

    private static function externalPdo(
        array $source,
        string $keyMaterial
    ): PDO {
        $driver=(string)$source['driver'];
        $config=DataSourceManager::runtimeConfig(
            $source,
            $keyMaterial
        );

        if ($driver === 'mysql') {
            $dsn='mysql:host='.(string)($config['host']??'127.0.0.1')
                .';port='.(int)($config['port']??3306)
                .';dbname='.(string)($config['database']??'')
                .';charset='.(string)($config['charset']??'utf8mb4');
            $username=(string)($config['username']??'');
            $password=(string)($config['password']??'');
        } elseif ($driver === 'pgsql') {
            $dsn='pgsql:host='.(string)($config['host']??'127.0.0.1')
                .';port='.(int)($config['port']??5432)
                .';dbname='.(string)($config['database']??'');
            $username=(string)($config['username']??'');
            $password=(string)($config['password']??'');
        } elseif ($driver === 'mssql') {
            if(!in_array('sqlsrv',PDO::getAvailableDrivers(),true))throw new RuntimeException('PDO_SQLSRV ist nicht geladen.');
            $server=(string)($config['host']??'127.0.0.1').','.(int)($config['port']??1433);
            $dsn='sqlsrv:Server='.$server.';Database='.(string)($config['database']??'').';Encrypt='.(empty($config['encrypt'])?'false':'true').';TrustServerCertificate='.(empty($config['trust_server_certificate'])?'false':'true');
            $username=(string)($config['username']??'');$password=(string)($config['password']??'');
        } elseif ($driver === 'sqlite') {
            $dsn='sqlite:'.(string)($config['path']??'');
            $username='';
            $password='';
        } elseif ($driver === 'oracle') {
            $dsn=trim((string)($config['dsn']??''));
            if ($dsn === '') {
                $dsn='oci:dbname=//'
                    .(string)($config['host']??'127.0.0.1')
                    .':'.(int)($config['port']??1521)
                    .'/'.(string)($config['service_name']??'XEPDB1')
                    .';charset='.(string)($config['charset']??'AL32UTF8');
            }
            $username=(string)($config['username']??'');
            $password=(string)($config['password']??'');
        } else {
            throw new RuntimeException(
                'Für diese Datenquelle ist keine PDO-Tabelleninspektion verfügbar.'
            );
        }

        $pdo=new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]
        );
        if($driver==='pgsql'){
            $schema=trim((string)($config['schema']??'public'))?:'public';
            if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$schema)!==1){
                throw new RuntimeException('Ungültiger PostgreSQL-Schemaname.');
            }
            $pdo->exec("SET client_encoding TO 'UTF8'");
            $pdo->exec('SET search_path TO "'.$schema.'"');
        }
        return $pdo;
    }

    private static function assertFieldCrudTable(PDO $pdo,string $table): void
    {
        self::assertManagedIdentifier($table,'Tabellenname');
        if (!self::fieldCrudAllowed($pdo,$table)) {
            throw new RuntimeException(
                'Feldänderungen sind für diese geschützte Systemtabelle nicht zulässig.'
            );
        }
        if (!self::systemTableExists($pdo,$table)) {
            throw new RuntimeException('Die Projekttabelle existiert physisch nicht.');
        }
    }

    private static function assertMutableColumn(
        PDO $pdo,
        string $table,
        string $column
    ): array {
        if ($column === 'id') {
            throw new RuntimeException('Das Pflichtfeld id darf nicht geändert oder gelöscht werden.');
        }
        $meta=self::columnMetadata($pdo,$table,$column);
        if ($meta === null) {
            throw new RuntimeException('Das Feld wurde nicht gefunden.');
        }
        $state=self::columnMutationState($pdo,$table,$column);
        if (!$state['allowed']) {
            throw new RuntimeException(
                'Das Feld ist geschützt: '.(string)$state['reason'].'.'
            );
        }
        return $meta;
    }

    private static function columnMetadata(
        PDO $pdo,
        string $table,
        string $column
    ): ?array {
        if (self::isOraclePdo($pdo)) {
            $stmt=$pdo->prepare(
                "SELECT LOWER(c.column_name) AS column_name,
                        c.data_type AS column_type,
                        CASE WHEN c.nullable='Y' THEN 'YES' ELSE 'NO' END AS is_nullable,
                        c.data_default AS column_default,
                        CASE WHEN c.identity_column='YES' THEN 'auto_increment' ELSE '' END AS extra
                 FROM user_tab_columns c
                 WHERE c.table_name=UPPER(?) AND c.column_name=UPPER(?)"
            );
        } else {
            $stmt=$pdo->prepare(
                "SELECT column_name,column_type,is_nullable,column_default,extra
                 FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name=?
                   AND column_name=?
                 LIMIT 1"
            );
        }
        $stmt->execute([$table,$column]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if ($row===false) return null;
        return [
            'name'=>(string)$row['column_name'],
            'type'=>(string)$row['column_type'],
            'nullable'=>(string)$row['is_nullable'],
            'default'=>$row['column_default'],
            'extra'=>(string)$row['extra'],
        ];
    }

    private static function editableDefaultKind(array $column): string
    {
        $default=array_key_exists('default_raw',$column)
            ? $column['default_raw']
            : ($column['default']??null);
        if ($default === null) {
            return strtoupper((string)($column['nullable']??'NO')) === 'YES'
                ? 'null'
                : 'none';
        }

        $defaultString=trim((string)$default);
        if (
            preg_match(
                '/^current_timestamp(?:\(\))?$/i',
                $defaultString
            ) === 1
        ) {
            return 'current_timestamp';
        }

        return 'literal';
    }

    private static function editableExtras(array $column): array
    {
        $type=strtolower((string)($column['type']??''));
        $extra=strtolower((string)($column['extra']??''));
        $values=[];

        if (preg_match('/\bunsigned\b/',$type)===1) {
            $values[]='unsigned';
        }
        if (preg_match('/\bzerofill\b/',$type)===1) {
            $values[]='zerofill';
        }
        if (str_contains($extra,'auto_increment')) {
            $values[]='auto_increment';
        }
        if (str_contains($extra,'on update current_timestamp')) {
            $values[]='on_update_current_timestamp';
        }

        return $values;
    }

    public static function columnIndexState(
        PDO $pdo,
        string $table,
        string $column
    ): array {
        self::assertManagedIdentifier($table,'Tabellenname');
        self::assertManagedIdentifier($column,'Feldname');

        if (self::isOraclePdo($pdo)) {
            $stmt=$pdo->prepare(
                "SELECT LOWER(i.index_name) AS index_name,
                        CASE WHEN i.uniqueness='UNIQUE' THEN 0 ELSE 1 END AS non_unique,
                        ic.column_position AS seq_in_index
                 FROM user_indexes i
                 JOIN user_ind_columns ic ON ic.index_name=i.index_name
                 WHERE i.table_name=UPPER(?) AND ic.column_name=UPPER(?)
                 ORDER BY i.index_name,ic.column_position"
            );
        } else {
            $stmt=$pdo->prepare(
                "SELECT index_name,non_unique,seq_in_index
                 FROM information_schema.statistics
                 WHERE table_schema=DATABASE()
                   AND table_name=?
                   AND column_name=?
                   AND index_name<>'PRIMARY'
                 ORDER BY index_name,seq_in_index"
            );
        }
        $stmt->execute([$table,$column]);

        $managed=[];
        $external=[];
        $managedKind='none';

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name=(string)$row['index_name'];
            $isManaged=str_starts_with($name,'idx_df_')
                || str_starts_with($name,'uq_df_');

            if ($isManaged && (int)$row['seq_in_index']===1) {
                $managed[]=$name;
                if (str_starts_with($name,'uq_df_')) {
                    $managedKind='unique';
                } elseif ($managedKind !== 'unique') {
                    $managedKind='index';
                }
            } else {
                $external[]=$name;
            }
        }

        return [
            'managed_kind'=>$managedKind,
            'managed_indexes'=>array_values(array_unique($managed)),
            'external_indexes'=>array_values(array_unique($external)),
        ];
    }

    private static function managedSecondaryIndexes(
        PDO $pdo,
        string $table,
        string $column
    ): array {
        $state=self::columnIndexState($pdo,$table,$column);
        return (array)$state['managed_indexes'];
    }

    private static function managedIndexName(
        string $table,
        string $column,
        bool $unique
    ): string {
        $prefix=$unique?'uq_df_':'idx_df_';
        $hash=substr(hash('sha256',$table."\0".$column),0,10);
        $stem=preg_replace('/[^A-Za-z0-9_]/','_',$table.'_'.$column) ?: 'field';
        $maxStem=64-strlen($prefix)-1-strlen($hash);
        return $prefix.substr($stem,0,max(1,$maxStem)).'_'.$hash;
    }

    private static function normalizeIndexKind(string $kind): string
    {
        $kind=strtolower(trim($kind));
        if (!in_array($kind,['none','index','unique'],true)) {
            throw new RuntimeException('Ungültiger Sekundärindextyp.');
        }
        return $kind;
    }

    private static function normalizeExtras(array $extras): array
    {
        $allowed=[
            'unsigned',
            'zerofill',
            'auto_increment',
            'on_update_current_timestamp',
        ];
        $values=[];
        foreach ($extras as $extra) {
            $extra=strtolower(trim((string)$extra));
            if ($extra === '') continue;
            if (!in_array($extra,$allowed,true)) {
                throw new RuntimeException(
                    'Nicht unterstützte Extra-Eigenschaft „'.$extra.'“.'
                );
            }
            $values[$extra]=true;
        }
        return array_keys($values);
    }

    private static function buildColumnDefinition(
        PDO $pdo,
        string $table,
        ?string $currentColumn,
        string $type,
        bool $nullable,
        string $defaultKind,
        ?string $defaultValue,
        array $extras
    ): string {
        $baseType=strtolower(trim($type));
        $sqlType=self::normalizeColumnType($baseType);
        $extras=self::normalizeExtras($extras);

        $numeric=preg_match(
            '/^(?:int|integer|bigint|decimal\(\d{1,2},\d{1,2}\))$/',
            $baseType
        )===1;
        $boolean=in_array($baseType,['bool','boolean'],true);
        $integer=in_array($baseType,['int','integer','bigint'],true);
        $temporal=in_array($baseType,['datetime','timestamp'],true);

        if (
            (in_array('unsigned',$extras,true)
                || in_array('zerofill',$extras,true))
            && !$numeric
        ) {
            throw new RuntimeException(
                'UNSIGNED und ZEROFILL sind nur für numerische Felder zulässig.'
            );
        }

        if (in_array('zerofill',$extras,true)) {
            if (!in_array('unsigned',$extras,true)) {
                $extras[]='unsigned';
            }
        }

        if (in_array('auto_increment',$extras,true)) {
            if (!$integer) {
                throw new RuntimeException(
                    'AUTO_INCREMENT ist nur für INT/BIGINT-Felder zulässig.'
                );
            }
            if ($nullable) {
                throw new RuntimeException(
                    'AUTO_INCREMENT-Felder dürfen keine NULL-Werte zulassen.'
                );
            }

            if (self::isOraclePdo($pdo)) {
                $stmt=$pdo->prepare(
                    "SELECT LOWER(column_name) FROM user_tab_columns
                     WHERE table_name=UPPER(?) AND identity_column='YES'
                       AND (? IS NULL OR column_name<>UPPER(?)) FETCH FIRST 1 ROWS ONLY"
                );
            } else {
                $stmt=$pdo->prepare(
                    "SELECT column_name
                     FROM information_schema.columns
                     WHERE table_schema=DATABASE()
                       AND table_name=?
                       AND LOWER(extra) LIKE '%auto_increment%'
                       AND (? IS NULL OR column_name<>?)
                     LIMIT 1"
                );
            }
            $stmt->execute([$table,$currentColumn,$currentColumn]);
            $existing=$stmt->fetchColumn();
            if ($existing !== false) {
                throw new RuntimeException(
                    'Die Tabelle besitzt bereits das AUTO_INCREMENT-Feld „'
                    .(string)$existing.'“.'
                );
            }
        }

        if (
            in_array('on_update_current_timestamp',$extras,true)
            && !$temporal
        ) {
            throw new RuntimeException(
                'ON UPDATE CURRENT_TIMESTAMP ist nur für DATETIME/TIMESTAMP zulässig.'
            );
        }

        $definition=$sqlType;
        if (in_array('unsigned',$extras,true)) {
            $definition.=' UNSIGNED';
        }
        if (in_array('zerofill',$extras,true)) {
            $definition.=' ZEROFILL';
        }
        $definition.=$nullable?' NULL':' NOT NULL';

        $defaultKind=strtolower(trim($defaultKind));
        if (!in_array(
            $defaultKind,
            ['none','null','literal','current_timestamp'],
            true
        )) {
            throw new RuntimeException('Ungültige Vorgabewert-Art.');
        }

        if ($defaultKind === 'null') {
            if (!$nullable) {
                throw new RuntimeException(
                    'DEFAULT NULL ist nur bei Feldern zulässig, die NULL-Werte erlauben.'
                );
            }
            $definition.=' DEFAULT NULL';
        } elseif ($defaultKind === 'literal') {
            $literal=(string)($defaultValue??'');
            if ($numeric) {
                if ($literal === '' || !is_numeric($literal)) {
                    throw new RuntimeException(
                        'Der Vorgabewert muss für diesen numerischen Datentyp eine Zahl sein.'
                    );
                }
                $definition.=' DEFAULT '.$literal;
            } elseif ($boolean) {
                $normalizedBoolean=strtolower(trim($literal));
                if (!in_array($normalizedBoolean,['0','1','false','true'],true)) {
                    throw new RuntimeException(
                        'BOOLEAN-Vorgabewerte müssen 0, 1, false oder true sein.'
                    );
                }
                $definition.=' DEFAULT '.(
                    in_array($normalizedBoolean,['1','true'],true)?'1':'0'
                );
            } else {
                $definition.=' DEFAULT '.$pdo->quote($literal);
            }
        } elseif ($defaultKind === 'current_timestamp') {
            if (!$temporal) {
                throw new RuntimeException(
                    'CURRENT_TIMESTAMP ist als Vorgabewert nur für DATETIME/TIMESTAMP zulässig.'
                );
            }
            $definition.=' DEFAULT CURRENT_TIMESTAMP';
        }

        if (in_array('auto_increment',$extras,true)) {
            if ($defaultKind !== 'none') {
                throw new RuntimeException(
                    'AUTO_INCREMENT kann nicht mit einem eigenen Vorgabewert kombiniert werden.'
                );
            }
            $definition.=' AUTO_INCREMENT';
        }
        if (in_array('on_update_current_timestamp',$extras,true)) {
            $definition.=' ON UPDATE CURRENT_TIMESTAMP';
        }

        return $definition;
    }

    private static function assertUniqueValues(
        PDO $pdo,
        string $table,
        string $column
    ): void {
        $quotedTable=self::quoteIdentifier($table,'mysql');
        $quotedColumn=self::quoteIdentifier($column,'mysql');

        $sql='SELECT '.$quotedColumn.',COUNT(*) AS c'
            .' FROM '.$quotedTable
            .' WHERE '.$quotedColumn.' IS NOT NULL'
            .' GROUP BY '.$quotedColumn
            .' HAVING COUNT(*)>1'
            .' LIMIT 1';

        $row=$pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            throw new RuntimeException(
                'UNIQUE kann nicht angelegt werden, weil im Feld bereits doppelte Werte vorhanden sind.'
            );
        }
    }

    private static function exactColumnDefinition(
        PDO $pdo,
        string $table,
        string $column
    ): string {
        self::assertManagedIdentifier($table,'Tabellenname');
        self::assertManagedIdentifier($column,'Feldname');

        $row=$pdo->query(
            'SHOW CREATE TABLE '.self::quoteIdentifier($table,'mysql')
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Die vollständige Tabellendefinition konnte nicht gelesen werden.');
        }

        $create='';
        foreach ($row as $key=>$value) {
            if (stripos((string)$key,'create table') !== false) {
                $create=(string)$value;
                break;
            }
        }
        if ($create === '') {
            $values=array_values($row);
            $create=(string)($values[1]??'');
        }
        if ($create === '') {
            throw new RuntimeException('Die vollständige CREATE-TABLE-Definition ist leer.');
        }

        $pattern='/^\\s*`'.preg_quote($column,'/').'`\\s+(.+?)(?:,)?\\s*$/mi';
        if (preg_match($pattern,$create,$match) !== 1) {
            throw new RuntimeException(
                'Die exakte Definition des Feldes „'.$column.'“ konnte nicht rekonstruiert werden.'
            );
        }
        $definition=trim((string)$match[1]);
        if (str_ends_with($definition,',')) {
            $definition=rtrim(substr($definition,0,-1));
        }
        if ($definition === '') {
            throw new RuntimeException('Die exakte Felddefinition ist leer.');
        }
        return $definition;
    }

    private static function csvDatabase(
        array $source,
        string $keyMaterial
    ): \DataForm\Database\Contracts\DatabaseInterface {
        if ((string)($source['driver']??'')!=='csv') {
            throw new RuntimeException('Die gewaehlte Datenquelle ist keine CSV-Datenquelle.');
        }
        if (empty($source['is_enabled'])) {
            throw new RuntimeException('Die CSV-Datenquelle ist deaktiviert.');
        }
        $config=DataSourceManager::runtimeConfig($source,$keyMaterial);
        return DatabaseFactory::create($config+['auto_connect'=>true]);
    }

    /** @return list<string> */
    private static function csvColumnNames(string $specification): array
    {
        $parts=preg_split('/\R+/',$specification,-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $columns=[];
        $seen=[];
        foreach ($parts as $part) {
            $part=trim($part);
            if ($part==='') {
                continue;
            }
            [$name]=array_pad(explode(':',$part,2),1,'');
            $name=trim($name);
            self::assertManagedIdentifier($name,'Spaltenname');
            if ($name==='id') {
                throw new RuntimeException('Die Spalte id wird automatisch erzeugt.');
            }
            $key=strtolower($name);
            if (isset($seen[$key])) {
                throw new RuntimeException('Die CSV-Spalte „'.$name.'“ wurde mehrfach angegeben.');
            }
            $seen[$key]=true;
            $columns[]=$name;
        }
        return $columns;
    }

    private static function parseColumns(string $specification): array
    {
        $parts=preg_split(
            '/\R+/',
            $specification,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];

        $result=[];
        $seen=[];

        foreach ($parts as $part) {
            $part=trim($part);
            if ($part === '') continue;

            [$name,$type]=array_pad(explode(':',$part,2),2,'varchar(255)');
            $name=trim($name);
            $type=strtolower(trim($type));

            self::assertManagedIdentifier($name,'Spaltenname');
            if ($name === 'id') {
                throw new RuntimeException(
                    'Die Spalte id wird automatisch erzeugt.'
                );
            }
            if (isset($seen[$name])) {
                throw new RuntimeException(
                    'Die Spalte „'.$name.'“ wurde mehrfach angegeben.'
                );
            }
            $seen[$name]=true;

            $sql=self::normalizeColumnType($type);
            $result[]=['name'=>$name,'sql'=>$sql];
        }

        return $result;
    }

    private static function isSqlitePdo(PDO $pdo): bool
    {
        if (class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo) return true;
        try { return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME))==='sqlite'; }
        catch (Throwable) { return false; }
    }

    private static function isPgsqlPdo(PDO $pdo): bool
    {
        if (class_exists('EnterprisePgsqlPdo',false) && $pdo instanceof EnterprisePgsqlPdo) return true;
        try { return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME))==='pgsql'; }
        catch (Throwable) { return false; }
    }

    private static function isOraclePdo(PDO $pdo): bool
    {
        if (class_exists('EnterpriseOraclePdo',false) && $pdo instanceof EnterpriseOraclePdo) return true;
        try { return in_array(strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)),['oci','oci8'],true); }
        catch (Throwable) { return false; }
    }

    private static function sqliteRequestedColumnDefinition(
        PDO $pdo,string $type,bool $nullable,string $defaultKind,?string $defaultValue,array $extras
    ): string {
        $base=strtolower(trim($type));
        self::normalizeColumnType($base); // validates the public type grammar
        $sqliteType=match(true){
            $base==='text', preg_match('/^varchar\(/',$base)===1 => 'TEXT',
            in_array($base,['int','integer','bigint','bool','boolean'],true) => 'INTEGER',
            str_starts_with($base,'decimal(') => 'NUMERIC',
            in_array($base,['date','datetime','timestamp'],true) => 'TEXT',
            default => 'TEXT',
        };
        $extras=self::normalizeExtras($extras);
        if(in_array('auto_increment',$extras,true)){
            throw new RuntimeException('SQLite unterstützt AUTO_INCREMENT nur für das verwaltete INTEGER-Primärfeld id.');
        }
        $def=$sqliteType.($nullable?' NULL':' NOT NULL');
        $defaultKind=strtolower(trim($defaultKind));
        if($defaultKind==='null'){
            if(!$nullable)throw new RuntimeException('DEFAULT NULL ist nur bei NULL-fähigen Feldern zulässig.');
            $def.=' DEFAULT NULL';
        }elseif($defaultKind==='literal'){
            $literal=(string)($defaultValue??'');
            if(in_array($sqliteType,['INTEGER','NUMERIC'],true)){
                if($literal===''||!is_numeric($literal))throw new RuntimeException('Der SQLite-Vorgabewert muss für diesen numerischen Datentyp eine Zahl sein.');
                $def.=' DEFAULT '.$literal;
            }else{
                $def.=' DEFAULT '.$pdo->quote($literal);
            }
        }elseif($defaultKind==='current_timestamp'){
            if(!in_array($base,['datetime','timestamp'],true))throw new RuntimeException('CURRENT_TIMESTAMP ist nur für DATETIME/TIMESTAMP zulässig.');
            $def.=' DEFAULT CURRENT_TIMESTAMP';
        }elseif($defaultKind!=='none'){
            throw new RuntimeException('Ungültige Vorgabewert-Art.');
        }
        return $def;
    }

    /**
     * SQLite kann Datentyp, NULL-Regel und Spaltenreihenfolge nicht mit
     * MODIFY/CHANGE ändern. Deshalb wird eine Anwendungstabelle transaktional
     * neu aufgebaut. Primärschlüssel, Fremdschlüssel, Indizes und Trigger
     * werden dabei erhalten; die Pflichtspalte id bleibt an Position 1.
     */
    private static function sqliteRebuildTable(PDO $pdo,string $table,array $change): void
    {
        self::assertManagedIdentifier($table,'Tabellenname');
        $tq=str_replace("'","''",$table);
        $columns=$pdo->query("PRAGMA table_info('{$tq}')")->fetchAll(PDO::FETCH_ASSOC);
        if($columns===[])throw new RuntimeException('SQLite-Tabellenschema konnte nicht gelesen werden.');
        $mode=(string)($change['mode']??'');$target=(string)($change['column']??'');
        $newName=(string)($change['new_column']??$target);

        $ordered=$columns;
        if($mode==='drop'){
            $ordered=array_values(array_filter($ordered,static fn(array $c):bool=>(string)$c['name']!==$target));
        }elseif($mode==='move'){
            $names=array_map(static fn(array $c):string=>(string)$c['name'],$ordered);
            $i=array_search($target,$names,true);if($i===false)throw new RuntimeException('SQLite-Feld wurde nicht gefunden.');
            $row=$ordered[$i];array_splice($ordered,$i,1);
            $direction=(string)($change['direction']??'up');
            $newIndex=$direction==='up'?max(1,$i-1):min(count($ordered),$i+1);
            array_splice($ordered,$newIndex,0,[$row]);
        }
        if($ordered===[])throw new RuntimeException('Eine SQLite-Tabelle darf nicht ohne Felder verbleiben.');

        // Snapshot indexes/triggers/FKs before touching the table.
        $indexSnapshots=[];
        foreach($pdo->query("PRAGMA index_list('{$tq}')")->fetchAll(PDO::FETCH_ASSOC) as $idx){
            if((string)($idx['origin']??'')==='pk')continue;
            $idxName=(string)($idx['name']??'');if($idxName==='')continue;
            $iq=str_replace("'","''",$idxName);
            $cols=array_values(array_filter(array_map(static fn(array $r):string=>(string)($r['name']??''),$pdo->query("PRAGMA index_info('{$iq}')")->fetchAll(PDO::FETCH_ASSOC))));
            $indexSnapshots[]=['name'=>$idxName,'unique'=>(int)($idx['unique']??0)===1,'origin'=>(string)($idx['origin']??''),'columns'=>$cols];
        }
        $triggers=$pdo->prepare("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND tbl_name=? AND sql IS NOT NULL");$triggers->execute([$table]);$triggerSnapshots=$triggers->fetchAll(PDO::FETCH_ASSOC);
        $fkRows=$pdo->query("PRAGMA foreign_key_list('{$tq}')")->fetchAll(PDO::FETCH_ASSOC);

        $pkCols=[];foreach($columns as $c)if((int)($c['pk']??0)>0)$pkCols[(int)$c['pk']]=(string)$c['name'];ksort($pkCols);
        $defs=[];$insertNew=[];$selectOld=[];
        foreach($ordered as $c){
            $old=(string)$c['name'];$name=($mode==='update'&&$old===$target)?$newName:$old;
            $insertNew[]=$name;$selectOld[]=$old;
            if($mode==='update'&&$old===$target){
                $def=self::sqliteRequestedColumnDefinition($pdo,(string)$change['type'],(bool)$change['nullable'],(string)$change['default_kind'],$change['default_value']??null,(array)$change['extras']);
                $defs[]=self::quoteIdentifier($name,'sqlite').' '.$def;
                continue;
            }
            $type=trim((string)($c['type']??''));if($type==='')$type='TEXT';
            $singleIntegerPk=count($pkCols)===1 && reset($pkCols)===$old && strtoupper($type)==='INTEGER';
            if($singleIntegerPk){$defs[]=self::quoteIdentifier($name,'sqlite').' INTEGER PRIMARY KEY AUTOINCREMENT';continue;}
            $def=self::quoteIdentifier($name,'sqlite').' '.$type;
            if((int)($c['notnull']??0)===1)$def.=' NOT NULL';
            if(array_key_exists('dflt_value',$c)&&$c['dflt_value']!==null)$def.=' DEFAULT '.(string)$c['dflt_value'];
            $defs[]=$def;
        }
        if(count($pkCols)>1){
            $mapped=array_map(static function(string $n)use($mode,$target,$newName):string{return self::quoteIdentifier($mode==='update'&&$n===$target?$newName:$n,'sqlite');},array_values($pkCols));
            $defs[]='PRIMARY KEY ('.implode(',',$mapped).')';
        }
        // Recreate outgoing foreign keys, adapting a renamed local column.
        $fkGroups=[];foreach($fkRows as $fk)$fkGroups[(int)$fk['id']][]=$fk;
        foreach($fkGroups as $rows){usort($rows,static fn($a,$b)=>(int)$a['seq']<=>(int)$b['seq']);$ref=(string)$rows[0]['table'];$from=[];$to=[];foreach($rows as $fk){$f=(string)$fk['from'];if($mode==='drop'&&$f===$target)continue 2;if($mode==='update'&&$f===$target)$f=$newName;$from[]=self::quoteIdentifier($f,'sqlite');$to[]=self::quoteIdentifier((string)$fk['to'],'sqlite');}$clause='FOREIGN KEY ('.implode(',',$from).') REFERENCES '.self::quoteIdentifier($ref,'sqlite').' ('.implode(',',$to).')';$onUpdate=strtoupper((string)($rows[0]['on_update']??''));$onDelete=strtoupper((string)($rows[0]['on_delete']??''));if($onUpdate!==''&&$onUpdate!=='NO ACTION')$clause.=' ON UPDATE '.$onUpdate;if($onDelete!==''&&$onDelete!=='NO ACTION')$clause.=' ON DELETE '.$onDelete;$defs[]=$clause;}

        $tmp='__df_rebuild_'.substr(hash('sha256',$table.microtime(true).random_int(1,PHP_INT_MAX)),0,12);
        $pdo->exec('PRAGMA foreign_keys=OFF');
        try{
            $pdo->beginTransaction();
            $pdo->exec('CREATE TABLE '.self::quoteIdentifier($tmp,'sqlite').' ('.implode(', ',$defs).')');
            $pdo->exec('INSERT INTO '.self::quoteIdentifier($tmp,'sqlite').' ('.implode(',',array_map(static fn($n)=>self::quoteIdentifier($n,'sqlite'),$insertNew)).') SELECT '.implode(',',array_map(static fn($n)=>self::quoteIdentifier($n,'sqlite'),$selectOld)).' FROM '.self::quoteIdentifier($table,'sqlite'));
            $pdo->exec('DROP TABLE '.self::quoteIdentifier($table,'sqlite'));
            $pdo->exec('ALTER TABLE '.self::quoteIdentifier($tmp,'sqlite').' RENAME TO '.self::quoteIdentifier($table,'sqlite'));

            foreach($indexSnapshots as $idx){
                $cols=(array)$idx['columns'];
                if($mode==='drop'&&in_array($target,$cols,true))continue;
                $mapped=array_map(static fn(string $n):string=>$mode==='update'&&$n===$target?$newName:$n,$cols);
                $isManaged=str_starts_with((string)$idx['name'],'idx_df_')||str_starts_with((string)$idx['name'],'uq_df_');
                if($mode==='update'&&$isManaged&&in_array($newName,$mapped,true))continue; // replaced below
                $name=(string)$idx['name'];if(str_starts_with($name,'sqlite_autoindex_'))$name='uq_df_rebuild_'.substr(hash('sha256',$table.implode('|',$mapped)),0,12);
                $pdo->exec('CREATE '.(!empty($idx['unique'])?'UNIQUE ':'').'INDEX '.self::quoteIdentifier($name,'sqlite').' ON '.self::quoteIdentifier($table,'sqlite').' ('.implode(',',array_map(static fn($n)=>self::quoteIdentifier($n,'sqlite'),$mapped)).')');
            }
            if($mode==='update' && (string)($change['index_kind']??'none')!=='none'){
                $unique=(string)$change['index_kind']==='unique';$name=self::managedIndexName($table,$newName,$unique);
                $pdo->exec('CREATE '.($unique?'UNIQUE ':'').'INDEX '.self::quoteIdentifier($name,'sqlite').' ON '.self::quoteIdentifier($table,'sqlite').' ('.self::quoteIdentifier($newName,'sqlite').')');
            }
            foreach($triggerSnapshots as $trigger){
                $sql=(string)($trigger['sql']??'');if($sql==='')continue;
                if($mode==='drop'&&preg_match('/\\b'.preg_quote($target,'/').'\\b/i',$sql)===1)continue;
                if($mode==='update')$sql=preg_replace('/\\b'.preg_quote($target,'/').'\\b/',$newName,$sql)??$sql;
                $pdo->exec($sql);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$pdo->exec('PRAGMA foreign_keys=ON');}
        $violations=$pdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC);
        if($violations!==[])throw new RuntimeException('SQLite-Schemaänderung erzeugte Fremdschlüsselverletzungen; Tabelle bitte aus Sicherung prüfen.');
    }

    private static function pgsqlOnUpdateObjectNames(string $table,string $column): array
    {
        $hash=substr(hash('sha256',$table."\0".$column),0,16);
        return [
            'trigger'=>'df_touch_'.$hash,
            'function'=>'df_touch_fn_'.$hash,
        ];
    }

    private static function pgsqlHasOnUpdateTrigger(PDO $pdo,string $table,string $column): bool
    {
        if(!self::isPgsqlPdo($pdo)) return false;
        $names=self::pgsqlOnUpdateObjectNames($table,$column);
        $stmt=$pdo->prepare(
            "SELECT COUNT(*) FROM pg_trigger tg "
            ."JOIN pg_class c ON c.oid=tg.tgrelid "
            ."JOIN pg_namespace n ON n.oid=c.relnamespace "
            ."WHERE n.nspname=current_schema() AND c.relname=? "
            ."AND tg.tgname=? AND NOT tg.tgisinternal"
        );
        $stmt->execute([$table,$names['trigger']]);
        return (int)$stmt->fetchColumn()>0;
    }

    private static function syncPgsqlOnUpdateTrigger(
        PDO $pdo,
        string $table,
        ?string $oldColumn,
        string $newColumn,
        bool $enabled
    ): void {
        if(!self::isPgsqlPdo($pdo)) return;

        $quotedTable=self::quoteIdentifier($table,'pgsql');
        $drop=function(string $column) use ($pdo,$quotedTable): void {
            $names=self::pgsqlOnUpdateObjectNames(trim($quotedTable,'"'),$column);
            $quotedTrigger=self::quoteIdentifier($names['trigger'],'pgsql');
            $quotedFunction=self::quoteIdentifier($names['function'],'pgsql');
            $pdo->exec('DROP TRIGGER IF EXISTS '.$quotedTrigger.' ON '.$quotedTable);
            $pdo->exec('DROP FUNCTION IF EXISTS '.$quotedFunction.'()');
        };

        if($oldColumn!==null && $oldColumn!=='') $drop($oldColumn);
        if($oldColumn===null || $oldColumn!==$newColumn) $drop($newColumn);
        if(!$enabled) return;

        $names=self::pgsqlOnUpdateObjectNames($table,$newColumn);
        $quotedTrigger=self::quoteIdentifier($names['trigger'],'pgsql');
        $quotedFunction=self::quoteIdentifier($names['function'],'pgsql');
        $quotedColumn=self::quoteIdentifier($newColumn,'pgsql');
        $pdo->exec(
            'CREATE OR REPLACE FUNCTION '.$quotedFunction.'() RETURNS trigger AS $$ '
            .'BEGIN IF NEW.'.$quotedColumn.' IS NOT DISTINCT FROM OLD.'.$quotedColumn.' THEN '
            .'NEW.'.$quotedColumn.' = CURRENT_TIMESTAMP; END IF; RETURN NEW; END; '
            .'$$ LANGUAGE plpgsql'
        );
        $pdo->exec(
            'CREATE TRIGGER '.$quotedTrigger.' BEFORE UPDATE ON '.$quotedTable
            .' FOR EACH ROW EXECUTE FUNCTION '.$quotedFunction.'()'
        );
    }

    private static function normalizeColumnType(string $type): string
    {
        return match (true) {
            $type === 'text' => 'TEXT',
            $type === 'int', $type === 'integer' => 'INT',
            $type === 'bigint' => 'BIGINT',
            $type === 'date' => 'DATE',
            $type === 'datetime' => 'DATETIME',
            $type === 'timestamp' => 'TIMESTAMP',
            $type === 'bool', $type === 'boolean' => 'TINYINT(1)',
            preg_match('/^varchar\((\d{1,4})\)$/',$type,$m) === 1
                && (int)$m[1] >= 1
                && (int)$m[1] <= 2000
                => 'VARCHAR('.(int)$m[1].')',
            preg_match('/^decimal\((\d{1,2}),(\d{1,2})\)$/',$type,$m) === 1
                && (int)$m[1] >= 1
                && (int)$m[1] <= 65
                && (int)$m[2] >= 0
                && (int)$m[2] <= 30
                && (int)$m[2] <= (int)$m[1]
                => 'DECIMAL('.(int)$m[1].','.(int)$m[2].')',
            default => throw new RuntimeException(
                'Nicht unterstützter Spaltentyp „'.$type.'“. '
                .'Zulässig sind varchar(n), text, int, bigint, decimal(p,s), '
                .'date, datetime, timestamp und boolean.'
            ),
        };
    }

    private static function systemTableExists(PDO $pdo, string $table): bool
    {
        if (self::isOraclePdo($pdo)) {
            $stmt=$pdo->prepare('SELECT COUNT(*) FROM user_tables WHERE table_name=UPPER(?)');
        } else {
            $stmt=$pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables '
                .'WHERE table_schema=DATABASE() AND table_name=?'
            );
        }
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function assertManagedIdentifier(
        string $identifier,
        string $label
    ): void {
        if (
            !preg_match(
                '/^[A-Za-z][A-Za-z0-9_]{0,63}$/',
                $identifier
            )
        ) {
            throw new RuntimeException(
                $label.' darf nur mit einem Buchstaben beginnen und '
                .'Buchstaben, Ziffern oder _ enthalten (max. 64 Zeichen).'
            );
        }
    }

    private static function quoteIdentifier(
        string $identifier,
        string $driver
    ): string {
        if ($driver === 'mysql') {
            return '`'.str_replace('`','``',$identifier).'`';
        }
        if ($driver === 'mssql') {
            return '['.str_replace(']',']]', $identifier).']';
        }
        return '"'.str_replace('"','""',$identifier).'"';
    }
}
