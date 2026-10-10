<?php
declare(strict_types=1);

require_once __DIR__ . '/project_store.php';

/**
 * Project deletion backup helper (HF72).
 *
 * Creates a session-protected ZIP archive containing the project registration
 * metadata and a restorable SQL dump of the physical project database.
 */

function enterprise_project_backup_root(): string
{
    return enterprise_system_storage_ensure('archive/project-backups');
}

function enterprise_project_backup_project_root(array $project): string
{
    $key = enterprise_project_storage_validate_key((string)($project['storage_key'] ?? ''));
    $root = enterprise_project_backup_root() . '/' . $key;
    enterprise_project_storage_mkdir($root);
    return $root;
}

function enterprise_project_backup_ensure_root(): string
{
    $root = enterprise_project_backup_root();

    $denyFile = $root . '/.htaccess';
    if (!is_file($denyFile)) {
        @file_put_contents($denyFile, "Require all denied\nDeny from all\n");
    }
    $indexFile = $root . '/index.html';
    if (!is_file($indexFile)) {
        @file_put_contents($indexFile, "<!doctype html><title>403</title>\n");
    }

    return $root;
}

function enterprise_project_backup_cleanup(int $maxAgeSeconds = 86400): void
{
    $root = enterprise_project_backup_ensure_root();
    $cutoff = time() - max(3600, $maxAgeSeconds);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile() || strtolower($item->getExtension()) !== 'zip') continue;
        if ((int)@$item->getMTime() < $cutoff) @unlink($item->getPathname());
    }
}

function enterprise_project_backup_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'projekt';
    }
    $ascii = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?? 'projekt';
    $value = trim($value, '-._');
    return $value !== '' ? substr($value, 0, 80) : 'projekt';
}

