<?php
declare(strict_types=1);

session_start();
require dirname(__DIR__) . '/system/ui/layout.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseCsvPdo.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseSqlitePdo.php';
require_once dirname(__DIR__) . '/system/app/EnterprisePgsqlPdo.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseOraclePdo.php';
require_once dirname(__DIR__) . '/system/app/EnterpriseMssqlPdo.php';
require_once dirname(__DIR__) . '/system/app/project_store.php';
require_once __DIR__ . '/admin-schema-gate.php';

$rootPath = dirname(__DIR__);
$envPath = $rootPath . '/DataForm5-Core/.env';
$messages = [];
$results = [];
$diagnostics = [];

/**
 * Nur tatsächlich registrierte PDO-Treiber werden im Einrichtungsdialog
 * angeboten. CSV bleibt unabhängig von PDO immer verfügbar.
 */
function installer_runtime_driver_options(): array
{
    $availablePdo = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
    $all = [
        'mysql' => ['label' => 'MariaDB / MySQL', 'pdo' => 'mysql'],
        'csv' => ['label' => 'CSV', 'pdo' => null],
        'sqlite' => ['label' => 'SQLite', 'pdo' => 'sqlite'],
        'pgsql' => ['label' => 'PostgreSQL', 'pdo' => 'pgsql'],
        'oracle' => ['label' => 'Oracle XE', 'pdo' => 'oci'],
        'mssql' => ['label' => 'Microsoft SQL Server', 'pdo' => 'sqlsrv'],
    ];
    $options = [];
    foreach ($all as $driver => $meta) {
        if ($meta['pdo'] === null || in_array($meta['pdo'], $availablePdo, true)) {
            $options[$driver] = $meta['label'];
        }
    }
    return $options;
}

if (!isset($_SESSION['easyit_csrf'])) {
    $_SESSION['easyit_csrf'] = bin2hex(random_bytes(32));
}

function db_e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function installerDirectoryWritable(string $dir): bool
{
    if (!is_dir($dir)) return false;

    $probe = rtrim($dir, "\\/") . DIRECTORY_SEPARATOR . '.easyit-write-test-' . bin2hex(random_bytes(6)) . '.tmp';
    $written = @file_put_contents($probe, 'easyit');
    if ($written === false) return false;

    @unlink($probe);
    return true;
}
function validDatabaseName(string $name): bool { return preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/', $name) === 1; }
function validPgsqlSchemaName(string $name): bool { return preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $name) === 1; }
function quoteIdentifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }
function pdoServer(string $host, int $port, string $user, string $password): PDO
{
    return new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 8,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}
function pgsqlServer(string $host,int $port,string $user,string $password,string $database='postgres'): PDO
{
    if(!extension_loaded('pdo_pgsql')) throw new RuntimeException('Die PHP-Erweiterung pdo_pgsql ist für PostgreSQL nicht aktiv.');
    return new EnterprisePgsqlPdo($host,$port,$database,$user,$password);
}
function pgsqlDatabaseExists(PDO $pdo,string $database): bool
{
    $st=$pdo->prepare('SELECT 1 FROM pg_database WHERE datname=?');$st->execute([$database]);return $st->fetchColumn()!==false;
}
function createPgsqlDatabase(PDO $pdo,string $database): void
{
    if(!validDatabaseName($database))throw new RuntimeException('Ungültiger PostgreSQL-Datenbankname.');
    if(!pgsqlDatabaseExists($pdo,$database)){
        $q='"'.str_replace('"','""',$database).'"';
        $pdo->exec('CREATE DATABASE '.$q." ENCODING 'UTF8' TEMPLATE template0");
    }
    if(!pgsqlDatabaseExists($pdo,$database))throw new RuntimeException('PostgreSQL-Datenbank wurde nach CREATE DATABASE nicht gefunden: '.$database);
}
function createPgsqlSchema(PDO $pdo,string $schema): void
{
    if(!validPgsqlSchemaName($schema))throw new RuntimeException('Ungültiger PostgreSQL-Schemaname.');
    $pdo->exec('CREATE SCHEMA IF NOT EXISTS "'.$schema.'"');
    $st=$pdo->prepare('SELECT 1 FROM pg_namespace WHERE nspname=?');$st->execute([$schema]);
    if($st->fetchColumn()===false)throw new RuntimeException('PostgreSQL-Schema wurde nach CREATE SCHEMA nicht gefunden: '.$schema);
}
function pgsqlDiagnostics(PDO $pdo): array
{
    $row=$pdo->query("SELECT version() AS version,current_database() AS database,current_user AS authenticated_user,current_schema() AS schema,inet_server_addr()::text AS hostname,inet_server_port() AS port")->fetch(PDO::FETCH_ASSOC)?:[];
    return ['Serverversion'=>(string)($row['version']??'unbekannt'),'Servername'=>(string)($row['hostname']??'lokal/Socket'),'Serverport'=>(string)($row['port']??'unbekannt'),'Datenbank'=>(string)($row['database']??'unbekannt'),'Schema'=>(string)($row['schema']??'unbekannt'),'Angemeldeter DB-Benutzer'=>(string)($row['authenticated_user']??'unbekannt')];
}

function mssqlServer(string $host,int $port,string $user,string $password,string $database='master'): PDO
{
    return new EnterpriseMssqlPdo($host,$port,$database,$user,$password,true,false);
}
function mssqlDatabaseExists(PDO $pdo,string $database): bool{$st=$pdo->prepare('SELECT COUNT(*) FROM sys.databases WHERE name=?');$st->execute([$database]);return (int)$st->fetchColumn()>0;}
function createMssqlDatabase(PDO $pdo,string $database): void{if(!validDatabaseName($database))throw new RuntimeException('Ungültiger MSSQL-Datenbankname.');if(!mssqlDatabaseExists($pdo,$database))$pdo->exec('CREATE DATABASE ['.str_replace(']',']]', $database).']');}
function mssqlDiagnostics(PDO $pdo): array{return ['Serverversion'=>(string)$pdo->query("SELECT CAST(SERVERPROPERTY('ProductVersion') AS nvarchar(128))")->fetchColumn(),'Datenbank'=>(string)$pdo->query('SELECT DB_NAME()')->fetchColumn()];}

function oracleServer(string $host,int $port,string $service,string $user,string $password): PDO
{
    return new EnterpriseOraclePdo($host,$port,$service,$user,$password);
}
function oracleDiagnostics(PDO $pdo): array
{
    $v=(string)$pdo->query("SELECT banner FROM v\$version WHERE ROWNUM=1")->fetchColumn();
    $u=(string)$pdo->query("SELECT USER FROM dual")->fetchColumn();
    return ['Serverversion'=>$v,'Service'=>'Oracle XE/PDB','Angemeldeter DB-Benutzer'=>$u];
}

