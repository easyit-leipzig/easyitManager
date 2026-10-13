<?php
declare(strict_types=1);

require_once __DIR__ . '/EnterpriseCsvPdo.php';
require_once __DIR__ . '/EnterpriseSqlitePdo.php';
require_once __DIR__ . '/EnterprisePgsqlPdo.php';
require_once __DIR__ . '/EnterpriseOraclePdo.php';
require_once __DIR__ . '/EnterpriseMssqlPdo.php';
require_once __DIR__ . '/project_storage.php';

function enterprise_project_store_driver(array $env, ?array $project = null): string
{
    $driver = strtolower(trim((string)($project['database_driver'] ?? $env['PROJECT_DB_DRIVER'] ?? 'mysql')));
    if (in_array($driver,['postgres','postgresql'],true)) $driver='pgsql';
    return in_array($driver, ['csv','sqlite','mysql','mariadb','pgsql','oracle','mssql'], true) ? ($driver === 'mariadb' ? 'mysql' : $driver) : $driver;
}

function enterprise_project_store_valid_name(string $name): bool
{
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/', $name) === 1;
}

function enterprise_project_store_valid_pgsql_schema(string $name): bool
{
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $name) === 1;
}

function enterprise_project_store_csv_base(array $env): string
{
    $root = dirname(__DIR__, 2);
    $setting = trim((string)($env['PROJECT_DB_CSV_BASE_PATH'] ?? 'storage/project-csv'));
    if ($setting === '') $setting = 'storage/project-csv';
    $normalized = str_replace('\\', '/', $setting);
    if (preg_match('~^(?:[A-Za-z]:/|/)~', $normalized)) return rtrim($normalized, '/');
    return rtrim(str_replace('\\','/',$root), '/') . '/' . ltrim($normalized, '/');
}

function enterprise_project_store_csv_database_path(array $env, string $database): string
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger CSV-Projektspeichername.');
    return enterprise_project_store_csv_base($env) . '/' . $database;
}

function enterprise_project_store_sqlite_base(array $env): string
{
    $root = dirname(__DIR__, 2);
    $setting = trim((string)($env['PROJECT_DB_SQLITE_BASE_PATH'] ?? 'storage/project-sqlite'));
    if ($setting === '') $setting = 'storage/project-sqlite';
    $normalized = str_replace('\\', '/', $setting);
    if (preg_match('~^(?:[A-Za-z]:/|/)~', $normalized)) return rtrim($normalized, '/');
    return rtrim(str_replace('\\','/',$root), '/') . '/' . ltrim($normalized, '/');
}

function enterprise_project_store_sqlite_database_path(array $env, string $database): string
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger SQLite-Projektspeichername.');
    return enterprise_project_store_sqlite_base($env) . '/' . $database . '.sqlite';
}



/** HF88 Storage Phase 2: project-aware CSV/SQLite paths with legacy fallback. */
function enterprise_project_store_csv_database_path_for_project(array $env, array $project, bool $preferLegacy = true): string
{
    $new = enterprise_project_storage_csv_path($project);
    $database = trim((string)($project['database_name'] ?? ''));
    $legacy = enterprise_project_store_csv_database_path($env, $database);
    if ($preferLegacy && !enterprise_project_store_csv_path_has_data($new) && enterprise_project_store_csv_path_has_data($legacy)) return $legacy;
    return $new;
}

function enterprise_project_store_csv_path_has_data(string $path): bool
{
    return is_dir($path) && (is_file($path.'/dataforms.csv') || is_file($path.'/migrations.csv') || is_file($path.'/events.csv') || is_file($path.'/_relations.json'));
}

function enterprise_project_store_sqlite_database_path_for_project(array $env, array $project, bool $preferLegacy = true): string
{
    $new = enterprise_project_storage_sqlite_file($project);
    $database = trim((string)($project['database_name'] ?? ''));
    $legacy = enterprise_project_store_sqlite_database_path($env, $database);
    $newMissing = !is_file($new) || (int)@filesize($new) === 0;
    $legacyExists = is_file($legacy) && (int)@filesize($legacy) > 0;
    if ($preferLegacy && $newMissing && $legacyExists) return $legacy;
    return $new;
}