function enterprise_project_backup_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function enterprise_project_backup_server(array $env): PDO
{
    foreach (['PROJECT_DB_HOST', 'PROJECT_DB_PORT', 'PROJECT_DB_USERNAME'] as $key) {
        if (trim((string)($env[$key] ?? '')) === '') {
            throw new RuntimeException("Projekt-DB-Konfiguration {$key} fehlt. Die Sicherung wurde nicht erstellt.");
        }
    }

    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';charset=utf8mb4',
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function enterprise_project_backup_database_exists(PDO $server, string $database): bool
{
    $stmt = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->execute([$database]);
    return $stmt->fetchColumn() !== false;
}

function enterprise_project_backup_database_pdo(array $env, string $database): PDO
{
    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';dbname=' . $database . ';charset=utf8mb4',
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function enterprise_project_backup_sql_value(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    $string = (string)$value;
    // Preserve binary/BLOB values without injecting raw NUL bytes into the dump.
    if (str_contains($string, "\0") || preg_match('//u', $string) !== 1) {
        return '0x' . bin2hex($string);
    }
    $quoted = $pdo->quote($string);
    if ($quoted === false) {
        throw new RuntimeException('Ein Datenbankwert konnte für das SQL-Backup nicht maskiert werden.');
    }
    return $quoted;
}

/** @return array{tables:int,views:int,rows:int,triggers:int} */
function enterprise_project_backup_dump_database(PDO $db, string $database, string $sqlFile): array
{
    $handle = fopen($sqlFile, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Die temporäre SQL-Sicherungsdatei konnte nicht angelegt werden.');
    }

    $stats = ['tables' => 0, 'views' => 0, 'rows' => 0, 'triggers' => 0];
    $tables = [];
    $views = [];

    try {
        fwrite($handle, "-- easyIT Enterprise project backup\n");
        fwrite($handle, '-- Database: ' . $database . "\n");
        fwrite($handle, '-- Generated: ' . gmdate('c') . "\n\n");
        $schemaMetaStmt = $db->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
        $schemaMetaStmt->execute([$database]);
        $schemaMeta = $schemaMetaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $charset = preg_match('/^[A-Za-z0-9_]+$/', (string)($schemaMeta['DEFAULT_CHARACTER_SET_NAME'] ?? '')) === 1
            ? (string)$schemaMeta['DEFAULT_CHARACTER_SET_NAME'] : 'utf8mb4';
        $collation = preg_match('/^[A-Za-z0-9_]+$/', (string)($schemaMeta['DEFAULT_COLLATION_NAME'] ?? '')) === 1
            ? (string)$schemaMeta['DEFAULT_COLLATION_NAME'] : 'utf8mb4_unicode_ci';

        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        fwrite($handle, 'CREATE DATABASE IF NOT EXISTS ' . enterprise_project_backup_quote_identifier($database) . ' CHARACTER SET ' . $charset . ' COLLATE ' . $collation . ";\n");
        fwrite($handle, 'USE ' . enterprise_project_backup_quote_identifier($database) . ";\n\n");

        $result = $db->query('SHOW FULL TABLES');
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $name = (string)($row[0] ?? '');
            $type = strtoupper((string)($row[1] ?? 'BASE TABLE'));
            if ($name === '') {
                continue;
            }
            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        foreach ($tables as $table) {
            $qid = enterprise_project_backup_quote_identifier($table);
            $create = $db->query('SHOW CREATE TABLE ' . $qid)->fetch(PDO::FETCH_NUM);
            $ddl = (string)($create[1] ?? '');
            if ($ddl === '') {
                throw new RuntimeException('Tabellendefinition für `' . $table . '` konnte nicht gelesen werden.');
            }
            fwrite($handle, "-- Table {$table}\nDROP TABLE IF EXISTS {$qid};\n{$ddl};\n\n");
            $stats['tables']++;
        }

        foreach ($tables as $table) {
            $qid = enterprise_project_backup_quote_identifier($table);
            $columnRows = $db->query('SHOW COLUMNS FROM ' . $qid)->fetchAll();
            $columns = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['Field'] ?? ''), $columnRows)));
            if ($columns === []) {
                continue;
            }
            $columnSql = implode(', ', array_map('enterprise_project_backup_quote_identifier', $columns));
            $rows = $db->query('SELECT * FROM ' . $qid);
            $batch = [];
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $values = [];
                foreach ($columns as $column) {
                    $values[] = enterprise_project_backup_sql_value($db, $row[$column] ?? null);
                }
                $batch[] = '(' . implode(', ', $values) . ')';
                $stats['rows']++;
                if (count($batch) >= 100) {
                    fwrite($handle, 'INSERT INTO ' . $qid . ' (' . $columnSql . ") VALUES\n" . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch !== []) {
                fwrite($handle, 'INSERT INTO ' . $qid . ' (' . $columnSql . ") VALUES\n" . implode(",\n", $batch) . ";\n");
            }
            fwrite($handle, "\n");
        }

        // Views are created after base tables/data so their dependencies exist.
        foreach ($views as $view) {
            $qid = enterprise_project_backup_quote_identifier($view);
            $create = $db->query('SHOW CREATE VIEW ' . $qid)->fetch(PDO::FETCH_ASSOC);
            $ddl = (string)($create['Create View'] ?? $create['Create view'] ?? '');
            if ($ddl === '') {
                continue;
            }
            fwrite($handle, "-- View {$view}\nDROP VIEW IF EXISTS {$qid};\n{$ddl};\n\n");
            $stats['views']++;
        }

        // Triggers are included as a separate DELIMITER block where available.
        try {
            $triggers = $db->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($triggers as $triggerRow) {
                $trigger = (string)($triggerRow['Trigger'] ?? '');
                if ($trigger === '') {
                    continue;
                }
                $create = $db->query('SHOW CREATE TRIGGER ' . enterprise_project_backup_quote_identifier($trigger))->fetch(PDO::FETCH_ASSOC);
                $ddl = (string)($create['SQL Original Statement'] ?? $create['Create Trigger'] ?? '');
                if ($ddl === '') {
                    continue;
                }
                fwrite($handle, "DELIMITER $$\nDROP TRIGGER IF EXISTS " . enterprise_project_backup_quote_identifier($trigger) . "$$\n{$ddl}$$\nDELIMITER ;\n\n");
                $stats['triggers']++;
            }
        } catch (Throwable) {
            // Some restricted DB users cannot inspect triggers. Tables and data remain restorable.
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
    } finally {
        fclose($handle);
    }

    return $stats;
}

/**
 * @return array{path:string,filename:string,sha256:string,size:int,token:string,expires_at:int,stats:array{tables:int,views:int,rows:int,triggers:int}}
 */


function enterprise_project_backup_pgsql_quote_identifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$identifier)!==1) {
        throw new RuntimeException('Ungültiger PostgreSQL-Bezeichner im Backup: '.$identifier);
    }
    return '"'.str_replace('"','""',$identifier).'"';
}

function enterprise_project_backup_pgsql_value(PDO $pdo, mixed $value, string $type=''): string
{
    if ($value===null) return 'NULL';
    if (is_resource($value)) $value=stream_get_contents($value);
    $type=strtolower($type);
    if ($type==='bytea') return "decode('".bin2hex((string)$value)."','hex')";
    if (is_bool($value)) return $value?'TRUE':'FALSE';
    $quoted=$pdo->quote((string)$value);
    if ($quoted===false) throw new RuntimeException('Ein PostgreSQL-Wert konnte für die Sicherung nicht maskiert werden.');
    return $quoted;
}