function envValues(string $path): array
{
    $values = [];
    if (!is_file($path)) return $values;
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}
function updateEnv(string $path, array $updates): void
{
    if (!is_file($path)) {
        $example = dirname($path) . '/.env.example';
        if (!is_file($example) || !is_readable($example)) {
            throw new RuntimeException('DataForm5-Core/.env fehlt und .env.example ist nicht verfügbar.');
        }
        $dir = dirname($path);
        if (!is_dir($dir) || !installerDirectoryWritable($dir)) {
            $last = error_get_last();
            $detail = is_array($last) && isset($last['message']) ? ' (' . $last['message'] . ')' : '';
            throw new RuntimeException('DataForm5-Core kann vom PHP/Apache-Prozess nicht beschrieben werden; .env kann nicht neu erzeugt werden.' . $detail);
        }
        if (!@copy($example, $path)) {
            $last = error_get_last();
            $detail = is_array($last) && isset($last['message']) ? ' (' . $last['message'] . ')' : '';
            throw new RuntimeException('DataForm5-Core/.env konnte aus .env.example nicht neu erzeugt werden.' . $detail);
        }
        @chmod($path, 0600);
    }
    if (!is_readable($path)) {
        throw new RuntimeException('DataForm5-Core/.env ist nicht lesbar.');
    }
    // Unter Windows kann is_writable() bei geerbten/entpackten ACLs falsch negativ sein.
    // Deshalb wird die tatsächliche Schreibbarkeit erst beim atomaren Schreibvorgang geprüft.
    $content = (string)file_get_contents($path);
    foreach ($updates as $key => $value) {
        $safe = str_replace(["\r", "\n"], '', (string)$value);
        $line = $key . '=' . $safe;
        if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $content)) {
            $content = preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $content) ?? $content;
        } else {
            $content .= PHP_EOL . $line;
        }
    }
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Die .env-Datei konnte nicht aktualisiert werden.');
    }
}

function removeEnvKeys(string $path,array $keys): void
{
    if(!is_file($path))return;
    $content=(string)file_get_contents($path);
    foreach($keys as $key)$content=preg_replace('/^'.preg_quote((string)$key,'/').'=.*(?:\r?\n|$)/m','',$content)??$content;
    if(file_put_contents($path,$content,LOCK_EX)===false)throw new RuntimeException('Veraltete Storage-Einträge konnten nicht aus .env entfernt werden.');
}

function ensureDataFormSecretKey(string $envPath): array
{
    $values = envValues($envPath);

    if (trim((string)($values['DATAFORM_APP_KEY'] ?? '')) !== '') {
        return ['created'=>false,'source'=>'DATAFORM_APP_KEY'];
    }

    if (trim((string)($values['APP_KEY'] ?? '')) !== '') {
        return ['created'=>false,'source'=>'APP_KEY'];
    }

    $key = 'dfk1_' . rtrim(
        strtr(base64_encode(random_bytes(32)), '+/', '-_'),
        '='
    );
    updateEnv($envPath, ['DATAFORM_APP_KEY'=>$key]);

    $verify = envValues($envPath);
    if (!hash_equals($key, (string)($verify['DATAFORM_APP_KEY'] ?? ''))) {
        throw new RuntimeException('DATAFORM_APP_KEY konnte nicht verifiziert werden.');
    }

    return ['created'=>true,'source'=>'DATAFORM_APP_KEY'];
}

