<?php
declare(strict_types=1);

require_once __DIR__ . '/DataSourceManager.php';
require_once dirname(__DIR__, 3) . '/DataForm5-Core/system/database/autoload.php';

/**
 * Kanonischer Datensatzspeicher der DataForm-Runtime.
 *
 * Speicherarten:
 * - generic: dataform_records in der Projekt-Metadatenbank,
 * - system: physische MySQL/MariaDB-, PostgreSQL- oder SQLite-Projekttabelle,
 * - external/csv|sqlite|pgsql|oracle|mssql: physische CSV-/SQLite-/PostgreSQL-/Oracle-Tabelle ueber DatabaseFactory.
 */
// RC1.1 compatibility markers for cumulative predecessor gates: ['csv','sqlite','pgsql'] / ['csv','sqlite','pgsql','oracle']
final class DataFormRecordStore
{
    public static function binding(PDO $pdo, int $dataformId): ?array
    {
        $exists=(int)$pdo->query(
            "SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
               AND table_name='dataform_table_bindings'"
        )->fetchColumn();

        if ($exists!==1) {
            return null;
        }

        $stmt=$pdo->prepare(
            "SELECT *
             FROM dataform_table_bindings
             WHERE dataform_id=?
             LIMIT 1"
        );
        $stmt->execute([$dataformId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $table=(string)$row['table_name'];
        self::assertIdentifier($table);
        $sourceKind=(string)($row['source_kind']??'system');
        $sourceId=(int)($row['source_id']??0);

        if ($sourceKind==='system' && $sourceId===0) {
            $check=$pdo->prepare(
                "SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?"
            );
            $check->execute([$table]);
            if ((int)$check->fetchColumn()!==1) {
                throw new RuntimeException(
                    'Die an das DataForm gebundene Projekttabelle „'.$table.'“ existiert nicht.'
                );
            }
            $row['_driver']=$pdo instanceof EnterpriseSqlitePdo ? 'sqlite' : ((class_exists('EnterprisePgsqlPdo',false) && $pdo instanceof EnterprisePgsqlPdo) ? 'pgsql' : ((class_exists('EnterpriseOraclePdo',false) && $pdo instanceof EnterpriseOraclePdo) ? 'oracle' : ((class_exists('EnterpriseMssqlPdo',false) && $pdo instanceof EnterpriseMssqlPdo) ? 'mssql' : 'mysql')));
            $row['_source_name']=match($row['_driver']){'sqlite'=>'Projekt-SQLite-Datenbank','pgsql'=>'Projekt-PostgreSQL-Datenbank','oracle'=>'Projekt-Oracle-XE-Datenbank','mssql'=>'Projekt-Microsoft-SQL-Server-Datenbank',default=>'Projekt-Datenbank'};
            return $row;
        }

        if ($sourceKind==='external' && $sourceId>0) {
            $source=self::sourceRow($pdo,$sourceId);
            $driver=(string)$source['driver'];
            if (!in_array($driver,['csv','sqlite','pgsql','oracle','mssql'],true)) {
                throw new RuntimeException(
                    'Der gebundene externe Datenquellentyp „'.$driver.'“ wird in dieser Phase noch nicht schreibend unterstuetzt.'
                );
            }
            $db=self::externalDatabase($source);
            try {
                if (!$db->tableExists($table)) {
                    throw new RuntimeException(
                        'Die an das DataForm gebundene '.$driver.'-Tabelle „'.$table.'“ existiert nicht.'
                    );
                }
                $columns=$db->columns($table);
                if (($columns[0]??'')!=='id') {
                    throw new RuntimeException('Die gebundene externe Tabelle besitzt keine gueltige Pflichtspalte id.');
                }
            } finally {
                $db->disconnect();
            }
            $row['_driver']=$driver;
            $row['_source_name']=(string)$source['name'];
            return $row;
        }

        throw new RuntimeException('Ungueltige oder noch nicht unterstuetzte DataForm-Tabellenbindung.');
    }

    public static function isPhysical(PDO $pdo, int $dataformId): bool
    {
        return self::binding($pdo,$dataformId)!==null;
    }

    public static function all(PDO $pdo, int $dataformId): array
    {
        $binding=self::binding($pdo,$dataformId);
        if ($binding===null) {
            $stmt=$pdo->prepare(
                'SELECT *
                 FROM dataform_records
                 WHERE dataform_id=?
                 ORDER BY id'
            );
            $stmt->execute([$dataformId]);

            $rows=[];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $row['data']=json_decode((string)$row['data_json'],true)?:[];
                $rows[]=$row;
            }
            return $rows;
        }

        $table=(string)$binding['table_name'];
        if (self::isExternalAdapterBinding($binding)) {
            $source=self::sourceRow($pdo,(int)$binding['source_id']);
            $db=self::externalDatabase($source);
            try {
                $rows=$db->all($table);
                usort($rows,static fn(array $a,array $b): int => (int)$a['id'] <=> (int)$b['id']);
                $fieldMap=self::csvColumnMap($pdo,$dataformId,$table,$db->columns($table));
                return array_map(
                    static fn(array $row): array => self::normalizePhysicalRow($row,$fieldMap,(string)$binding['_driver']),
                    $rows
                );
            } finally {
                $db->disconnect();
            }
        }

        $rows=$pdo->query(
            'SELECT * FROM '.self::quoteIdentifier($table).' ORDER BY `id`'
        )->fetchAll(PDO::FETCH_ASSOC);
        $fieldMap=self::physicalColumnMap($pdo,$dataformId,$table);

        return array_map(
            static fn(array $row): array => self::normalizePhysicalRow($row,$fieldMap,(string)($binding['_driver']??'mysql')),
            $rows
        );
    }

    public static function find(PDO $pdo, int $dataformId, int $recordId): ?array
    {
        if ($recordId<1) {
            return null;
        }

        $binding=self::binding($pdo,$dataformId);
        if ($binding===null) {
            $stmt=$pdo->prepare(
                'SELECT *
                 FROM dataform_records
                 WHERE id=? AND dataform_id=?
                 LIMIT 1'
            );
            $stmt->execute([$recordId,$dataformId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $row['data']=json_decode((string)$row['data_json'],true)?:[];
            return $row;
        }

        $table=(string)$binding['table_name'];
        if (self::isExternalAdapterBinding($binding)) {
            $source=self::sourceRow($pdo,(int)$binding['source_id']);
            $db=self::externalDatabase($source);
            try {
                $row=$db->find($table,$recordId);
                if ($row===null) {
                    return null;
                }
                $fieldMap=self::csvColumnMap($pdo,$dataformId,$table,$db->columns($table));
                return self::normalizePhysicalRow($row,$fieldMap,(string)$binding['_driver']);
            } finally {
                $db->disconnect();
            }
        }

        $stmt=$pdo->prepare(
            'SELECT * FROM '.self::quoteIdentifier($table).' WHERE `id`=? LIMIT 1'
        );
        $stmt->execute([$recordId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return self::normalizePhysicalRow(
            $row,
            self::physicalColumnMap($pdo,$dataformId,$table),
            (string)($binding['_driver']??'mysql')
        );
    }

    public static function create(PDO $pdo, int $dataformId, array $data): int
    {
        $binding=self::binding($pdo,$dataformId);
        if ($binding===null) {
            $stmt=$pdo->prepare(
                'INSERT INTO dataform_records (dataform_id,data_json) VALUES (?,?)'
            );
            $stmt->execute([
                $dataformId,
                json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
            return (int)$pdo->lastInsertId();
        }

        $table=(string)$binding['table_name'];
        if (self::isExternalAdapterBinding($binding)) {
            $source=self::sourceRow($pdo,(int)$binding['source_id']);
            $db=self::externalDatabase($source);
            try {
                $columns=self::csvColumnMap($pdo,$dataformId,$table,$db->columns($table));
                $values=[];
                foreach ($columns as $fieldName=>$meta) {
                    if (!array_key_exists($fieldName,$data)) {
                        continue;
                    }
                    $values[$meta['column']]=self::normalizeWriteValue($data[$fieldName],$meta);
                }
                return $db->insert($table,$values);
            } finally {
                $db->disconnect();
            }
        }

        $columns=self::physicalColumnMap($pdo,$dataformId,$table);
        $names=[];
        $values=[];
        foreach ($columns as $fieldName=>$meta) {
            if (!array_key_exists($fieldName,$data)) {
                continue;
            }
            $names[]=$meta['column'];
            $values[]=self::normalizeWriteValue($data[$fieldName],$meta);
        }

        if ($names===[]) {
            $pdo->exec('INSERT INTO '.self::quoteIdentifier($table).' () VALUES ()');
        } else {
            $quoted=array_map(static fn(string $name): string => self::quoteIdentifier($name),$names);
            $placeholders=implode(',',array_fill(0,count($names),'?'));
            $stmt=$pdo->prepare(
                'INSERT INTO '.self::quoteIdentifier($table)
                .' ('.implode(',',$quoted).') VALUES ('.$placeholders.')'
            );
            $stmt->execute($values);
        }
        return (int)$pdo->lastInsertId();
    }

    public static function update(PDO $pdo, int $dataformId, int $recordId, array $data): void
    {
        $binding=self::binding($pdo,$dataformId);
        if ($binding===null) {
            $stmt=$pdo->prepare(
                'UPDATE dataform_records SET data_json=? WHERE id=? AND dataform_id=?'
            );
            $stmt->execute([
                json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                $recordId,
                $dataformId,
            ]);
            if ($stmt->rowCount()<1 && self::find($pdo,$dataformId,$recordId)===null) {
                throw new RuntimeException('Der Datensatz wurde nicht gefunden.');
            }
            return;
        }

        if (self::find($pdo,$dataformId,$recordId)===null) {
            throw new RuntimeException('Der Datensatz wurde nicht gefunden.');
        }

        $table=(string)$binding['table_name'];
        if (self::isExternalAdapterBinding($binding)) {
            $source=self::sourceRow($pdo,(int)$binding['source_id']);
            $db=self::externalDatabase($source);
            try {
                $columns=self::csvColumnMap($pdo,$dataformId,$table,$db->columns($table));
                $values=[];
                foreach ($columns as $fieldName=>$meta) {
                    if (!array_key_exists($fieldName,$data)) {
                        continue;
                    }
                    $values[$meta['column']]=self::normalizeWriteValue($data[$fieldName],$meta);
                }
                if ($values!==[] && !$db->update($table,$recordId,$values)) {
                    throw new RuntimeException('Der externe Datensatz konnte nicht aktualisiert werden.');
                }
                return;
            } finally {
                $db->disconnect();
            }
        }

        $columns=self::physicalColumnMap($pdo,$dataformId,$table);
        $sets=[];
        $values=[];
        foreach ($columns as $fieldName=>$meta) {
            if (!array_key_exists($fieldName,$data)) {
                continue;
            }
            $sets[]=self::quoteIdentifier((string)$meta['column']).'=?';
            $values[]=self::normalizeWriteValue($data[$fieldName],$meta);
        }
        if ($sets===[]) {
            return;
        }
        $values[]=$recordId;
        $stmt=$pdo->prepare(
            'UPDATE '.self::quoteIdentifier($table).' SET '.implode(',',$sets).' WHERE `id`=?'
        );
        $stmt->execute($values);
    }

    public static function delete(PDO $pdo, int $dataformId, int $recordId): void
    {
        $binding=self::binding($pdo,$dataformId);
        if ($binding===null) {
            $stmt=$pdo->prepare(
                'DELETE FROM dataform_records WHERE id=? AND dataform_id=?'
            );
            $stmt->execute([$recordId,$dataformId]);
            if ($stmt->rowCount()!==1) {
                throw new RuntimeException('Der Datensatz wurde nicht gefunden.');
            }
            return;
        }

        $table=(string)$binding['table_name'];
        if (self::isExternalAdapterBinding($binding)) {
            $source=self::sourceRow($pdo,(int)$binding['source_id']);
            $db=self::externalDatabase($source);
            try {
                if (!$db->delete($table,$recordId)) {
                    throw new RuntimeException('Der Datensatz wurde nicht gefunden.');
                }
                return;
            } finally {
                $db->disconnect();
            }
        }

        $stmt=$pdo->prepare(
            'DELETE FROM '.self::quoteIdentifier($table).' WHERE `id`=?'
        );
        $stmt->execute([$recordId]);
        if ($stmt->rowCount()!==1) {
            throw new RuntimeException('Der Datensatz wurde nicht gefunden.');
        }
    }

    public static function bulkDelete(PDO $pdo, int $dataformId, array $recordIds): int
    {
        $ids=array_values(array_unique(array_filter(
            array_map('intval',$recordIds),
            static fn(int $id): bool => $id>0
        )));
        if ($ids===[]) {
            return 0;
        }

        $binding=self::binding($pdo,$dataformId);
        if ($binding!==null && self::isExternalAdapterBinding($binding)) {
            $source=self::sourceRow($pdo,(int)$binding['source_id']);
            $db=self::externalDatabase($source);
            $count=0;
            try {
                foreach ($ids as $id) {
                    if ($db->delete((string)$binding['table_name'],$id)) {
                        $count++;
                    }
                }
                return $count;
            } finally {
                $db->disconnect();
            }
        }

        $ph=implode(',',array_fill(0,count($ids),'?'));
        if ($binding===null) {
            $stmt=$pdo->prepare(
                'DELETE FROM dataform_records WHERE dataform_id=? AND id IN ('.$ph.')'
            );
            $stmt->execute(array_merge([$dataformId],$ids));
        } else {
            $table=(string)$binding['table_name'];
            $stmt=$pdo->prepare(
                'DELETE FROM '.self::quoteIdentifier($table).' WHERE `id` IN ('.$ph.')'
            );
            $stmt->execute($ids);
        }
        return $stmt->rowCount();
    }

    public static function displayCaption(
        PDO $pdo,
        int $dataformId,
        int $recordId,
        ?string $displayFieldName=null
    ): string {
        $record=self::find($pdo,$dataformId,$recordId);
        if ($record===null) {
            return '#'.$recordId;
        }
        if ($displayFieldName!==null) {
            $caption=trim((string)($record['data'][$displayFieldName]??''));
            if ($caption!=='') {
                return $caption;
            }
        }
        return '#'.$recordId;
    }

    private static function physicalColumnMap(PDO $pdo, int $dataformId, string $table): array
    {
        $stmt=$pdo->prepare(
            'SELECT name,configuration_json
             FROM dataform_fields
             WHERE dataform_id=?
             ORDER BY position,id'
        );
        $stmt->execute([$dataformId]);

        $metadata=[];
        foreach ($pdo->query(
            'SHOW FULL COLUMNS FROM '.self::quoteIdentifier($table)
        )->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $metadata[strtolower((string)$column['Field'])]=$column;
        }

        $map=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $fieldName=(string)$field['name'];
            $columnName=self::fieldColumnName($fieldName,$field['configuration_json']??null);
            self::assertIdentifier($columnName);
            $key=strtolower($columnName);
            if (!isset($metadata[$key])) {
                continue;
            }
            $column=$metadata[$key];
            $map[$fieldName]=[
                'column'=>$columnName,
                'type'=>strtolower((string)$column['Type']),
                'nullable'=>strtoupper((string)$column['Null'])==='YES',
                'default'=>$column['Default'],
                'extra'=>strtolower((string)$column['Extra']),
            ];
        }
        return $map;
    }

    private static function csvColumnMap(
        PDO $pdo,
        int $dataformId,
        string $table,
        array $columns
    ): array {
        self::assertIdentifier($table);
        $available=[];
        foreach ($columns as $column) {
            self::assertIdentifier((string)$column);
            $available[strtolower((string)$column)]=(string)$column;
        }

        $stmt=$pdo->prepare(
            'SELECT name,configuration_json
             FROM dataform_fields
             WHERE dataform_id=?
             ORDER BY position,id'
        );
        $stmt->execute([$dataformId]);

        $map=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $field) {
            $fieldName=(string)$field['name'];
            $columnName=self::fieldColumnName($fieldName,$field['configuration_json']??null);
            self::assertIdentifier($columnName);
            $key=strtolower($columnName);
            if ($key==='id' || !isset($available[$key])) {
                continue;
            }
            $map[$fieldName]=[
                'column'=>$available[$key],
                'type'=>'text',
                'nullable'=>true,
                'default'=>'',
                'extra'=>'csv',
            ];
        }
        return $map;
    }

    private static function fieldColumnName(string $fieldName,mixed $raw): string
    {
        $columnName=$fieldName;
        if (is_string($raw) && trim($raw)!=='') {
            $cfg=json_decode($raw,true);
            if (is_array($cfg) && is_array($cfg['table_binding']??null)) {
                $configured=(string)($cfg['table_binding']['column']??'');
                if ($configured!=='') {
                    $columnName=$configured;
                }
            }
        }
        return $columnName;
    }

    private static function normalizeWriteValue(mixed $value, array $meta): mixed
    {
        if (is_bool($value)) {
            $value=$value?'1':'0';
        }
        if ($value===null) {
            return null;
        }

        $string=(string)$value;
        $type=(string)$meta['type'];
        if ($string==='' && !empty($meta['nullable']) && $type!=='text') {
            return null;
        }
        if (preg_match('/^(?:datetime|timestamp)/',$type)===1
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/',$string)===1) {
            return str_replace('T',' ',$string);
        }
        return $string;
    }

    private static function normalizePhysicalRow(array $row, array $fieldMap, string $storage): array
    {
        $id=(int)($row['id']??0);
        $data=[];
        foreach ($fieldMap as $fieldName=>$meta) {
            $column=(string)$meta['column'];
            $value=$row[$column]??null;
            if ($value===null) {
                $data[$fieldName]='';
            } elseif ($value instanceof DateTimeInterface) {
                $data[$fieldName]=$value->format('Y-m-d H:i:s');
            } elseif (!is_scalar($value)) {
                $data[$fieldName]=(string)json_encode(
                    $value,
                    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
                );
            } else {
                $data[$fieldName]=(string)$value;
            }
        }

        return [
            'id'=>$id,
            'data_json'=>json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            'data'=>$data,
            'created_at'=>isset($row['created_at'])?(string)$row['created_at']:'',
            'updated_at'=>isset($row['updated_at'])?(string)$row['updated_at']:'',
            '_physical'=>true,
            '_storage'=>$storage,
        ];
    }

    private static function isExternalAdapterBinding(array $binding): bool
    {
        return (string)($binding['source_kind']??'')==='external'
            && (int)($binding['source_id']??0)>0
            && in_array((string)($binding['_driver']??''),['csv','sqlite','pgsql','oracle','mssql'],true);
    }

    private static function sourceRow(PDO $pdo,int $sourceId): array
    {
        $source=DataSourceManager::find($pdo,$sourceId);
        if (!$source) {
            throw new RuntimeException('Die gebundene Datenquelle wurde nicht gefunden.');
        }
        if (empty($source['is_enabled'])) {
            throw new RuntimeException('Die gebundene Datenquelle ist deaktiviert.');
        }
        return $source;
    }

    private static function externalDatabase(array $source): \DataForm\Database\Contracts\DatabaseInterface
    {
        if (!in_array((string)($source['driver']??''),['csv','sqlite','pgsql','oracle','mssql'],true)) {
            throw new RuntimeException('Die Datenquelle ist keine schreibbare CSV-/SQLite-/PostgreSQL-/Oracle-/MSSQL-Datenquelle.');
        }
        $config=DataSourceManager::runtimeConfig($source,'');
        return \DataForm\Database\DatabaseFactory::create($config+['auto_connect'=>true]);
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/',$identifier)!==1) {
            throw new RuntimeException(
                'Ungueltiger Tabellen- oder Feldname. DataForm-kompatible Tabellen-/Feldnamen duerfen nur Buchstaben, Ziffern und Unterstriche enthalten und muessen mit einem Buchstaben beginnen.'
            );
        }
    }

    private static function quoteIdentifier(string $identifier): string
    {
        self::assertIdentifier($identifier);
        return '`'.$identifier.'`';
    }
}