/** @return array{tables:int,views:int,rows:int,triggers:int,functions:int,indexes:int} */
function enterprise_project_backup_dump_mssql(PDO $pdo,string $database,string $target): array
{
    $fh=fopen($target,'wb');if(!$fh)throw new RuntimeException('MSSQL-Dumpdatei kann nicht geschrieben werden.');$tables=[];$rows=0;
    fwrite($fh,"-- easyIT MSSQL logical backup\nSET NOCOUNT ON;\nGO\n");
    $st=$pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
    foreach($st->fetchAll(PDO::FETCH_COLUMN) as $table){$table=(string)$table;$tables[]=$table;$q='['.str_replace(']',']]', $table).']';$cols=$pdo->query("SELECT COLUMN_NAME,DATA_TYPE,CHARACTER_MAXIMUM_LENGTH,IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME=".$pdo->quote($table)." ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);$defs=[];foreach($cols as $c){$name='['.str_replace(']',']]',(string)$c['COLUMN_NAME']).']';$type=strtoupper((string)$c['DATA_TYPE']);$len=(int)($c['CHARACTER_MAXIMUM_LENGTH']??0);if(in_array($type,['NVARCHAR','VARCHAR','VARBINARY'],true))$type.='('.($len<0?'MAX':max(1,$len)).')';$defs[]=$name.' '.$type.(((string)$c['IS_NULLABLE']==='NO')?' NOT NULL':' NULL');}fwrite($fh,"IF OBJECT_ID(N'dbo.".str_replace("'","''",$table)."',N'U') IS NULL CREATE TABLE dbo.$q (".implode(', ',$defs).");\nGO\n");$data=$pdo->query('SELECT * FROM dbo.'.$q)->fetchAll(PDO::FETCH_ASSOC);foreach($data as $row){$names=[];$vals=[];foreach($row as $k=>$v){$names[]='['.str_replace(']',']]',(string)$k).']';$vals[]=$v===null?'NULL':$pdo->quote((string)$v);}fwrite($fh,'INSERT INTO dbo.'.$q.' ('.implode(',',$names).') VALUES ('.implode(',',$vals).");\n");$rows++;}fwrite($fh,"GO\n");}
    fclose($fh);return ['tables'=>count($tables),'rows'=>$rows];
}

function enterprise_project_backup_dump_pgsql(PDO $db, string $database, string $sqlFile): array
{
    $handle=fopen($sqlFile,'wb');
    if($handle===false)throw new RuntimeException('Die temporäre PostgreSQL-Sicherungsdatei konnte nicht angelegt werden.');
    $stats=['tables'=>0,'views'=>0,'rows'=>0,'triggers'=>0,'functions'=>0,'indexes'=>0];
    try{
        fwrite($handle,"-- easyIT Enterprise PostgreSQL project backup\n");
        fwrite($handle,'-- Database: '.$database."\n-- Generated: ".gmdate('c')."\n\n");
        fwrite($handle,"SET client_encoding TO 'UTF8';\nBEGIN;\n\n");

        $tables=$db->query("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND c.relkind='r' ORDER BY c.relname")->fetchAll(PDO::FETCH_COLUMN);
        $columnTypes=[];
        $serialColumns=[];
        $identityAlways=[];
        foreach($tables as $tableRaw){
            $table=(string)$tableRaw;$qt=enterprise_project_backup_pgsql_quote_identifier($table);
            $st=$db->prepare("SELECT a.attname AS column_name,format_type(a.atttypid,a.atttypmod) AS formatted_type,t.typname AS udt_name,a.attnotnull,a.attidentity,pg_get_expr(ad.adbin,ad.adrelid) AS column_default FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid JOIN pg_namespace n ON n.oid=c.relnamespace JOIN pg_type t ON t.oid=a.atttypid LEFT JOIN pg_attrdef ad ON ad.adrelid=a.attrelid AND ad.adnum=a.attnum WHERE n.nspname=current_schema() AND c.relname=? AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum");
            $st->execute([$table]);$cols=$st->fetchAll(PDO::FETCH_ASSOC);
            if($cols===[])continue;
            $defs=[];$columnTypes[$table]=[];$serialColumns[$table]=[];$identityAlways[$table]=false;
            foreach($cols as $col){
                $name=(string)$col['column_name'];$type=(string)$col['formatted_type'];$udt=(string)$col['udt_name'];$default=(string)($col['column_default']??'');$identity=(string)($col['attidentity']??'');
                $columnTypes[$table][$name]=$udt;
                $def=enterprise_project_backup_pgsql_quote_identifier($name).' '.$type;
                if($identity==='a'){$def.=' GENERATED ALWAYS AS IDENTITY';$identityAlways[$table]=true;$serialColumns[$table][]=$name;}
                elseif($identity==='d'){$def.=' GENERATED BY DEFAULT AS IDENTITY';$serialColumns[$table][]=$name;}
                elseif($default!=='' && preg_match('/^nextval\(/i',$default)){
                    if(in_array($udt,['int8','bigint'],true))$def=enterprise_project_backup_pgsql_quote_identifier($name).' BIGSERIAL';
                    elseif(in_array($udt,['int4','integer'],true))$def=enterprise_project_backup_pgsql_quote_identifier($name).' SERIAL';
                    elseif(in_array($udt,['int2','smallint'],true))$def=enterprise_project_backup_pgsql_quote_identifier($name).' SMALLSERIAL';
                    else $def.=' DEFAULT '.$default;
                    $serialColumns[$table][]=$name;
                } elseif($default!=='') $def.=' DEFAULT '.$default;
                if((bool)$col['attnotnull'])$def.=' NOT NULL';
                $defs[]=$def;
            }
            fwrite($handle,"-- Table {$table}\nDROP TABLE IF EXISTS {$qt} CASCADE;\nCREATE TABLE {$qt} (\n  ".implode(",\n  ",$defs)."\n);\n\n");
            $stats['tables']++;
        }

        foreach($tables as $tableRaw){
            $table=(string)$tableRaw;if(!isset($columnTypes[$table]))continue;$qt=enterprise_project_backup_pgsql_quote_identifier($table);
            $cols=array_keys($columnTypes[$table]);$colSql=implode(', ',array_map('enterprise_project_backup_pgsql_quote_identifier',$cols));
            $rows=$db->query('SELECT * FROM '.$qt);
            $batch=[];
            while($row=$rows->fetch(PDO::FETCH_ASSOC)){
                $vals=[];foreach($cols as $c)$vals[]=enterprise_project_backup_pgsql_value($db,$row[$c]??null,$columnTypes[$table][$c]??'');
                $batch[]='('.implode(', ',$vals).')';$stats['rows']++;
                if(count($batch)>=100){fwrite($handle,'INSERT INTO '.$qt.' ('.$colSql.')'.(!empty($identityAlways[$table])?' OVERRIDING SYSTEM VALUE':'')." VALUES\n".implode(",\n",$batch).";\n");$batch=[];}
            }
            if($batch!==[])fwrite($handle,'INSERT INTO '.$qt.' ('.$colSql.')'.(!empty($identityAlways[$table])?' OVERRIDING SYSTEM VALUE':'')." VALUES\n".implode(",\n",$batch).";\n");
            foreach($serialColumns[$table]??[] as $column){
                $qc=enterprise_project_backup_pgsql_quote_identifier($column);
                $tblLit=$db->quote($table);$colLit=$db->quote($column);
                fwrite($handle,"SELECT setval(pg_get_serial_sequence({$tblLit},{$colLit}), COALESCE((SELECT MAX({$qc}) FROM {$qt}),1), EXISTS(SELECT 1 FROM {$qt}));\n");
            }
            fwrite($handle,"\n");
        }

        // Add all table constraints after data, so foreign-key dependency order is irrelevant.
        $constraints=$db->query("SELECT c.relname AS table_name,con.conname,pg_get_constraintdef(con.oid,true) AS definition FROM pg_constraint con JOIN pg_class c ON c.oid=con.conrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND con.contype IN ('p','u','f','c','x') ORDER BY c.relname,con.contype,con.conname")->fetchAll(PDO::FETCH_ASSOC);
        foreach($constraints as $con){
            $qt=enterprise_project_backup_pgsql_quote_identifier((string)$con['table_name']);$qn=enterprise_project_backup_pgsql_quote_identifier((string)$con['conname']);
            fwrite($handle,'ALTER TABLE '.$qt.' ADD CONSTRAINT '.$qn.' '.(string)$con['definition'].";\n");
        }
        fwrite($handle,"\n");

        // Non-constraint indexes.
        $indexes=$db->query("SELECT pg_get_indexdef(i.indexrelid) AS definition FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid JOIN pg_namespace n ON n.oid=t.relnamespace LEFT JOIN pg_constraint con ON con.conindid=i.indexrelid WHERE n.nspname=current_schema() AND con.oid IS NULL ORDER BY t.relname,i.indexrelid")->fetchAll(PDO::FETCH_COLUMN);
        foreach($indexes as $index){$index=trim((string)$index);if($index==='')continue;fwrite($handle,$index.";\n");$stats['indexes']++;}
        fwrite($handle,"\n");

        // Functions before triggers because trigger DDL references them.
        $functions=$db->query("SELECT pg_get_functiondef(p.oid) FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname=current_schema() ORDER BY p.proname,p.oid")->fetchAll(PDO::FETCH_COLUMN);
        foreach($functions as $fn){$fn=trim((string)$fn);if($fn==='')continue;fwrite($handle,$fn."\n\n");$stats['functions']++;}

        $triggers=$db->query("SELECT pg_get_triggerdef(tg.oid,true) FROM pg_trigger tg JOIN pg_class c ON c.oid=tg.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND NOT tg.tgisinternal ORDER BY c.relname,tg.tgname")->fetchAll(PDO::FETCH_COLUMN);
        foreach($triggers as $tr){$tr=trim((string)$tr);if($tr==='')continue;fwrite($handle,$tr.";\n");$stats['triggers']++;}
        fwrite($handle,"\n");

        $views=$db->query("SELECT viewname,definition FROM pg_views WHERE schemaname=current_schema() ORDER BY viewname")->fetchAll(PDO::FETCH_ASSOC);
        foreach($views as $v){$name=(string)$v['viewname'];$def=trim((string)$v['definition']);if($name===''||$def==='')continue;$qv=enterprise_project_backup_pgsql_quote_identifier($name);fwrite($handle,"CREATE OR REPLACE VIEW {$qv} AS {$def};\n\n");$stats['views']++;}
        fwrite($handle,"COMMIT;\n");
    }finally{fclose($handle);}
    return $stats;
}

function enterprise_project_backup_csv_stats(string $path): array
{
    $stats=['tables'=>0,'views'=>0,'rows'=>0,'triggers'=>0,'files'=>0];
    foreach(glob(rtrim($path,'/\\').'/*.csv')?:[] as $file){
        if(!is_file($file))continue;$stats['tables']++;$stats['files']++;
        $lines=file($file,FILE_IGNORE_NEW_LINES);if(is_array($lines)&&count($lines)>0)$stats['rows']+=max(0,count($lines)-1);
    }
    foreach(glob(rtrim($path,'/\\').'/*')?:[] as $file)if(is_file($file)&&!str_ends_with(strtolower($file),'.csv'))$stats['files']++;
    return $stats;
}


/** @return array{tables:int,views:int,rows:int,triggers:int,files:int} */

function enterprise_project_backup_oracle_quote_identifier(string $identifier): string
{
    if(preg_match('/^[A-Za-z][A-Za-z0-9_$#]{0,127}$/',$identifier)!==1)throw new RuntimeException('Ungültiger Oracle-Bezeichner: '.$identifier);
    return '"'.str_replace('"','""',$identifier).'"';
}
function enterprise_project_backup_oracle_value(PDO $pdo,mixed $value): string
{
    if($value===null)return 'NULL';
    if(is_int($value)||is_float($value))return (string)$value;
    if(is_resource($value))$value=stream_get_contents($value);
    $v=(string)$value;
    if(strlen($v)>3000)return "TO_CLOB(".$pdo->quote(substr($v,0,3000)).")";
    return $pdo->quote($v);
}
function enterprise_project_backup_dump_oracle(PDO $db,string $database,string $sqlFile): array
{
    $h=fopen($sqlFile,'wb');if(!$h)throw new RuntimeException('Oracle-Backupdatei konnte nicht erzeugt werden.');
    $stats=['tables'=>0,'rows'=>0,'views'=>0,'sequences'=>0,'triggers'=>0,'indexes'=>0];
    try{
        fwrite($h,"-- easyIT Oracle XE logical project backup\n-- Schema: ".(string)$db->query('SELECT USER FROM dual')->fetchColumn()."\n\n");
        try{$db->exec("BEGIN DBMS_METADATA.SET_TRANSFORM_PARAM(DBMS_METADATA.SESSION_TRANSFORM,'SQLTERMINATOR',TRUE); END;");}catch(Throwable){}
        $tables=$db->query('SELECT table_name FROM user_tables ORDER BY table_name')->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $table){$table=(string)$table;$qt=enterprise_project_backup_oracle_quote_identifier($table);
            $st=$db->prepare("SELECT DBMS_METADATA.GET_DDL('TABLE',?) FROM dual");$st->execute([$table]);$ddl=$st->fetchColumn();if(is_resource($ddl))$ddl=stream_get_contents($ddl);$ddl=trim((string)$ddl);if($ddl!=='')fwrite($h,$ddl.(str_ends_with($ddl,';')?'':';')."\n\n");$stats['tables']++;
            $rows=$db->query('SELECT * FROM '.$qt);$cols=[];for($i=0;$i<$rows->columnCount();$i++){$m=$rows->getColumnMeta($i);$cols[]=(string)($m['name']??'');}
            while($row=$rows->fetch(PDO::FETCH_ASSOC)){$names=array_keys($row);$vals=[];foreach($names as $c)$vals[]=enterprise_project_backup_oracle_value($db,$row[$c]);fwrite($h,'INSERT INTO '.$qt.' ('.implode(',',array_map('enterprise_project_backup_oracle_quote_identifier',$names)).') VALUES ('.implode(',',$vals).');' ."\n");$stats['rows']++;}
            fwrite($h,"\n");
        }
        foreach(['SEQUENCE'=>'sequences','VIEW'=>'views','INDEX'=>'indexes'] as $type=>$key){
            $st=$db->prepare('SELECT object_name FROM user_objects WHERE object_type=? ORDER BY object_name');$st->execute([$type]);foreach($st->fetchAll(PDO::FETCH_COLUMN) as $name){$name=(string)$name;if($type==='INDEX'&&str_starts_with($name,'SYS_'))continue;try{$q=$db->prepare("SELECT DBMS_METADATA.GET_DDL(?,?) FROM dual");$q->execute([$type,$name]);$ddl=$q->fetchColumn();if(is_resource($ddl))$ddl=stream_get_contents($ddl);$ddl=trim((string)$ddl);if($ddl!=='')fwrite($h,"\n".$ddl.(str_ends_with($ddl,';')?'':';')."\n");$stats[$key]++;}catch(Throwable){}}
        }
        fwrite($h,"\nCOMMIT;\n");
    }finally{fclose($h);}
    return $stats;
}