function databaseExists(PDO $pdo, string $database): bool
{
    $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
    $stmt->execute([$database]);
    return $stmt->fetchColumn() !== false;
}
function createDatabase(PDO $pdo, string $database): void
{
    $quoted = quoteIdentifier($database);
    try {
        $pdo->exec("CREATE DATABASE IF NOT EXISTS {$quoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // Manche MariaDB-Installationen kennen diese Kollation nicht oder verwenden abweichende Defaults.
        if (str_contains(strtolower($e->getMessage()), 'collation')) {
            $pdo->exec("CREATE DATABASE IF NOT EXISTS {$quoted} CHARACTER SET utf8mb4");
        } else {
            throw $e;
        }
    }
    if (!databaseExists($pdo, $database)) {
        throw new RuntimeException("Die Datenbank {$database} wurde nach CREATE DATABASE nicht gefunden. Prüfen Sie CREATE-Rechte und den verwendeten MariaDB-Server.");
    }
}
function migrationTableExists(PDO $pdo): bool
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations'");
    return (int)$stmt->fetchColumn() > 0;
}
function migrationRecord(PDO $pdo, string $migration): ?array
{
    if (!migrationTableExists($pdo)) return null;
    $stmt = $pdo->prepare('SELECT migration, checksum, executed_at FROM migrations WHERE migration = ? LIMIT 1');
    $stmt->execute([$migration]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
function registerMigration(PDO $pdo, string $migration, string $checksum): void
{
    if (!migrationTableExists($pdo)) {
        throw new RuntimeException("Migrationstabelle fehlt nach Ausführung von {$migration}.");
    }
    $stmt = $pdo->prepare('INSERT INTO migrations (migration, checksum) VALUES (?, ?) ON DUPLICATE KEY UPDATE checksum = checksum');
    $stmt->execute([$migration, $checksum]);
}
function installSchema(PDO $server, string $database, string $directory): array
{
    if (!databaseExists($server, $database)) {
        throw new RuntimeException("Schema-Installation abgebrochen: Datenbank {$database} ist nicht vorhanden.");
    }
    $server->exec('USE ' . quoteIdentifier($database));
    $files = glob($directory . '/*.php') ?: [];
    sort($files, SORT_NATURAL);
    if ($files === []) throw new RuntimeException('Keine Schema-Dateien gefunden: ' . $directory);

    $done = [];
    foreach ($files as $file) {
        $name = basename($file);
        $checksum = hash_file('sha256', $file);
        if (!is_string($checksum) || strlen($checksum) !== 64) {
            throw new RuntimeException("SHA-256 konnte für {$name} nicht ermittelt werden.");
        }

        $existing = migrationRecord($server, $name);
        if ($existing !== null) {
            if (!hash_equals((string)$existing['checksum'], $checksum)) {
                throw new RuntimeException("Migration {$name} wurde nach ihrer Ausführung verändert. Erwartet: {$existing['checksum']}; aktuell: {$checksum}");
            }
            $done[] = 'SKIP ' . $name . ' (bereits ausgeführt, Checksum OK)';
            continue;
        }

        $installer = require $file;
        if (!is_callable($installer)) throw new RuntimeException('Ungültige Schema-Datei: ' . $name);

        // MySQL/MariaDB performs implicit commits for DDL statements such as
        // CREATE TABLE. Wrapping schema migrations in a PDO transaction therefore
        // causes a later commit() to fail with "There is no active transaction".
        // Execute the schema callable directly and register it only after success.
        $installer($server);
        registerMigration($server, $name, $checksum);
        $done[] = 'APPLY ' . $name;
    }
    return $done;
}
function serverDiagnostics(PDO $pdo): array
{
    $row = $pdo->query("SELECT VERSION() AS version, @@hostname AS hostname, @@port AS port, @@datadir AS datadir, CURRENT_USER() AS authenticated_user")->fetch() ?: [];
    return [
        'Serverversion' => (string)($row['version'] ?? 'unbekannt'),
        'Servername' => (string)($row['hostname'] ?? 'unbekannt'),
        'Serverport' => (string)($row['port'] ?? 'unbekannt'),
        'Datenverzeichnis' => (string)($row['datadir'] ?? 'unbekannt'),
        'Angemeldeter DB-Benutzer' => (string)($row['authenticated_user'] ?? 'unbekannt'),
    ];
}

$env = envValues($envPath);
// RC1.1 Phase-2-Kompatibilitätsmarker: ['mysql','csv','sqlite','pgsql','oracle']
$driverOptions=installer_runtime_driver_options();
$allowedDrivers=array_keys($driverOptions);
$defaultDriver=isset($driverOptions['mysql'])?'mysql':(array_key_first($driverOptions)??'csv');
$adminDriver=strtolower((string)($env['ADMIN_DB_DRIVER'] ?? $defaultDriver)); if(in_array($adminDriver,['postgres','postgresql'],true))$adminDriver='pgsql';
$projectDriver=strtolower((string)($env['PROJECT_DB_DRIVER'] ?? $defaultDriver)); if(in_array($projectDriver,['postgres','postgresql'],true))$projectDriver='pgsql';
$form = [
    'admin_driver' => in_array($adminDriver,$allowedDrivers,true)?$adminDriver:$defaultDriver,
    'admin_csv_base' => (string)($env['ADMIN_DB_CSV_BASE_PATH'] ?? 'storage/admin-csv'),
    'admin_sqlite_base' => (string)($env['ADMIN_DB_SQLITE_BASE_PATH'] ?? 'storage/admin-sqlite'),
    'admin_host' => (string)($env['ADMIN_DB_HOST'] ?? '127.0.0.1'),
    'admin_port' => (string)($env['ADMIN_DB_PORT'] ?? ($adminDriver==='pgsql'?'5432':($adminDriver==='oracle'?'1521':($adminDriver==='mssql'?'1433':'3306')))),
    'admin_oracle_service'=>(string)($env['ADMIN_DB_ORACLE_SERVICE']??'XEPDB1'),
    'admin_maintenance_database'=>(string)($env['ADMIN_DB_MAINTENANCE_DATABASE']??'postgres'),
    'admin_schema'=>(string)($env['ADMIN_DB_SCHEMA']??'public'),
    'admin_username' => (string)($env['ADMIN_DB_USERNAME'] ?? ($adminDriver==='pgsql'?'postgres':($adminDriver==='oracle'?'easyit_admin':'root'))),
    'project_driver' => in_array($projectDriver,$allowedDrivers,true)?$projectDriver:$defaultDriver,
    'project_host' => (string)($env['PROJECT_DB_HOST'] ?? '127.0.0.1'),
    'project_port' => (string)($env['PROJECT_DB_PORT'] ?? ($projectDriver==='pgsql'?'5432':($projectDriver==='oracle'?'1521':($projectDriver==='mssql'?'1433':'3306')))),
    'project_oracle_service'=>(string)($env['PROJECT_DB_ORACLE_SERVICE']??'XEPDB1'),
    'project_maintenance_database'=>(string)($env['PROJECT_DB_MAINTENANCE_DATABASE']??'postgres'),
    'project_schema'=>(string)($env['PROJECT_DB_SCHEMA']??'public'),
    'project_username' => (string)($env['PROJECT_DB_USERNAME'] ?? ($projectDriver==='pgsql'?'postgres':($projectDriver==='oracle'?'easyit_project':'root'))),
    'admin_db' => (string)($env['ADMIN_DB_DATABASE'] ?? 'easyit_license'),
    'project_name' => (string)($env['CONTEXT_PROJECT_NAME'] ?? 'Demo'),
    'project_db' => (string)($env['PROJECT_DB_DATABASE'] ?? 'easyit_project_demo'),
];

function csvAdminAbsoluteBase(string $rootPath, string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') throw new RuntimeException('Der CSV-Basispfad für den Administrationsspeicher fehlt.');
    if (preg_match('~^(?:[A-Za-z]:/|/)~', $path)) return rtrim($path, '/');
    return rtrim($rootPath, '/\\') . '/' . ltrim($path, '/');
}
function csvProjectAbsoluteBase(string $rootPath, string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') throw new RuntimeException('Der CSV-Basispfad für Projektdaten fehlt.');
    if (preg_match('~^(?:[A-Za-z]:/|/)~', $path)) return rtrim($path, '/');
    return rtrim($rootPath, '/\\') . '/' . ltrim($path, '/');
}

function sqliteAbsoluteBase(string $rootPath,string $path,string $label): string
{
    $path=trim(str_replace('\\','/',$path));
    if($path==='')throw new RuntimeException('Der SQLite-Basispfad für '.$label.' fehlt.');
    if(!preg_match('~^(?:[A-Za-z]:/|/)~',$path))$path=rtrim($rootPath,'/\\').'/'.ltrim($path,'/');
    return rtrim($path,'/');
}
function sqliteDatabasePath(string $rootPath,string $base,string $database,string $label): string
{
    if(!validDatabaseName($database))throw new RuntimeException('Ungültiger SQLite-Speichername für '.$label.'.');
    return sqliteAbsoluteBase($rootPath,$base,$label).'/'.$database.'.sqlite';
}
function sqliteProbe(string $rootPath,string $base,string $label): string
{
    if(!extension_loaded('pdo_sqlite'))throw new RuntimeException('Die PHP-Erweiterung pdo_sqlite ist für SQLite nicht aktiv.');
    $dir=sqliteAbsoluteBase($rootPath,$base,$label);
    if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('SQLite-Verzeichnis konnte nicht angelegt werden: '.$dir);
    $probe=$dir.'/.easyit_sqlite_probe_'.bin2hex(random_bytes(5));
    if(@file_put_contents($probe,'ok',LOCK_EX)===false)throw new RuntimeException('SQLite-Verzeichnis ist nicht beschreibbar: '.$dir);
    @unlink($probe);
    return $dir;
}
function registerPreparedProject(PDO $adminPdo,string $name,string $driver,string $database): array
{
    enterprise_project_storage_upgrade($adminPdo);
    $name=trim($name)?:$database;
    $st=$adminPdo->prepare('SELECT id,name,slug,storage_key FROM projects WHERE database_driver=? AND database_name=? LIMIT 1');
    $st->execute([$driver,$database]);
    $existing=$st->fetch(PDO::FETCH_ASSOC);
    if(is_array($existing)){
        $id=(int)$existing['id'];$slug=(string)$existing['slug'];$key=trim((string)($existing['storage_key']??''));
        if($key===''){$key=enterprise_project_storage_key($id,$slug);$adminPdo->prepare('UPDATE projects SET storage_key=? WHERE id=?')->execute([$key,$id]);}
        return ['id'=>$id,'name'=>(string)$existing['name'],'slug'=>$slug,'storage_key'=>$key,'created'=>false];
    }
    $slug=strtolower(str_replace('_','-',$database));
    $slug=preg_replace('/[^a-z0-9-]+/','-',$slug)??'';$slug=trim($slug,'-');
    if(strlen($slug)<2)$slug='project-'.substr(hash('sha256',$database),0,8);
    $base=$slug;$n=2;
    while(true){$q=$adminPdo->prepare('SELECT id FROM projects WHERE slug=? LIMIT 1');$q->execute([$slug]);if($q->fetchColumn()===false)break;$slug=$base.'-'.$n++;}
    $ins=$adminPdo->prepare("INSERT INTO projects(name,slug,database_driver,database_name,status) VALUES (?,?,?,?,'provisioning')");
    $ins->execute([$name,$slug,$driver,$database]);$id=(int)$adminPdo->lastInsertId();
    $key=enterprise_project_storage_key($id,$slug);$adminPdo->prepare('UPDATE projects SET storage_key=? WHERE id=?')->execute([$key,$id]);
    return ['id'=>$id,'name'=>$name,'slug'=>$slug,'storage_key'=>$key,'created'=>true];
}

function installSchemaPortable(PDO $pdo,string $directory): array
{
    $files=glob(rtrim($directory,'/\\').'/*.php')?:[];sort($files,SORT_NATURAL);if($files===[])throw new RuntimeException('Keine Schema-Dateien gefunden: '.$directory);
    $done=[];
    foreach($files as $file){$name=basename($file);$checksum=hash_file('sha256',$file);if(!is_string($checksum)||strlen($checksum)!==64)throw new RuntimeException('SHA-256 konnte für '.$name.' nicht ermittelt werden.');
        $existing=null;try{$st=$pdo->prepare('SELECT migration,checksum,executed_at FROM migrations WHERE migration=? LIMIT 1');$st->execute([$name]);$row=$st->fetch(PDO::FETCH_ASSOC);if(is_array($row))$existing=$row;}catch(Throwable){}
        if($existing!==null){if(!hash_equals((string)$existing['checksum'],$checksum))throw new RuntimeException('Migration '.$name.' wurde nach ihrer Ausführung verändert.');$done[]='SKIP '.$name.' (bereits ausgeführt, Checksum OK)';continue;}
        $installer=require $file;if(!is_callable($installer))throw new RuntimeException('Ungültige Schema-Datei: '.$name);$installer($pdo);
        try{$dn=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$sql=$pdo instanceof EnterpriseSqlitePdo?'INSERT OR IGNORE INTO migrations(migration,checksum) VALUES (?,?)':($dn==='pgsql'?'INSERT INTO migrations(migration,checksum) VALUES (?,?) ON CONFLICT (migration) DO NOTHING':'INSERT INTO migrations(migration,checksum) VALUES (?,?) ON DUPLICATE KEY UPDATE checksum=checksum');$pdo->prepare($sql)->execute([$name,$checksum]);}catch(Throwable){}
        $done[]='APPLY '.$name;
    }
    return $done;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($form) as $key) $form[$key]=trim((string)($_POST[$key]??$form[$key]));
    $form['admin_driver']=strtolower($form['admin_driver']); if(in_array($form['admin_driver'],['postgres','postgresql'],true))$form['admin_driver']='pgsql';
    $form['project_driver']=strtolower($form['project_driver']); if(in_array($form['project_driver'],['postgres','postgresql'],true))$form['project_driver']='pgsql';
    $unavailableDriverSelections=[];
    if(!in_array($form['admin_driver'],$allowedDrivers,true)){$unavailableDriverSelections[]='Administrationsspeicher: '.$form['admin_driver'];$form['admin_driver']=$defaultDriver;}
    if(!in_array($form['project_driver'],$allowedDrivers,true)){$unavailableDriverSelections[]='Projektdatenspeicher: '.$form['project_driver'];$form['project_driver']=$defaultDriver;}
    $adminPassword=(string)($_POST['admin_password']??'');$projectPassword=(string)($_POST['project_password']??'');
    $action=(string)($_POST['action']??'test');$token=(string)($_POST['csrf_token']??'');
    try{
        if(!hash_equals((string)$_SESSION['easyit_csrf'],$token))throw new RuntimeException('Die Sicherheitsprüfung ist fehlgeschlagen. Laden Sie die Seite neu.');
        if($unavailableDriverSelections!==[])throw new RuntimeException('Nicht verfügbarer Datenbanktreiber wurde übermittelt. Fehlende PDO-Treiber dürfen nicht als Datenquelle gewählt werden: '.implode(', ',$unavailableDriverSelections));
        if(!validDatabaseName($form['admin_db'])||!validDatabaseName($form['project_db']))throw new RuntimeException('Datenbank-/Speichernamen dürfen nur Buchstaben, Zahlen und Unterstriche enthalten und müssen mit einem Buchstaben beginnen.');
        if($form['admin_driver']==='pgsql'&&!validPgsqlSchemaName($form['admin_schema']))throw new RuntimeException('Ungültiges PostgreSQL-Administrationsschema.');
        if($form['project_driver']==='pgsql'&&!validPgsqlSchemaName($form['project_schema']))throw new RuntimeException('Ungültiges PostgreSQL-Projektschema.');
        if($form['admin_driver']===$form['project_driver']&&$form['admin_db']===$form['project_db']&&($form['admin_driver']!=='pgsql'||$form['admin_schema']===$form['project_schema']))throw new RuntimeException('Administrations- und Projektspeicher müssen getrennte Datenbank-/Schema-Kombinationen besitzen.');

        $adminServer=null;$projectServer=null;$adminPdo=null;$projectPdo=null;
        if($form['admin_driver']==='mysql'){
            if(!extension_loaded('pdo_mysql'))throw new RuntimeException('Die PHP-Erweiterung pdo_mysql ist für MariaDB/MySQL nicht aktiv.');
            $port=filter_var($form['admin_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Admin-DB-Port.');
            $adminServer=pdoServer($form['admin_host'],(int)$port,$form['admin_username'],$adminPassword);$results[]='✔ Verbindung zum Admin-MariaDB/MySQL-Server erfolgreich';$diagnostics=array_merge($diagnostics,array_combine(array_map(fn($k)=>'Admin · '.$k,array_keys(serverDiagnostics($adminServer))),array_values(serverDiagnostics($adminServer))));
        }elseif($form['admin_driver']==='pgsql'){
            $port=filter_var($form['admin_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Admin-PostgreSQL-Port.');
            $adminServer=pgsqlServer($form['admin_host'],(int)$port,$form['admin_username'],$adminPassword,$form['admin_maintenance_database']?:'postgres');$results[]='✔ Verbindung zum Admin-PostgreSQL-Server erfolgreich';foreach(pgsqlDiagnostics($adminServer) as $k=>$v)$diagnostics['Admin · '.$k]=$v;
        }elseif($form['admin_driver']==='mssql'){
            $port=filter_var($form['admin_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Admin-MSSQL-Port.');
            $adminServer=mssqlServer($form['admin_host'],(int)$port,$form['admin_username'],$adminPassword,'master');$results[]='✔ Verbindung zum Admin-MSSQL-Server erfolgreich';foreach(mssqlDiagnostics($adminServer) as $k=>$v)$diagnostics['Admin · '.$k]=$v;
        }elseif($form['admin_driver']==='oracle'){
            $port=filter_var($form['admin_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Admin-Oracle-Port.');
            $adminPdo=oracleServer($form['admin_host'],(int)$port,$form['admin_oracle_service']?:'XEPDB1',$form['admin_username'],$adminPassword);$results[]='✔ Verbindung zum Admin-Oracle-XE-Schema erfolgreich';foreach(oracleDiagnostics($adminPdo) as $k=>$v)$diagnostics['Admin · '.$k]=$v;
        }elseif($form['admin_driver']==='csv'){$base=csvAdminAbsoluteBase($rootPath,$form['admin_csv_base']);$adminPdo=new EnterpriseCsvPdo($base,$form['admin_db']);$results[]='✔ CSV-Administrationsspeicher ist lesbar und beschreibbar: '.$adminPdo->storagePath();}
        elseif($form['admin_driver']==='sqlite'){$dir=sqliteProbe($rootPath,$form['admin_sqlite_base'],'den Administrationsspeicher');$results[]='✔ SQLite-Administrationspfad ist lesbar und beschreibbar: '.$dir;}

        if($form['project_driver']==='mysql'){
            if(!extension_loaded('pdo_mysql'))throw new RuntimeException('Die PHP-Erweiterung pdo_mysql ist für MariaDB/MySQL nicht aktiv.');
            $port=filter_var($form['project_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Projekt-DB-Port.');
            $projectServer=pdoServer($form['project_host'],(int)$port,$form['project_username'],$projectPassword);$results[]='✔ Verbindung zum Projekt-MariaDB/MySQL-Server erfolgreich';
        }elseif($form['project_driver']==='pgsql'){
            $port=filter_var($form['project_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Projekt-PostgreSQL-Port.');
            $projectServer=pgsqlServer($form['project_host'],(int)$port,$form['project_username'],$projectPassword,$form['project_maintenance_database']?:'postgres');$results[]='✔ Verbindung zum Projekt-PostgreSQL-Server erfolgreich';
        }elseif($form['project_driver']==='mssql'){
            $port=filter_var($form['project_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Projekt-MSSQL-Port.');
            $projectServer=mssqlServer($form['project_host'],(int)$port,$form['project_username'],$projectPassword,'master');$results[]='✔ Verbindung zum Projekt-MSSQL-Server erfolgreich';
        }elseif($form['project_driver']==='oracle'){
            $port=filter_var($form['project_port'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);if($port===false)throw new RuntimeException('Ungültiger Projekt-Oracle-Port.');
            $projectPdo=oracleServer($form['project_host'],(int)$port,$form['project_oracle_service']?:'XEPDB1',$form['project_username'],$projectPassword);$results[]='✔ Verbindung zum Projekt-Oracle-XE-Schema erfolgreich';
        }elseif(in_array($form['project_driver'],['csv','sqlite'],true)){
            $projectBase=enterprise_project_storage_base();if(!is_dir($projectBase)&&!@mkdir($projectBase,0770,true)&&!is_dir($projectBase))throw new RuntimeException('Projekt-Storage-Basis konnte nicht angelegt werden.');
            if(!installerDirectoryWritable($projectBase))throw new RuntimeException('Projekt-Storage-Basis ist nicht beschreibbar: '.$projectBase);
            $results[]='✔ Projekt-Storage-Basis ist lesbar und beschreibbar: '.$projectBase;
        }

        if(in_array($action,['create','install'],true)){
            if($form['admin_driver']==='mysql'){createDatabase($adminServer,$form['admin_db']);$results[]='✔ Administrationsdatenbank `'.$form['admin_db'].'` wurde angelegt und verifiziert';}
            elseif($form['admin_driver']==='pgsql'){createPgsqlDatabase($adminServer,$form['admin_db']);$adminBase=new EnterprisePgsqlPdo($form['admin_host'],(int)$form['admin_port'],$form['admin_db'],$form['admin_username'],$adminPassword,'public');createPgsqlSchema($adminBase,$form['admin_schema']);$adminPdo=new EnterprisePgsqlPdo($form['admin_host'],(int)$form['admin_port'],$form['admin_db'],$form['admin_username'],$adminPassword,$form['admin_schema']);$results[]='✔ PostgreSQL-Administrationsspeicher `'.$form['admin_db'].' / '.$form['admin_schema'].'` wurde angelegt und verifiziert';}
            elseif($form['admin_driver']==='mssql'){createMssqlDatabase($adminServer,$form['admin_db']);$adminPdo=new EnterpriseMssqlPdo($form['admin_host'],(int)$form['admin_port'],$form['admin_db'],$form['admin_username'],$adminPassword,true,false);$results[]='✔ MSSQL-Administrationsdatenbank `'.$form['admin_db'].'` wurde angelegt und verifiziert';}
            elseif($form['admin_driver']==='oracle'){if(!$adminPdo instanceof EnterpriseOraclePdo)throw new RuntimeException('Oracle-Administrationsschema ist nicht verbunden.');$results[]='✔ Oracle-XE-Administrationsschema `'.$form['admin_username'].'@'.$form['admin_oracle_service'].'` wurde verifiziert';}
            elseif($form['admin_driver']==='csv'){$results[]='✔ CSV-Administrationsspeicher `'.$form['admin_db'].'` wurde angelegt und verifiziert';}
            else{$path=sqliteDatabasePath($rootPath,$form['admin_sqlite_base'],$form['admin_db'],'den Administrationsspeicher');$adminPdo=new EnterpriseSqlitePdo($path);if((string)$adminPdo->query('PRAGMA integrity_check')->fetchColumn()!=='ok')throw new RuntimeException('SQLite-Integritätsprüfung des Administrationsspeichers ist fehlgeschlagen.');$results[]='✔ SQLite-Administrationsspeicher wurde angelegt und verifiziert: '.$path;}

            if($form['project_driver']==='mysql'){createDatabase($projectServer,$form['project_db']);$results[]='✔ Projektdatenbank `'.$form['project_db'].'` wurde angelegt und verifiziert';}
            elseif($form['project_driver']==='pgsql'){createPgsqlDatabase($projectServer,$form['project_db']);$projectBase=new EnterprisePgsqlPdo($form['project_host'],(int)$form['project_port'],$form['project_db'],$form['project_username'],$projectPassword,'public');createPgsqlSchema($projectBase,$form['project_schema']);$projectPdo=new EnterprisePgsqlPdo($form['project_host'],(int)$form['project_port'],$form['project_db'],$form['project_username'],$projectPassword,$form['project_schema']);$results[]='✔ PostgreSQL-Projektspeicher `'.$form['project_db'].' / '.$form['project_schema'].'` wurde angelegt und verifiziert';}
            elseif($form['project_driver']==='mssql'){createMssqlDatabase($projectServer,$form['project_db']);$projectPdo=new EnterpriseMssqlPdo($form['project_host'],(int)$form['project_port'],$form['project_db'],$form['project_username'],$projectPassword,true,false);$results[]='✔ MSSQL-Projektdatenbank `'.$form['project_db'].'` wurde angelegt und verifiziert';}
            elseif($form['project_driver']==='oracle'){if(!$projectPdo instanceof EnterpriseOraclePdo)throw new RuntimeException('Oracle-Projektschema ist nicht verbunden.');$results[]='✔ Oracle-XE-Projektschema `'.$form['project_username'].'@'.$form['project_oracle_service'].'` wurde verifiziert';}
            elseif(in_array($form['project_driver'],['csv','sqlite'],true)){$results[]='✔ Lokaler Projektdatenspeicher wird beim Installieren nach Projektregistrierung unter storage/projects/<storage_key>/ angelegt';}
        }

        if($action==='install'){
            // HF112: Vor dem normalen Migrationslauf den realen Basiszustand
            // pruefen. So kann ein alter Migrationseintrag niemals eine real
            // fehlende Tabelle wie `users` verdecken.
            $preGatePdo=$adminPdo instanceof PDO?$adminPdo:$adminServer;
            if(!$preGatePdo instanceof PDO)throw new RuntimeException('Administrationsspeicher konnte für das Schema-Gate nicht geöffnet werden.');
            if($form['admin_driver']==='mysql')$preGatePdo->exec('USE '.quoteIdentifier($form['admin_db']));
            $preSchemaGate=easyit_admin_schema_assert_or_repair($preGatePdo,__DIR__.'/schema/admin');
            if($preSchemaGate['repaired'])$results[]='✔ Admin-Basisschema vor Migrationslauf repariert: '.implode(', ',$preSchemaGate['applied']);

            if($form['admin_driver']==='mysql'){foreach(installSchema($adminServer,$form['admin_db'],__DIR__.'/schema/admin') as $file)$results[]='✔ Admin-Schema: '.$file;}
            elseif($form['admin_driver']==='pgsql'){if(!$adminPdo instanceof EnterprisePgsqlPdo)throw new RuntimeException('PostgreSQL-Administrationsspeicher konnte nicht initialisiert werden.');foreach(installSchemaPortable($adminPdo,__DIR__.'/schema/admin') as $file)$results[]='✔ PostgreSQL-Admin-Schema: '.$file;}
            elseif($form['admin_driver']==='mssql'){if(!$adminPdo instanceof EnterpriseMssqlPdo)throw new RuntimeException('MSSQL-Administrationsspeicher konnte nicht initialisiert werden.');foreach(installSchemaPortable($adminPdo,__DIR__.'/schema/admin') as $file)$results[]='✔ MSSQL-Admin-Schema: '.$file;$results[]='✔ MSSQL-Control-Plane wurde verifiziert';}
            elseif($form['admin_driver']==='oracle'){if(!$adminPdo instanceof EnterpriseOraclePdo)throw new RuntimeException('Oracle-Administrationsschema konnte nicht initialisiert werden.');foreach(installSchemaPortable($adminPdo,__DIR__.'/schema/admin') as $file)$results[]='✔ Oracle-XE-Admin-Schema: '.$file;$results[]='✔ Oracle-XE-Control-Plane wurde verifiziert';}
            elseif($form['admin_driver']==='csv'){foreach(installSchemaPortable($adminPdo,__DIR__.'/schema/admin') as $file)$results[]='✔ CSV-Admin-Schema: '.$file;$adminPdo->ensureAdminSchema();$results[]='✔ CSV-Control-Plane-Tabellen und Grunddaten wurden verifiziert';}
            else{foreach(installSchemaPortable($adminPdo,__DIR__.'/schema/admin') as $file)$results[]='✔ SQLite-Admin-Schema: '.$file;$results[]='✔ Native SQLite-Control-Plane wurde verifiziert';}

            $registryPdo=$adminPdo instanceof PDO?$adminPdo:$adminServer;
            if(!$registryPdo instanceof PDO)throw new RuntimeException('Administrationsspeicher konnte für die Projektregistrierung nicht geöffnet werden.');
            $adminSchemaGate=easyit_admin_schema_assert_or_repair($registryPdo,__DIR__.'/schema/admin');
            if($adminSchemaGate['repaired']){
                $results[]='✔ Administrationsschema wurde nachvalidiert und repariert: '.implode(', ',$adminSchemaGate['applied']);
            }else{
                $results[]='✔ Administrationsschema-Gate bestanden: alle Pflichttabellen vorhanden';
            }
            $registered=registerPreparedProject($registryPdo,$form['project_name'],$form['project_driver'],$form['project_db']);
            $projectEnv=[
                'PROJECT_DB_DRIVER'=>$form['project_driver'],'PROJECT_DB_HOST'=>$form['project_host'],'PROJECT_DB_PORT'=>$form['project_port'],
                'PROJECT_DB_MAINTENANCE_DATABASE'=>$form['project_driver']==='pgsql'?($form['project_maintenance_database']?:'postgres'):'',
                'PROJECT_DB_DATABASE'=>$form['project_db'],'PROJECT_DB_SCHEMA'=>$form['project_driver']==='pgsql'?$form['project_schema']:'',
                'PROJECT_DB_USERNAME'=>$form['project_username'],'PROJECT_DB_PASSWORD'=>$projectPassword,
                'PROJECT_DB_ORACLE_SERVICE'=>$form['project_driver']==='oracle'?($form['project_oracle_service']?:'XEPDB1'):'',
                'PROJECT_DB_CHARSET'=>$form['project_driver']==='oracle'?'AL32UTF8':($form['project_driver']==='pgsql'?'UTF8':($form['project_driver']==='mssql'?'UTF-8':'utf8mb4')),
                'PROJECT_DB_ENCRYPT'=>$form['project_driver']==='mssql'?'true':'','PROJECT_DB_TRUST_SERVER_CERTIFICATE'=>$form['project_driver']==='mssql'?'false':'',
            ];
            $projectRecord=['id'=>(int)$registered['id'],'name'=>(string)$registered['name'],'slug'=>(string)$registered['slug'],'storage_key'=>(string)$registered['storage_key'],'product_type'=>'dataform','database_driver'=>$form['project_driver'],'database_name'=>$form['project_db'],'status'=>'provisioning','created_at'=>date(DATE_ATOM)];
            enterprise_project_storage_provision($projectRecord,$projectEnv);
            if(in_array($form['project_driver'],['csv','sqlite'],true))$projectPdo=enterprise_project_store_create_for_project($projectEnv,$projectRecord);
            $results[]=$registered['created']?'✔ Erstes Projekt mit Storage-Key registriert: '.$registered['storage_key']:'✔ Projekt war bereits registriert; Storage-Key: '.$registered['storage_key'];

            if($form['project_driver']==='mysql'){foreach(installSchema($projectServer,$form['project_db'],__DIR__.'/schema/project') as $file)$results[]='✔ Projekt-Schema: '.$file;}
            elseif($form['project_driver']==='pgsql'){if(!$projectPdo instanceof EnterprisePgsqlPdo)throw new RuntimeException('PostgreSQL-Projektdatenspeicher konnte nicht initialisiert werden.');foreach(installSchemaPortable($projectPdo,__DIR__.'/schema/project') as $file)$results[]='✔ PostgreSQL-Projekt-Schema: '.$file;$sourceId=enterprise_project_store_ensure_default_pgsql_source($projectPdo,['PROJECT_DB_HOST'=>$form['project_host'],'PROJECT_DB_PORT'=>$form['project_port'],'PROJECT_DB_USERNAME'=>$form['project_username'],'PROJECT_DB_SCHEMA'=>$form['project_schema']],$form['project_db']);$results[]='✔ Projektinterne PostgreSQL-Datenquelle #'.$sourceId.' wurde registriert';$results[]='✔ DataForm-Metadaten und Anwendungsdaten können vollständig in PostgreSQL liegen';}
            elseif($form['project_driver']==='mssql'){if(!$projectPdo instanceof EnterpriseMssqlPdo)throw new RuntimeException('MSSQL-Projektdatenspeicher konnte nicht initialisiert werden.');foreach(installSchemaPortable($projectPdo,__DIR__.'/schema/project') as $file)$results[]='✔ MSSQL-Projekt-Schema: '.$file;$sourceId=enterprise_project_store_ensure_default_mssql_source($projectPdo,['PROJECT_DB_HOST'=>$form['project_host'],'PROJECT_DB_PORT'=>$form['project_port'],'PROJECT_DB_USERNAME'=>$form['project_username']],$form['project_db']);$results[]='✔ Projektinterne MSSQL-Datenquelle #'.$sourceId.' wurde registriert';$results[]='✔ DataForm-Metadaten und Anwendungsdaten können vollständig in MSSQL liegen';}
            elseif($form['project_driver']==='oracle'){if(!$projectPdo instanceof EnterpriseOraclePdo)throw new RuntimeException('Oracle-Projektschema konnte nicht initialisiert werden.');foreach(installSchemaPortable($projectPdo,__DIR__.'/schema/project') as $file)$results[]='✔ Oracle-XE-Projekt-Schema: '.$file;$sourceId=enterprise_project_store_ensure_default_oracle_source($projectPdo,['PROJECT_DB_HOST'=>$form['project_host'],'PROJECT_DB_PORT'=>$form['project_port'],'PROJECT_DB_ORACLE_SERVICE'=>$form['project_oracle_service'],'PROJECT_DB_USERNAME'=>$form['project_username']],$form['project_db']);$results[]='✔ Projektinterne Oracle-XE-Datenquelle #'.$sourceId.' wurde registriert';$results[]='✔ DataForm-Metadaten und Anwendungsdaten können vollständig in Oracle XE liegen';}
            elseif($form['project_driver']==='csv'){foreach(installSchemaPortable($projectPdo,__DIR__.'/schema/project') as $file)$results[]='✔ CSV-Projekt-Schema: '.$file;$sourceId=enterprise_project_store_ensure_default_csv_source_for_project($projectPdo,$projectEnv,$projectRecord);$results[]='✔ Projektinterne CSV-Datenquelle #'.$sourceId.' wurde registriert';}
            else{foreach(installSchemaPortable($projectPdo,__DIR__.'/schema/project') as $file)$results[]='✔ SQLite-Projekt-Schema: '.$file;$sourceId=enterprise_project_store_ensure_default_sqlite_source_for_project($projectPdo,$projectEnv,$projectRecord);$results[]='✔ Projektinterne SQLite-Datenquelle #'.$sourceId.' wurde registriert';$results[]='✔ DataForm-Metadaten und Anwendungsdaten können vollständig in der SQLite-Projektdatei liegen';}

            $registryPdo->prepare("UPDATE projects SET status='active' WHERE id=?")->execute([(int)$registered['id']]);$projectRecord['status']='active';enterprise_project_storage_provision($projectRecord,$projectEnv);

            if($form['admin_driver']==='csv')$adminUpdates=['ADMIN_DB_DRIVER'=>'csv','ADMIN_DB_CSV_BASE_PATH'=>$form['admin_csv_base'],'ADMIN_DB_SQLITE_BASE_PATH'=>'','ADMIN_DB_DATABASE'=>$form['admin_db'],'ADMIN_DB_HOST'=>'','ADMIN_DB_PORT'=>'','ADMIN_DB_USERNAME'=>'','ADMIN_DB_PASSWORD'=>''];
            elseif($form['admin_driver']==='sqlite')$adminUpdates=['ADMIN_DB_DRIVER'=>'sqlite','ADMIN_DB_CSV_BASE_PATH'=>'','ADMIN_DB_SQLITE_BASE_PATH'=>$form['admin_sqlite_base'],'ADMIN_DB_DATABASE'=>$form['admin_db'],'ADMIN_DB_HOST'=>'','ADMIN_DB_PORT'=>'','ADMIN_DB_USERNAME'=>'','ADMIN_DB_PASSWORD'=>''];
            else $adminUpdates=['ADMIN_DB_DRIVER'=>$form['admin_driver'],'ADMIN_DB_CSV_BASE_PATH'=>'','ADMIN_DB_SQLITE_BASE_PATH'=>'','ADMIN_DB_HOST'=>$form['admin_host'],'ADMIN_DB_PORT'=>$form['admin_port'],'ADMIN_DB_MAINTENANCE_DATABASE'=>$form['admin_driver']==='pgsql'?($form['admin_maintenance_database']?:'postgres'):'','ADMIN_DB_DATABASE'=>$form['admin_db'],'ADMIN_DB_SCHEMA'=>$form['admin_driver']==='pgsql'?$form['admin_schema']:'','ADMIN_DB_USERNAME'=>$form['admin_username'],'ADMIN_DB_PASSWORD'=>$adminPassword,'ADMIN_DB_ORACLE_SERVICE'=>$form['admin_driver']==='oracle'?($form['admin_oracle_service']?:'XEPDB1'):'','ADMIN_DB_CHARSET'=>$form['admin_driver']==='oracle'?'AL32UTF8':($form['admin_driver']==='pgsql'?'UTF8':($form['admin_driver']==='mssql'?'UTF-8':'utf8mb4')),'ADMIN_DB_ENCRYPT'=>$form['admin_driver']==='mssql'?'true':'','ADMIN_DB_TRUST_SERVER_CERTIFICATE'=>$form['admin_driver']==='mssql'?'false':''];

            $projectUpdates=$projectEnv+['PROJECT_STORAGE_KEY'=>(string)$registered['storage_key']];
            updateEnv($envPath,$adminUpdates+$projectUpdates+['CONTEXT_PROJECT_NAME'=>$form['project_name']]);removeEnvKeys($envPath,['PROJECT_DB_CSV_BASE_PATH','PROJECT_DB_SQLITE_BASE_PATH']);$secretState=ensureDataFormSecretKey($envPath);$results[]=$secretState['created']?'✔ DATAFORM_APP_KEY wurde automatisch erzeugt':'✔ DataForm-Secret-Schlüssel ist vorhanden ('.$secretState['source'].')';$results[]='✔ DataForm5-Core/.env wurde aktualisiert';$_SESSION['easyit_db_setup_complete']=true;
        }
    }catch(Throwable $e){$messages[]=$e->getMessage();if($e instanceof PDOException&&isset($e->errorInfo[1]))$messages[]='Datenbank-Fehlernummer: '.(string)$e->errorInfo[1];}
}

ob_start();
?>
<section class="hero"><span class="badge">Setup · Schritt 6</span><h1>Datenbanken vorbereiten</h1><p>Administrationsspeicher und Projektdatenspeicher werden unabhängig voneinander gewählt. In dieser PHP-Laufzeit verfügbar: <?=db_e(implode(', ',array_values($driverOptions)))?>. Datenbanktypen mit fehlendem PDO-Treiber werden nicht angeboten.</p></section>
<?php foreach ($messages as $message): ?><div class="notice error" role="alert"><?= db_e($message) ?></div><?php endforeach; ?>
<?php if ($results): ?><section class="card"><h2>Ergebnis</h2><ul class="result-list"><?php foreach ($results as $result): ?><li><?= db_e($result) ?></li><?php endforeach; ?></ul></section><?php endif; ?>
<?php if ($diagnostics): ?><section class="card"><h2>Verbundener Datenbankserver</h2><dl class="status-list"><?php foreach ($diagnostics as $label => $value): ?><div><dt><?= db_e($label) ?></dt><dd><code><?= db_e($value) ?></code></dd></div><?php endforeach; ?></dl></section><?php endif; ?>
<section class="card">
<form method="post" class="form-grid" autocomplete="off">
<input type="hidden" name="csrf_token" value="<?= db_e((string)$_SESSION['easyit_csrf']) ?>">
<h2 class="form-span">1. Administrationsspeicher</h2>
<label>Treiber<select name="admin_driver" id="admin_driver"><?php foreach($driverOptions as $driver=>$label):?><option value="<?=db_e($driver)?>" <?= $form['admin_driver']===$driver?'selected':'' ?>><?=db_e($label)?></option><?php endforeach;?></select></label>
<label>Administrationsdatenbank<input name="admin_db" value="<?= db_e($form['admin_db']) ?>" required pattern="[A-Za-z][A-Za-z0-9_]{1,62}"></label>
	<label data-admin-pgsql>PostgreSQL-Schema<input name="admin_schema" value="<?= db_e($form['admin_schema']) ?>" required pattern="[A-Za-z][A-Za-z0-9_]{0,62}"><small>Datenbank und Schema bilden gemeinsam den Administrationsspeicher.</small></label>
<label data-admin-csv>CSV-Basispfad<input name="admin_csv_base" value="<?= db_e($form['admin_csv_base']) ?>"><small>z. B. storage/admin-csv</small></label>
<p class="form-span muted" data-admin-csv>Benutzer, Rollen, Projekte, Audit, Lizenzen, Module und Migrationen werden als <code>|</code>-getrennte CSV-Dateien gespeichert.</p>
<label data-admin-sqlite>SQLite-Basispfad<input name="admin_sqlite_base" value="<?= db_e($form['admin_sqlite_base']) ?>"><small>z. B. storage/admin-sqlite</small></label>
<p class="form-span muted" data-admin-sqlite>Die gesamte Enterprise-Control-Plane wird in <code>&lt;Basis&gt;/&lt;Name&gt;.sqlite</code> gespeichert.</p>

<h2 class="form-span">2. Projektdatenspeicher</h2>
<label>Treiber<select name="project_driver" id="project_driver"><?php foreach($driverOptions as $driver=>$label):?><option value="<?=db_e($driver)?>" <?= $form['project_driver']===$driver?'selected':'' ?>><?=db_e($label)?></option><?php endforeach;?></select></label>
<label>Datenbank-/Speichername<input name="project_db" value="<?= db_e($form['project_db']) ?>" required pattern="[A-Za-z][A-Za-z0-9_]{1,62}"></label>
	<label data-project-pgsql>PostgreSQL-Schema<input name="project_schema" value="<?= db_e($form['project_schema']) ?>" required pattern="[A-Za-z][A-Za-z0-9_]{0,62}"><small>Datenbank und Schema bilden gemeinsam den Projektspeicher.</small></label>
<p class="form-span muted" data-project-csv>CSV wird automatisch unter <code>storage/projects/&lt;storage_key&gt;/csv/</code> angelegt. Jede Tabelle verwendet <code>|</code> und die verwaltete Pflicht-ID <code>id</code>.</p>
<p class="form-span muted" data-project-sqlite>SQLite wird automatisch als <code>storage/projects/&lt;storage_key&gt;/sqlite/database.sqlite</code> angelegt.</p>

<div class="form-span" data-admin-sql><h3>Serverzugang Administrationsspeicher</h3><p>Für MariaDB/MySQL, PostgreSQL oder Oracle XE. Oracle XE verwendet standardmäßig Port 1521 und Service XEPDB1.</p></div>
<label data-admin-sql>Host<input name="admin_host" value="<?= db_e($form['admin_host']) ?>"></label>
<label data-admin-sql>Port<input name="admin_port" value="<?= db_e($form['admin_port']) ?>" inputmode="numeric"></label><label data-admin-pgsql>PostgreSQL-Wartungsdatenbank<input name="admin_maintenance_database" value="<?= db_e($form['admin_maintenance_database']) ?>"><small>Standard: postgres</small></label><label data-admin-sql>Oracle Service/PDB<input name="admin_oracle_service" value="<?= db_e($form['admin_oracle_service']) ?>"><small>Nur Oracle XE, Standard XEPDB1</small></label>
<label data-admin-sql>Benutzer<input name="admin_username" value="<?= db_e($form['admin_username']) ?>"></label>
<label data-admin-sql>Passwort<input type="password" name="admin_password" value="" autocomplete="new-password"></label>
<div class="form-span" data-project-sql><h3>Serverzugang Projektdatenspeicher</h3><p>Unabhängig vom Administrationsspeicher konfigurierbar.</p></div>
<label data-project-sql>Host<input name="project_host" value="<?= db_e($form['project_host']) ?>"></label>
<label data-project-sql>Port<input name="project_port" value="<?= db_e($form['project_port']) ?>" inputmode="numeric"></label><label data-project-pgsql>PostgreSQL-Wartungsdatenbank<input name="project_maintenance_database" value="<?= db_e($form['project_maintenance_database']) ?>"><small>Standard: postgres</small></label><label data-project-sql>Oracle Service/PDB<input name="project_oracle_service" value="<?= db_e($form['project_oracle_service']) ?>"><small>Nur Oracle XE, Standard XEPDB1</small></label>
<label data-project-sql>Benutzer<input name="project_username" value="<?= db_e($form['project_username']) ?>"></label>
<label data-project-sql>Passwort<input type="password" name="project_password" value="" autocomplete="new-password"></label>

<h2 class="form-span">3. Erstes Projekt</h2>
<label class="form-span">Projektname<input name="project_name" value="<?= db_e($form['project_name']) ?>" required></label>
<div class="form-span button-row">
<button class="button secondary" <?= easyit_button_attributes('suchen', 'setup_db_test') ?> name="action" value="test" type="submit">1. Speicher prüfen</button>
<button class="button secondary" <?= easyit_button_attributes('neu', 'setup_db_create') ?> name="action" value="create" type="submit">2. Speicher anlegen und prüfen</button>
<button class="button" <?= easyit_button_attributes('bestaetigen', 'setup_db_install') ?> name="action" value="install" type="submit">3. Schemas installieren und .env speichern</button>
</div>
</form>
<div class="notice"><strong>Phase 4 Oracle XE:</strong> Administrations- und Projektspeicher sind unabhängig als MySQL, CSV, SQLite, PostgreSQL oder Oracle XE wählbar. PostgreSQL benötigt <code>pdo_pgsql</code>. Es wird immer eine Kombination aus Datenbank und Schema konfiguriert; beide werden beim Anlegen erzeugt und anschließend verifiziert, sofern der angegebene Benutzer die notwendigen Rechte besitzt.</div>
</section>
<script>
(function(){
 const a=document.getElementById('admin_driver'),p=document.getElementById('project_driver');
 function sync(){const ad=a?a.value:'mysql',pd=p?p.value:'mysql',ac=ad==='csv',pc=pd==='csv',as=ad==='sqlite',ps=pd==='sqlite',adminSql=ad==='mysql'||ad==='pgsql'||ad==='oracle'||ad==='mssql',projectSql=pd==='mysql'||pd==='pgsql'||pd==='oracle'||pd==='mssql';document.querySelectorAll('[data-admin-csv]').forEach(x=>x.style.display=ac?'':'none');document.querySelectorAll('[data-project-csv]').forEach(x=>x.style.display=pc?'':'none');document.querySelectorAll('[data-admin-sqlite]').forEach(x=>x.style.display=as?'':'none');document.querySelectorAll('[data-project-sqlite]').forEach(x=>x.style.display=ps?'':'none');document.querySelectorAll('[data-admin-sql]').forEach(x=>x.style.display=adminSql?'':'none');document.querySelectorAll('[data-project-sql]').forEach(x=>x.style.display=projectSql?'':'none');document.querySelectorAll('[data-admin-pgsql]').forEach(x=>x.style.display=ad==='pgsql'?'':'none');document.querySelectorAll('[data-project-pgsql]').forEach(x=>x.style.display=pd==='pgsql'?'':'none');if(ad==='pgsql')document.querySelector('[name=admin_port]').value='5432';if(pd==='pgsql')document.querySelector('[name=project_port]').value='5432';if(ad==='oracle')document.querySelector('[name=admin_port]').value='1521';if(pd==='oracle')document.querySelector('[name=project_port]').value='1521';}
 a&&a.addEventListener('change',sync);p&&p.addEventListener('change',sync);sync();
})();
</script>
<nav class="page-actions"><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="local-config.php">← Zurück zu Schritt 5</a><a class="button" <?= easyit_button_attributes('weiter') ?> href="admin.php">Administrator anlegen →</a></nav>
<?php
$content = ob_get_clean();
render_page(['title'=>'Datenbank-Assistent','active'=>'setup','base'=>'../','content'=>$content,'help'=>[
'title'=>'Datenbank-Assistent','location'=>'Setup → Schritt 6 → Speicher','short'=>'Administrations- und Projektdatenspeicher können unabhängig als MariaDB/MySQL, CSV, SQLite, PostgreSQL oder Oracle XE betrieben werden.','goal'=>'Control Plane und Projektdaten persistent und getrennt einrichten.','next'=>'Speicher prüfen, anlegen und anschließend die Schemas installieren.','steps'=>['Treiber für Administrationsspeicher wählen.','Treiber für Projektdatenspeicher wählen.','Bei CSV oder SQLite den jeweiligen Basisordner festlegen.','Bei MySQL/PostgreSQL den jeweiligen Serverzugang angeben.','Schemas installieren und .env speichern.'],'examples'=>['PostgreSQL/PostgreSQL: 127.0.0.1:5432','CSV/PostgreSQL','MySQL/SQLite'],'tips'=>['CSV verwendet | als Trennzeichen.','SQLite verwendet native .sqlite-Dateien mit Fremdschlüsseln.','PostgreSQL verwendet pdo_pgsql; Admin- und Projektzugang sind getrennt konfigurierbar.'],'duration'=>'ca. 2–5 Minuten']]);