function enterprise_project_store_pdo_for_project_path(array $env, array $project, bool $preferLegacy = true): PDO
{
    $driver = enterprise_project_store_driver($env, $project);
    $database = trim((string)($project['database_name'] ?? ''));
    if ($database === '') throw new RuntimeException('Der Projektdatenspeichername fehlt.');
    if ($driver === 'csv') {
        $path = enterprise_project_store_csv_database_path_for_project($env, $project, $preferLegacy);
        enterprise_project_storage_mkdir(dirname($path));
        return new EnterpriseCsvPdo(dirname($path), basename($path));
    }
    if ($driver === 'sqlite') {
        $path = enterprise_project_store_sqlite_database_path_for_project($env, $project, $preferLegacy);
        enterprise_project_storage_mkdir(dirname($path));
        return new EnterpriseSqlitePdo($path);
    }
    return enterprise_project_store_pdo($env, $database, $driver);
}

function enterprise_project_store_exists_for_project(array $env, array $project): bool
{
    $driver = enterprise_project_store_driver($env, $project);
    if ($driver === 'csv') return enterprise_project_store_csv_path_has_data(enterprise_project_store_csv_database_path_for_project($env, $project, true));
    if ($driver === 'sqlite') {
        $path = enterprise_project_store_sqlite_database_path_for_project($env, $project, true);
        if (!is_file($path) || (int)@filesize($path) === 0) return false;
        try { $pdo=new EnterpriseSqlitePdo($path); return (string)$pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok'; } catch (Throwable) { return false; }
    }
    return enterprise_project_store_exists($env, (string)($project['database_name'] ?? ''), $driver);
}

function enterprise_project_store_create_for_project(array $env, array $project): PDO
{
    enterprise_project_storage_provision($project, $env);
    $driver = enterprise_project_store_driver($env, $project);
    if ($driver === 'csv' || $driver === 'sqlite') return enterprise_project_store_pdo_for_project_path($env, $project, false);
    return enterprise_project_store_create($env, (string)$project['database_name'], $driver);
}

function enterprise_project_store_delete_for_project(array $env, array $project): bool
{
    $driver = enterprise_project_store_driver($env, $project);
    if ($driver === 'csv') {
        $path = enterprise_project_store_csv_database_path_for_project($env, $project, true);
        if (!is_dir($path)) return false;
        enterprise_project_store_remove_tree($path);
        enterprise_project_storage_mkdir(enterprise_project_storage_csv_path($project));
        return true;
    }
    if ($driver === 'sqlite') {
        $path = enterprise_project_store_sqlite_database_path_for_project($env, $project, true);
        if (!is_file($path)) return false;
        foreach ([$path.'-wal',$path.'-shm',$path] as $file) if (is_file($file) && !@unlink($file)) throw new RuntimeException('SQLite-Datei konnte nicht gelöscht werden: '.$file);
        return true;
    }
    return enterprise_project_store_delete($env, (string)($project['database_name'] ?? ''), $driver);
}

function enterprise_project_store_ensure_default_csv_source_for_project(PDO $pdo, array $env, array $project): int
{
    $path=enterprise_project_store_csv_database_path_for_project($env,$project,false);
    $name='Projekt-CSV-Datenspeicher';
    $config=json_encode(['base_path'=>dirname($path),'database'=>basename($path)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? LIMIT 1');$st->execute([$name]);$id=(int)($st->fetchColumn()?:0);
    if($id>0){$up=$pdo->prepare("UPDATE data_sources SET driver='csv',config_json=?,is_enabled=1 WHERE id=?");$up->execute([$config,$id]);return $id;}
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner CSV-Datenspeicher')");$ins->execute([$name,'csv',$config]);return (int)$pdo->lastInsertId();
}

function enterprise_project_store_ensure_default_sqlite_source_for_project(PDO $pdo, array $env, array $project): int
{
    $path=enterprise_project_store_sqlite_database_path_for_project($env,$project,false);
    $name='Projekt-SQLite-Datenspeicher';
    $config=json_encode(['path'=>$path], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? LIMIT 1');$st->execute([$name]);$id=(int)($st->fetchColumn()?:0);
    if($id>0){$up=$pdo->prepare("UPDATE data_sources SET driver='sqlite',config_json=?,is_enabled=1 WHERE id=?");$up->execute([$config,$id]);return $id;}
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner SQLite-Datenspeicher')");$ins->execute([$name,'sqlite',$config]);return (int)$pdo->lastInsertId();
}

function enterprise_project_store_pgsql_schema(array $env): string
{
    $schema=trim((string)($env['PROJECT_DB_SCHEMA']??'public')) ?: 'public';
    if (!enterprise_project_store_valid_pgsql_schema($schema)) throw new RuntimeException('Ungültiger PostgreSQL-Projektschemaname.');
    return $schema;
}

function enterprise_project_store_pgsql_server(array $env, ?string $maintenanceDatabase = null): PDO
{
    foreach (['PROJECT_DB_HOST','PROJECT_DB_PORT','PROJECT_DB_USERNAME'] as $key) {
        if (trim((string)($env[$key] ?? '')) === '') throw new RuntimeException("Projekt-PostgreSQL-Konfiguration {$key} fehlt.");
    }
    if (!extension_loaded('pdo_pgsql')) throw new RuntimeException('Die PHP-Erweiterung pdo_pgsql ist nicht aktiv.');
    $maintenanceDatabase=trim((string)($maintenanceDatabase ?? $env['PROJECT_DB_MAINTENANCE_DATABASE'] ?? 'postgres')) ?: 'postgres';
    return new EnterprisePgsqlPdo(
        (string)$env['PROJECT_DB_HOST'],
        (int)$env['PROJECT_DB_PORT'],
        $maintenanceDatabase,
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        'public'
    );
}

function enterprise_project_store_pgsql_pdo(array $env, string $database): PDO
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger PostgreSQL-Projektdatenbankname.');
    return new EnterprisePgsqlPdo(
        (string)($env['PROJECT_DB_HOST'] ?? '127.0.0.1'),
        (int)($env['PROJECT_DB_PORT'] ?? 5432),
        $database,
        (string)($env['PROJECT_DB_USERNAME'] ?? ''),
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        enterprise_project_store_pgsql_schema($env)
    );
}


function enterprise_project_store_oracle_pdo(array $env, string $database): PDO
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger Oracle-Projektspeichername.');
    $service=trim((string)($env['PROJECT_DB_ORACLE_SERVICE'] ?? 'XEPDB1')) ?: 'XEPDB1';
    return new EnterpriseOraclePdo((string)($env['PROJECT_DB_HOST']??'127.0.0.1'),(int)($env['PROJECT_DB_PORT']??1521),$service,(string)($env['PROJECT_DB_USERNAME']??''),(string)($env['PROJECT_DB_PASSWORD']??''));
}

function enterprise_project_store_mssql_pdo(array $env,string $database): PDO
{
    if(!enterprise_project_store_valid_name($database))throw new RuntimeException('Ungültiger MSSQL-Projektdatenbankname.');
    return new EnterpriseMssqlPdo((string)($env['PROJECT_DB_HOST']??'127.0.0.1'),(int)($env['PROJECT_DB_PORT']??1433),$database,(string)($env['PROJECT_DB_USERNAME']??''),(string)($env['PROJECT_DB_PASSWORD']??''),(string)($env['PROJECT_DB_ENCRYPT']??'1')!=='0',(string)($env['PROJECT_DB_TRUST_SERVER_CERTIFICATE']??'0')==='1');
}
function enterprise_project_store_mssql_server(array $env): PDO
{
    return new EnterpriseMssqlPdo((string)($env['PROJECT_DB_HOST']??'127.0.0.1'),(int)($env['PROJECT_DB_PORT']??1433),'master',(string)($env['PROJECT_DB_USERNAME']??''),(string)($env['PROJECT_DB_PASSWORD']??''),(string)($env['PROJECT_DB_ENCRYPT']??'1')!=='0',(string)($env['PROJECT_DB_TRUST_SERVER_CERTIFICATE']??'0')==='1');
}

function enterprise_project_store_mysql_server(array $env): PDO
{
    foreach (['PROJECT_DB_HOST','PROJECT_DB_PORT','PROJECT_DB_USERNAME'] as $key) {
        if (trim((string)($env[$key] ?? '')) === '') throw new RuntimeException("Projekt-DB-Konfiguration {$key} fehlt.");
    }
    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';charset=utf8mb4',
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

function enterprise_project_store_mysql_pdo(array $env, string $database): PDO
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger Projektdatenbankname.');
    return new PDO(
        'mysql:host=' . $env['PROJECT_DB_HOST'] . ';port=' . (int)$env['PROJECT_DB_PORT'] . ';dbname=' . $database . ';charset=' . ($env['PROJECT_DB_CHARSET'] ?? 'utf8mb4'),
        (string)$env['PROJECT_DB_USERNAME'],
        (string)($env['PROJECT_DB_PASSWORD'] ?? ''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

function enterprise_project_store_pdo(array $env, string $database, ?string $driver = null): PDO
{
    $driver = strtolower(trim((string)($driver ?? enterprise_project_store_driver($env))));
    if ($driver === 'csv') return new EnterpriseCsvPdo(enterprise_project_store_csv_base($env), $database);
    if ($driver === 'sqlite') return new EnterpriseSqlitePdo(enterprise_project_store_sqlite_database_path($env, $database));
    if ($driver === 'pgsql') return enterprise_project_store_pgsql_pdo($env,$database);
    if ($driver === 'oracle') return enterprise_project_store_oracle_pdo($env,$database);
    if ($driver === 'mssql') return enterprise_project_store_mssql_pdo($env,$database);
    if (in_array($driver, ['mysql','mariadb'], true)) return enterprise_project_store_mysql_pdo($env, $database);
    throw new RuntimeException('Nicht unterstützter Projektdatenspeicher: ' . $driver);
}


function enterprise_project_store_for_project(array $env, array $project): PDO
{
    return enterprise_project_store_pdo_for_project_path($env, $project, true);
}

function enterprise_project_store_exists(array $env, string $database, ?string $driver = null): bool
{
    $driver = strtolower(trim((string)($driver ?? enterprise_project_store_driver($env))));
    if ($driver === 'csv') {
        $path = enterprise_project_store_csv_database_path($env, $database);
        return is_dir($path) && (is_file($path . '/dataforms.csv') || is_file($path . '/migrations.csv') || is_file($path . '/_relations.json'));
    }
    if ($driver === 'sqlite') {
        $path = enterprise_project_store_sqlite_database_path($env, $database);
        if (!is_file($path) || filesize($path)===0) return false;
        try { $pdo=new EnterpriseSqlitePdo($path); return (string)$pdo->query('PRAGMA integrity_check')->fetchColumn()==='ok'; } catch (Throwable) { return false; }
    }
    if ($driver === 'oracle') {
        try { $pdo=enterprise_project_store_oracle_pdo($env,$database); return (int)$pdo->query('SELECT COUNT(*) FROM user_tables')->fetchColumn()>=0; } catch (Throwable) { return false; }
    }
    if ($driver === 'mssql') {
        try{$server=enterprise_project_store_mssql_server($env);$st=$server->prepare('SELECT COUNT(*) FROM sys.databases WHERE name=?');$st->execute([$database]);return (int)$st->fetchColumn()>0;}catch(Throwable){return false;}
    }
    if ($driver === 'pgsql') {
        try {
            $server=enterprise_project_store_pgsql_server($env);
            $st=$server->prepare('SELECT 1 FROM pg_database WHERE datname=?');
            $st->execute([$database]);
            if($st->fetchColumn()===false)return false;
            $schema=enterprise_project_store_pgsql_schema($env);
            $target=new EnterprisePgsqlPdo((string)$env['PROJECT_DB_HOST'],(int)$env['PROJECT_DB_PORT'],$database,(string)$env['PROJECT_DB_USERNAME'],(string)($env['PROJECT_DB_PASSWORD']??''),'public');
            $st=$target->prepare('SELECT 1 FROM pg_namespace WHERE nspname=?');$st->execute([$schema]);
            return $st->fetchColumn()!==false;
        } catch (Throwable) { return false; }
    }
    if (in_array($driver, ['mysql','mariadb'], true)) {
        $server = enterprise_project_store_mysql_server($env);
        $st = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');
        $st->execute([$database]);
        return $st->fetchColumn() !== false;
    }
    return false;
}

function enterprise_project_store_create(array $env, string $database, ?string $driver = null): PDO
{
    $driver = strtolower(trim((string)($driver ?? enterprise_project_store_driver($env))));
    if ($driver === 'csv') return new EnterpriseCsvPdo(enterprise_project_store_csv_base($env), $database);
    if ($driver === 'sqlite') return new EnterpriseSqlitePdo(enterprise_project_store_sqlite_database_path($env, $database));
    if ($driver === 'oracle') {
        // Oracle XE stores each project in the configured schema/user inside the PDB.
        // Provisioning of the Oracle user itself is deliberately separated from runtime credentials.
        return enterprise_project_store_oracle_pdo($env,$database);
    }
    if ($driver === 'pgsql') {
        if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger PostgreSQL-Projektdatenbankname.');
        $server=enterprise_project_store_pgsql_server($env);
        $st=$server->prepare('SELECT 1 FROM pg_database WHERE datname=?');$st->execute([$database]);
        if ($st->fetchColumn()===false) {
            $quoted='"'.str_replace('"','""',$database).'"';
            $server->exec('CREATE DATABASE '.$quoted." ENCODING 'UTF8' TEMPLATE template0");
        }
        $st=$server->prepare('SELECT 1 FROM pg_database WHERE datname=?');$st->execute([$database]);
        if($st->fetchColumn()===false)throw new RuntimeException('PostgreSQL-Projektdatenbank wurde nach CREATE DATABASE nicht gefunden: '.$database);
        $schema=enterprise_project_store_pgsql_schema($env);
        $target=new EnterprisePgsqlPdo((string)$env['PROJECT_DB_HOST'],(int)$env['PROJECT_DB_PORT'],$database,(string)$env['PROJECT_DB_USERNAME'],(string)($env['PROJECT_DB_PASSWORD']??''),'public');
        $target->exec('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
        $st=$target->prepare('SELECT 1 FROM pg_namespace WHERE nspname=?');$st->execute([$schema]);
        if($st->fetchColumn()===false)throw new RuntimeException('PostgreSQL-Projektschema wurde nach CREATE SCHEMA nicht gefunden: '.$schema);
        return enterprise_project_store_pgsql_pdo($env,$database);
    }
    if ($driver === 'mssql') {
        if(!enterprise_project_store_valid_name($database))throw new RuntimeException('Ungültiger MSSQL-Projektdatenbankname.');
        $server=enterprise_project_store_mssql_server($env);$st=$server->prepare('SELECT COUNT(*) FROM sys.databases WHERE name=?');$st->execute([$database]);if((int)$st->fetchColumn()===0)$server->exec('CREATE DATABASE ['.str_replace(']',']]', $database).']');return enterprise_project_store_mssql_pdo($env,$database);
    }
    if (in_array($driver, ['mysql','mariadb'], true)) {
        $server = enterprise_project_store_mysql_server($env);
        $q='`'.str_replace('`','``',$database).'`';
        // HF159: Existing project databases are a supported reuse path. The
        // setup workflow decides explicitly whether an existing DB is reused
        // or deleted. Creation therefore occurs only when the DB is absent.
        $exists=$server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=?');
        $exists->execute([$database]);
        if($exists->fetchColumn()===false){
            try { $server->exec("CREATE DATABASE {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
            catch (PDOException $e) {
                if (str_contains(strtolower($e->getMessage()), 'collation')) $server->exec("CREATE DATABASE {$q} CHARACTER SET utf8mb4");
                else throw $e;
            }
        }
        return enterprise_project_store_mysql_pdo($env, $database);
    }
    throw new RuntimeException('Nicht unterstützter Projektdatenspeicher: ' . $driver);
}

function enterprise_project_store_remove_tree(string $path): void
{
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isLink() || $item->isFile()) { if (!@unlink($item->getPathname())) throw new RuntimeException('Datei konnte nicht gelöscht werden: '.$item->getPathname()); }
        elseif ($item->isDir() && !@rmdir($item->getPathname())) throw new RuntimeException('Ordner konnte nicht gelöscht werden: '.$item->getPathname());
    }
    if (!@rmdir($path)) throw new RuntimeException('CSV-Projektspeicher konnte nicht gelöscht werden: '.$path);
}

function enterprise_project_store_delete(array $env, string $database, ?string $driver = null): bool
{
    $driver = strtolower(trim((string)($driver ?? enterprise_project_store_driver($env))));
    if ($driver === 'csv') {
        $baseReal = realpath(enterprise_project_store_csv_base($env));
        $path = enterprise_project_store_csv_database_path($env, $database);
        $pathReal = realpath($path);
        if ($pathReal === false) return false;
        if ($baseReal === false || !str_starts_with(str_replace('\\','/',$pathReal), rtrim(str_replace('\\','/',$baseReal),'/').'/')) {
            throw new RuntimeException('CSV-Projektspeicher liegt außerhalb des freigegebenen Basisordners.');
        }
        enterprise_project_store_remove_tree($pathReal);
        return true;
    }
    if ($driver === 'sqlite') {
        $base = enterprise_project_store_sqlite_base($env);
        $path = enterprise_project_store_sqlite_database_path($env, $database);
        if (!is_file($path)) return false;
        $baseReal = realpath($base);
        $pathReal = realpath($path);
        if ($baseReal===false || $pathReal===false || !str_starts_with(str_replace('\\','/',$pathReal), rtrim(str_replace('\\','/',$baseReal),'/').'/')) {
            throw new RuntimeException('SQLite-Projektspeicher liegt außerhalb des freigegebenen Basisordners.');
        }
        foreach ([$pathReal.'-wal',$pathReal.'-shm',$pathReal] as $file) if (is_file($file) && !@unlink($file)) throw new RuntimeException('SQLite-Datei konnte nicht gelöscht werden: '.$file);
        return true;
    }
    if ($driver === 'oracle') {
        $pdo=enterprise_project_store_oracle_pdo($env,$database);
        $objects=$pdo->query("SELECT object_name,object_type FROM user_objects WHERE object_type IN ('VIEW','TABLE','SEQUENCE') ORDER BY CASE object_type WHEN 'VIEW' THEN 1 WHEN 'TABLE' THEN 2 ELSE 3 END")->fetchAll(PDO::FETCH_ASSOC);
        foreach($objects as $o){$name=(string)($o['OBJECT_NAME']??$o['object_name']??'');$type=strtoupper((string)($o['OBJECT_TYPE']??$o['object_type']??''));if($name===''||!in_array($type,['VIEW','TABLE','SEQUENCE'],true))continue;$q='"'.str_replace('"','""',$name).'"';try{$pdo->exec('DROP '.$type.' '.$q.($type==='TABLE'?' CASCADE CONSTRAINTS PURGE':''));}catch(Throwable){}}
        return true;
    }
    if ($driver === 'pgsql') {
        if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger PostgreSQL-Projektdatenbankname.');
        $schema=enterprise_project_store_pgsql_schema($env);
        $adminDriver=strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'')));
        if(in_array($adminDriver,['postgres','postgresql'],true))$adminDriver='pgsql';
        $adminDatabase=trim((string)($env['ADMIN_DB_DATABASE']??''));
        $adminSchema=trim((string)($env['ADMIN_DB_SCHEMA']??'public')) ?: 'public';
        if($adminDriver==='pgsql' && $adminDatabase===$database){
            if($adminSchema===$schema) throw new RuntimeException('PostgreSQL-Projektschema entspricht dem Administrationsschema und darf nicht gelöscht werden.');
            $target=new EnterprisePgsqlPdo((string)$env['PROJECT_DB_HOST'],(int)$env['PROJECT_DB_PORT'],$database,(string)$env['PROJECT_DB_USERNAME'],(string)($env['PROJECT_DB_PASSWORD']??''),'public');
            $target->exec('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
            return true;
        }
        $server=enterprise_project_store_pgsql_server($env);
        try { $st=$server->prepare('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname=? AND pid<>pg_backend_pid()');$st->execute([$database]); } catch (Throwable) {}
        $quoted='"'.str_replace('"','""',$database).'"';
        $server->exec('DROP DATABASE IF EXISTS '.$quoted);
        return true;
    }
    if ($driver === 'mssql') {
        $server=enterprise_project_store_mssql_server($env);$q='['.str_replace(']',']]', $database).']';try{$server->exec('ALTER DATABASE '.$q.' SET SINGLE_USER WITH ROLLBACK IMMEDIATE');}catch(Throwable){}$server->exec('DROP DATABASE '.$q);return true;
    }
    if (in_array($driver, ['mysql','mariadb'], true)) {
        $server = enterprise_project_store_mysql_server($env);
        $q='`'.str_replace('`','``',$database).'`';
        $server->exec('DROP DATABASE IF EXISTS '.$q);
        return true;
    }
    throw new RuntimeException('Nicht unterstützter Projektdatenspeicher: ' . $driver);
}

/** @return list<string> */
function enterprise_project_store_install_schema(PDO $pdo, string $directory): array
{
    $files = glob(rtrim($directory,'/\\').'/*.php') ?: [];
    sort($files, SORT_NATURAL);
    if ($files === []) throw new RuntimeException('Keine Projekt-Schema-Dateien gefunden.');
    $done=[];
    foreach ($files as $file) {
        $name=basename($file); $checksum=hash_file('sha256',$file);
        if (!is_string($checksum) || strlen($checksum)!==64) throw new RuntimeException('Checksum konnte nicht ermittelt werden: '.$name);
        $exists=false;
        try {
            $st=$pdo->prepare('SELECT migration,checksum FROM migrations WHERE migration=? LIMIT 1');
            $st->execute([$name]); $row=$st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                if (!hash_equals((string)$row['checksum'],$checksum)) throw new RuntimeException('Migration '.$name.' wurde nach Ausführung verändert.');
                $exists=true;
            }
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(),'verändert')) throw $e;
        }
        if ($exists) { $done[]='SKIP '.$name.' (Checksum OK)'; continue; }
        $installer=require $file;
        if (!is_callable($installer)) throw new RuntimeException('Ungültige Projekt-Schema-Datei: '.$name);
        $installer($pdo);
        try {
            $driverName=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql=($pdo instanceof EnterpriseSqlitePdo || $driverName==='sqlite')
                ? 'INSERT OR IGNORE INTO migrations(migration,checksum) VALUES (?,?)'
                : ($driverName==='pgsql' ? 'INSERT INTO migrations(migration,checksum) VALUES (?,?) ON CONFLICT (migration) DO NOTHING' : 'INSERT INTO migrations(migration,checksum) VALUES (?,?) ON DUPLICATE KEY UPDATE checksum=checksum');
            $st=$pdo->prepare($sql); $st->execute([$name,$checksum]);
        } catch (Throwable) {
            // 001_migrations.php registriert sich bereits selbst; spätere Adapter dürfen idempotent sein.
        }
        $done[]='APPLY '.$name;
    }
    return $done;
}


/**
 * Registers the physical CSV project store itself as the default writable
 * DataForm data source. Thus metadata and application tables can live in the
 * same project-scoped CSV database while remaining accessed through the
 * normal CSV adapter for arbitrary user tables.
 */
function enterprise_project_store_ensure_default_csv_source(PDO $pdo, array $env, string $database): int
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger CSV-Projektspeichername.');
    $name='Projekt-CSV-Datenspeicher';
    $config=json_encode([
        'base_path'=>enterprise_project_store_csv_base($env),
        'database'=>$database,
    ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? LIMIT 1');
    $st->execute([$name]);
    $id=(int)($st->fetchColumn()?:0);
    if($id>0){
        $up=$pdo->prepare("UPDATE data_sources SET driver='csv',config_json=?,is_enabled=1 WHERE id=?");
        $up->execute([$config,$id]);
        return $id;
    }
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner CSV-Datenspeicher')");
    $ins->execute([$name,'csv',$config]);
    return (int)$pdo->lastInsertId();
}


function enterprise_project_store_ensure_default_sqlite_source(PDO $pdo, array $env, string $database): int
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger SQLite-Projektspeichername.');
    $name='Projekt-SQLite-Datenspeicher';
    $config=json_encode(['path'=>enterprise_project_store_sqlite_database_path($env,$database)], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? LIMIT 1'); $st->execute([$name]); $id=(int)($st->fetchColumn()?:0);
    if($id>0){$up=$pdo->prepare("UPDATE data_sources SET driver='sqlite',config_json=?,is_enabled=1 WHERE id=?");$up->execute([$config,$id]);return $id;}
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner SQLite-Datenspeicher')");
    $ins->execute([$name,'sqlite',$config]); return (int)$pdo->lastInsertId();
}


function enterprise_project_store_ensure_default_pgsql_source(PDO $pdo, array $env, string $database): int
{
    if (!enterprise_project_store_valid_name($database)) throw new RuntimeException('Ungültiger PostgreSQL-Projektspeichername.');
    $name='Projekt-PostgreSQL-Datenspeicher';
    $config=json_encode([
        'host'=>(string)($env['PROJECT_DB_HOST']??'127.0.0.1'),
        'port'=>(int)($env['PROJECT_DB_PORT']??5432),
        'database'=>$database,
        'schema'=>enterprise_project_store_pgsql_schema($env),
        'username'=>(string)($env['PROJECT_DB_USERNAME']??''),
        'charset'=>'UTF8',
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? LIMIT 1');$st->execute([$name]);$id=(int)($st->fetchColumn()?:0);
    if($id>0){$up=$pdo->prepare("UPDATE data_sources SET driver='pgsql',config_json=?,is_enabled=1 WHERE id=?");$up->execute([$config,$id]);return $id;}
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner PostgreSQL-Datenspeicher')");
    $ins->execute([$name,'pgsql',$config]); return (int)$pdo->lastInsertId();
}


function enterprise_project_store_ensure_default_oracle_source(PDO $pdo,array $env,string $database): int
{
    $name='Projekt-Oracle-XE-Datenspeicher';
    $config=json_encode(['host'=>(string)($env['PROJECT_DB_HOST']??'127.0.0.1'),'port'=>(int)($env['PROJECT_DB_PORT']??1521),'service'=>(string)($env['PROJECT_DB_ORACLE_SERVICE']??'XEPDB1'),'username'=>(string)($env['PROJECT_DB_USERNAME']??''),'charset'=>'AL32UTF8'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? FETCH FIRST 1 ROWS ONLY');$st->execute([$name]);$id=(int)($st->fetchColumn()?:0);
    if($id>0){$up=$pdo->prepare("UPDATE data_sources SET driver='oracle',config_json=?,is_enabled=1 WHERE id=?");$up->execute([$config,$id]);return $id;}
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner Oracle-XE-Datenspeicher')");$ins->execute([$name,'oracle',$config]);return (int)$pdo->lastInsertId();
}


function enterprise_project_store_ensure_default_mssql_source(PDO $pdo,array $env,string $database): int
{
    $name='Projekt-MSSQL-Datenspeicher';
    $config=json_encode(['host'=>(string)($env['PROJECT_DB_HOST']??'127.0.0.1'),'port'=>(int)($env['PROJECT_DB_PORT']??1433),'database'=>$database,'username'=>(string)($env['PROJECT_DB_USERNAME']??''),'encrypt'=>(string)($env['PROJECT_DB_ENCRYPT']??'1')!=='0','trust_server_certificate'=>(string)($env['PROJECT_DB_TRUST_SERVER_CERTIFICATE']??'0')==='1'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    $st=$pdo->prepare('SELECT id FROM data_sources WHERE name=? LIMIT 1');$st->execute([$name]);$id=(int)($st->fetchColumn()?:0);
    if($id>0){$up=$pdo->prepare("UPDATE data_sources SET driver='mssql',config_json=?,is_enabled=1 WHERE id=?");$up->execute([$config,$id]);return $id;}
    $ins=$pdo->prepare("INSERT INTO data_sources(name,driver,config_json,is_enabled,last_test_status,last_test_message) VALUES(?,?,?,1,'pass','Projektinterner MSSQL-Datenspeicher')");$ins->execute([$name,'mssql',$config]);return (int)$pdo->lastInsertId();
}