function enterprise_project_backup_sqlite_stats(PDO $pdo): array
{
    $stats=['tables'=>0,'views'=>0,'rows'=>0,'triggers'=>0,'files'=>1];
    $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $stats['tables']=count($tables);
    foreach($tables as $table){
        $table=(string)$table;
        if(preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$table)!==1)continue;
        try{$stats['rows']+=(int)$pdo->query('SELECT COUNT(*) FROM '.enterprise_project_backup_quote_identifier($table))->fetchColumn();}catch(Throwable){}
    }
    $stats['views']=(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='view'")->fetchColumn();
    $stats['triggers']=(int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='trigger'")->fetchColumn();
    return $stats;
}

function enterprise_project_backup_zip_directory(ZipArchive $zip,string $source,string $prefix): int
{
    $sourceReal=realpath($source);if($sourceReal===false||!is_dir($sourceReal))throw new RuntimeException('CSV-Projektspeicher wurde für die Sicherung nicht gefunden.');
    $count=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceReal,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){
        $rel=str_replace('\\','/',substr($item->getPathname(),strlen($sourceReal)+1));if($rel==='')continue;$target=rtrim($prefix,'/').'/'.$rel;
        if($item->isDir()){$zip->addEmptyDir($target);continue;}
        if($item->isFile()){if(!$zip->addFile($item->getPathname(),$target))throw new RuntimeException('CSV-Projektdatei konnte nicht gesichert werden: '.$rel);$count++;}
    }
    return $count;
}

/**
 * @return array{path:string,filename:string,sha256:string,size:int,token:string,expires_at:int,stats:array<string,int>}
 */
function enterprise_project_backup_create(array $env, array $project, int $userId): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Für die Projektsicherung wird die PHP-Erweiterung ext-zip / ZipArchive benötigt. Es wurde nichts gelöscht.');
    $database=trim((string)($project['database_name']??''));
    if(!enterprise_project_store_valid_name($database))throw new RuntimeException('Der hinterlegte Projektspeichername ist ungültig. Die Sicherung wurde nicht erstellt.');
    $driver=enterprise_project_store_driver($env,$project);
    if(!in_array($driver,['mysql','csv','sqlite','pgsql','oracle','mssql'],true))throw new RuntimeException('Die automatische Projektsicherung ist für diesen Treiber noch nicht freigegeben: '.$driver);
    if(!enterprise_project_store_exists_for_project($env,$project))throw new RuntimeException('Der Projektdatenspeicher `'.$database.'` wurde nicht gefunden. Eine vollständige Sicherung kann deshalb nicht erstellt werden; es wurde nichts gelöscht.');

    enterprise_project_backup_cleanup();$root=enterprise_project_backup_project_root($project);
    $tmpDir=$root.'/tmp-'.bin2hex(random_bytes(10));if(!mkdir($tmpDir,0770,true)&&!is_dir($tmpDir))throw new RuntimeException('Das temporäre Sicherungsverzeichnis konnte nicht angelegt werden.');
    $timestamp=gmdate('Ymd_His');$baseName=enterprise_project_backup_slug((string)($project['name']??'projekt')).'-backup-'.$timestamp;$filename=$baseName.'.zip';$path=$root.'/'.$filename;
    if(is_file($path)){$filename=$baseName.'-'.bin2hex(random_bytes(3)).'.zip';$path=$root.'/'.$filename;}

    try{
        $payloadFiles=['project.json','README_RESTORE.txt'];
        if($driver==='mysql'){
            $db=enterprise_project_backup_database_pdo($env,$database);$sqlFile=$tmpDir.'/database.sql';$snapshotStarted=false;
            try{$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');$snapshotStarted=true;$stats=enterprise_project_backup_dump_database($db,$database,$sqlFile);$db->exec('COMMIT');$snapshotStarted=false;}
            catch(Throwable $dumpError){if($snapshotStarted)try{$db->exec('ROLLBACK');}catch(Throwable){}throw $dumpError;}
            $payloadFiles[]='database.sql';$dataFormat='mysql-sql';
        }elseif($driver==='pgsql'){
            $db=enterprise_project_store_pdo($env,$database,'pgsql');$sqlFile=$tmpDir.'/postgresql.sql';
            $stats=enterprise_project_backup_dump_pgsql($db,$database,$sqlFile);$dataFormat='postgresql-sql';
        }elseif($driver==='oracle'){
            $db=enterprise_project_store_pdo($env,$database,'oracle');$sqlFile=$tmpDir.'/oracle.sql';
            $stats=enterprise_project_backup_dump_oracle($db,$database,$sqlFile);$dataFormat='oracle-sql';
        }elseif($driver==='mssql'){
            $db=enterprise_project_store_pdo($env,$database,'mssql');$sqlFile=$tmpDir.'/mssql.sql';
            $stats=enterprise_project_backup_dump_mssql($db,$database,$sqlFile);$dataFormat='mssql-sql';
            $payloadFiles[]='mssql-data/database.sql';
        }elseif($driver==='csv'){
            $csvPath=enterprise_project_store_csv_database_path_for_project($env,$project,true);$stats=enterprise_project_backup_csv_stats($csvPath);$dataFormat='csv-directory';
        }else{
            $sqlitePdo=enterprise_project_store_for_project($env,$project);
            try{$sqlitePdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');}catch(Throwable){}
            $sqlitePath=enterprise_project_store_sqlite_database_path_for_project($env,$project,true);
            if(!is_file($sqlitePath)||(int)filesize($sqlitePath)<=0)throw new RuntimeException('Die SQLite-Projektdatei wurde nicht gefunden oder ist leer.');
            $stats=enterprise_project_backup_sqlite_stats($sqlitePdo);$dataFormat='sqlite-file';
            $payloadFiles[]='sqlite-data/database.sqlite';
        }

        $projectMeta=$project;$projectMeta['backup_created_at']=gmdate('c');$projectMeta['backup_created_by_user_id']=$userId;
        file_put_contents($tmpDir.'/project.json',json_encode($projectMeta,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $manifest=[
            'format'=>'easyit-project-backup','format_version'=>5,'created_at'=>gmdate('c'),'project'=>[
                'id'=>(int)($project['id']??0),'name'=>(string)($project['name']??''),'slug'=>(string)($project['slug']??''),'storage_key'=>(string)($project['storage_key']??''),'product_type'=>(string)($project['product_type']??''),
                'database_driver'=>$driver,'database_name'=>$database,'database_schema'=>$driver==='pgsql'?enterprise_project_store_pgsql_schema($env):'',
            ],'data_format'=>$dataFormat,'database'=>$stats,'files'=>array_merge($payloadFiles,['manifest.json'], $driver==='csv'?['csv-data/']:($driver==='sqlite'?['sqlite-data/database.sqlite']:($driver==='pgsql'?['postgresql-data/database.sql']:($driver==='oracle'?['oracle-data/database.sql']:($driver==='mssql'?['mssql-data/database.sql']:[]))))),
        ];
        $documentsPath=enterprise_project_storage_path($project,'documents');
        $documentFiles=0;
        if(is_dir($documentsPath)){
            $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($documentsPath,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
            foreach($it as $item)if($item instanceof SplFileInfo && $item->isFile() && !$item->isLink())$documentFiles++;
        }
        $manifest['documents']=['included'=>true,'files'=>$documentFiles,'archive_prefix'=>'documents/'];
        $manifest['files'][]='documents/';

        $readme="easyIT Enterprise – Projektsicherung\n======================================\n\nProjekt: ".(string)($project['name']??'')."\nTreiber: ".strtoupper($driver)."\nSpeicher: ".$database."\nErstellt: ".gmdate('c')."\n\n";
        $readme.=$driver==='csv'
            ? "Der Ordner csv-data/ enthält den vollständigen physischen CSV-Projektdatenspeicher mit allen Tabellen und Datensätzen.\n"
            : ($driver==='sqlite'
                ? "sqlite-data/database.sqlite enthält die vollständige native SQLite-Projektdatenbank.\n"
                : ($driver==='pgsql'
                    ? "postgresql-data/database.sql enthält das PostgreSQL-Schema, die Datensätze, Sequenzen, Constraints, Indizes, Funktionen, Trigger und Views.\n"
                    : ($driver==='oracle'
                        ? "oracle-data/database.sql enthält das Oracle-XE-Schema, Datensätze und Datenbankobjekte.\n"
                        : ($driver==='mssql' ? "mssql-data/database.sql enthält das Microsoft-SQL-Server-Schema und alle Datensätze.\n" : "database.sql enthält das physische Projektdatenbankschema und alle Datensätze.\n"))));
        $readme.="project.json enthält die Enterprise-Projektmetadaten.\ndocuments/ enthält die projektbezogenen Dokumente und Moduldateien.\nmanifest.json beschreibt Format, Treiber und Statistik.\nWiederherstellung: easyIT Enterprise → Projekte → Projektsicherung wiederherstellen.\n";
        file_put_contents($tmpDir.'/README_RESTORE.txt',$readme);file_put_contents($tmpDir.'/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Das ZIP-Sicherungsarchiv konnte nicht erzeugt werden.');
        try{
            foreach(['manifest.json','project.json','README_RESTORE.txt'] as $file)if(!$zip->addFile($tmpDir.'/'.$file,$file))throw new RuntimeException('Datei konnte nicht in Sicherung aufgenommen werden: '.$file);
            if($driver==='mysql'){
                if(!$zip->addFile($tmpDir.'/database.sql','database.sql'))throw new RuntimeException('database.sql konnte nicht gesichert werden.');
            }elseif($driver==='pgsql'){
                if(!$zip->addFile($tmpDir.'/postgresql.sql','postgresql-data/database.sql'))throw new RuntimeException('PostgreSQL database.sql konnte nicht gesichert werden.');
            }elseif($driver==='oracle'){
                if(!$zip->addFile($tmpDir.'/oracle.sql','oracle-data/database.sql'))throw new RuntimeException('Oracle database.sql konnte nicht gesichert werden.');
            }elseif($driver==='mssql'){
                if(!$zip->addFile($tmpDir.'/mssql.sql','mssql-data/database.sql'))throw new RuntimeException('MSSQL database.sql konnte nicht gesichert werden.');
            }elseif($driver==='csv'){
                enterprise_project_backup_zip_directory($zip,enterprise_project_store_csv_database_path_for_project($env,$project,true),'csv-data');
            }else{
                $sqlitePath=enterprise_project_store_sqlite_database_path_for_project($env,$project,true);
                if(!$zip->addFile($sqlitePath,'sqlite-data/database.sqlite'))throw new RuntimeException('SQLite-Projektdatei konnte nicht gesichert werden.');
            }
            if(is_dir($documentsPath))enterprise_project_backup_zip_directory($zip,$documentsPath,'documents');
        }finally{if(!$zip->close())throw new RuntimeException('Das ZIP-Sicherungsarchiv konnte nicht abgeschlossen werden.');}
        if(!is_file($path)||(int)filesize($path)<=0)throw new RuntimeException('Das erzeugte Sicherungsarchiv ist leer oder nicht vorhanden.');
        $sha256=hash_file('sha256',$path);if(!is_string($sha256)||strlen($sha256)!==64)throw new RuntimeException('Die Integritätsprüfung des Sicherungsarchivs ist fehlgeschlagen.');
        $token=bin2hex(random_bytes(24));$expiresAt=time()+86400;$_SESSION['project_backup_downloads'][$token]=['path'=>$path,'filename'=>$filename,'project_id'=>(int)($project['id']??0),'created_at'=>time(),'expires_at'=>$expiresAt,'sha256'=>$sha256];
        return ['path'=>$path,'filename'=>$filename,'sha256'=>$sha256,'size'=>(int)filesize($path),'token'=>$token,'expires_at'=>$expiresAt,'stats'=>$stats];
    }catch(Throwable $e){if(is_file($path))@unlink($path);throw $e;}
    finally{
        if(is_dir($tmpDir)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item){if($item->isDir())@rmdir($item->getPathname());else @unlink($item->getPathname());}@rmdir($tmpDir);}
    }
}

/** @return array{path:string,filename:string,project_id:int,created_at:int,expires_at:int,sha256:string} */
function enterprise_project_backup_resolve_download(string $token): array
{
    if ($token === '' || preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
        throw new RuntimeException('Ungültiger Sicherungslink.');
    }
    $entry = $_SESSION['project_backup_downloads'][$token] ?? null;
    if (!is_array($entry)) {
        throw new RuntimeException('Der Sicherungslink ist nicht mehr verfügbar.');
    }
    if ((int)($entry['expires_at'] ?? 0) < time()) {
        unset($_SESSION['project_backup_downloads'][$token]);
        throw new RuntimeException('Der Sicherungslink ist abgelaufen.');
    }

    $root = realpath(enterprise_project_backup_ensure_root());
    $path = realpath((string)($entry['path'] ?? ''));
    if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
        unset($_SESSION['project_backup_downloads'][$token]);
        throw new RuntimeException('Die Sicherungsdatei wurde nicht gefunden.');
    }

    return [
        'path' => $path,
        'filename' => basename((string)($entry['filename'] ?? basename($path))),
        'project_id' => (int)($entry['project_id'] ?? 0),
        'created_at' => (int)($entry['created_at'] ?? 0),
        'expires_at' => (int)($entry['expires_at'] ?? 0),
        'sha256' => (string)($entry['sha256'] ?? ''),
    ];
}

// STAND 4 MSSQL backup archive contract: mssql-data/database.sql / data_format=mssql-sql
