<?php
declare(strict_types=1);

require_once __DIR__ . '/EnterpriseCsvPdo.php';
require_once __DIR__ . '/EnterpriseSqlitePdo.php';
require_once __DIR__ . '/EnterprisePgsqlPdo.php';
require_once __DIR__ . '/EnterpriseOraclePdo.php';
require_once __DIR__ . '/EnterpriseMssqlPdo.php';
require_once __DIR__ . '/project_store.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/calendar_project.php';
require_once __DIR__ . '/product_modules.php';
require_once dirname(__DIR__) . '/licensing/LicenseConfigStore.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax','path'=>'/']);
    session_start();
}

function enterprise_env(string $path): array {
    $values=[];
    if (!is_file($path) || !is_readable($path)) return $values;
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line=trim($line);
        if ($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
        [$key,$value]=explode('=',$line,2);
        $values[trim($key)] = trim(trim($value), "\"'");
    }
    return $values;
}
/**
 * HF107: easyit_license ist die kanonische Administrationsdatenbank.
 * Der fruehere Default easyit_admin wird nur noch als Legacy-Marker erkannt
 * und beim ersten Zugriff gezielt in der .env korrigiert. Individuell
 * konfigurierte andere Datenbanknamen bleiben unveraendert.
 */
function enterprise_admin_database(array $env): string {
    $database=trim((string)($env['ADMIN_DB_DATABASE'] ?? ''));
    if ($database==='' || strcasecmp($database,'easyit_admin')===0) return 'easyit_license';
    return $database;
}
function enterprise_repair_legacy_admin_database_env(string $envPath): void {
    if (!is_file($envPath) || !is_readable($envPath) || !is_writable($envPath)) return;
    $content=(string)file_get_contents($envPath);
    if ($content==='') return;
    $changed=preg_replace('/^ADMIN_DB_DATABASE\s*=\s*([\"\']?)easyit_admin\1\s*$/mi','ADMIN_DB_DATABASE=easyit_license',$content,1,$count);
    if ($count<1 || !is_string($changed) || $changed===$content) return;
    $tmp=$envPath.'.hf107.tmp';
    if (@file_put_contents($tmp,$changed,LOCK_EX)===false) return;
    @chmod($tmp,fileperms($envPath)&0777);
    if (!@rename($tmp,$envPath)) @unlink($tmp);
}
function enterprise_pdo(): PDO {
    static $pdo=null;
    if ($pdo instanceof PDO) return $pdo;
    $envPath=dirname(__DIR__,2).'/DataForm5-Core/.env';
    enterprise_repair_legacy_admin_database_env($envPath);
    $env=enterprise_env($envPath);
    $driver=strtolower(trim((string)($env['ADMIN_DB_DRIVER'] ?? 'mysql')));
    $database=enterprise_admin_database($env);
    if ($driver==='csv') {
        $database=enterprise_admin_database($env);
        if ($database==='') throw new RuntimeException('Konfiguration ADMIN_DB_DATABASE fehlt. Bitte Installer abschließen.');
        $base=trim((string)($env['ADMIN_DB_CSV_BASE_PATH'] ?? ''));
        if ($base==='') $base=dirname(__DIR__,2).'/storage/admin-csv';
        elseif (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~',$base)) $base=dirname(__DIR__,2).'/'.ltrim(str_replace('\\','/',$base),'/');
        $pdo=new EnterpriseCsvPdo($base,$database);
        return $pdo;
    }
    if ($driver==='sqlite') {
        $database=enterprise_admin_database($env);
        if ($database==='' || preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$database)!==1) throw new RuntimeException('Konfiguration ADMIN_DB_DATABASE ist für SQLite ungültig. Bitte Installer abschließen.');
        $base=trim((string)($env['ADMIN_DB_SQLITE_BASE_PATH'] ?? 'storage/admin-sqlite'));
        if ($base==='') $base='storage/admin-sqlite';
        $base=str_replace('\\','/',$base);
        if (!preg_match('~^(?:[A-Za-z]:/|/)~',$base)) $base=dirname(__DIR__,2).'/'.ltrim($base,'/');
        $pdo=new EnterpriseSqlitePdo(rtrim($base,'/').'/'.$database.'.sqlite');
        return $pdo;
    }
    if ($driver==='mssql') {
        return new EnterpriseMssqlPdo((string)($env['ADMIN_DB_HOST']??'127.0.0.1'),(int)($env['ADMIN_DB_PORT']??1433),$database,(string)($env['ADMIN_DB_USERNAME']??''),(string)($env['ADMIN_DB_PASSWORD']??''),(string)($env['ADMIN_DB_ENCRYPT']??'1')!=='0',(string)($env['ADMIN_DB_TRUST_SERVER_CERTIFICATE']??'0')==='1');
    }
    if ($driver==='oracle') {
        foreach (['ADMIN_DB_HOST','ADMIN_DB_PORT','ADMIN_DB_USERNAME'] as $key) { if (empty($env[$key])) throw new RuntimeException("Konfiguration {$key} fehlt. Bitte Installer abschließen."); }
        $service=trim((string)($env['ADMIN_DB_ORACLE_SERVICE'] ?? 'XEPDB1')) ?: 'XEPDB1';
        $pdo=new EnterpriseOraclePdo((string)$env['ADMIN_DB_HOST'],(int)($env['ADMIN_DB_PORT']??1521),$service,(string)$env['ADMIN_DB_USERNAME'],(string)($env['ADMIN_DB_PASSWORD']??''));
        return $pdo;
    }
    if (in_array($driver,['pgsql','postgres','postgresql'],true)) {
        foreach (['ADMIN_DB_HOST','ADMIN_DB_PORT','ADMIN_DB_USERNAME'] as $key) {
            if (empty($env[$key])) throw new RuntimeException("Konfiguration {$key} fehlt. Bitte Installer abschließen.");
        }
        $host=(string)$env['ADMIN_DB_HOST'];
        $port=(int)$env['ADMIN_DB_PORT'];
        $database=enterprise_admin_database($env);
        $username=(string)$env['ADMIN_DB_USERNAME'];
        $password=(string)($env['ADMIN_DB_PASSWORD']??'');
        $schema=trim((string)($env['ADMIN_DB_SCHEMA']??'public')) ?: 'public';
        try {
            $pdo=new EnterprisePgsqlPdo($host,$port,$database,$username,$password,$schema);
            return $pdo;
        } catch (PDOException $e) {
            // Eine fehlende Ziel-Datenbank ist bei PostgreSQL kein Grund für
            // einen ungefangenen Fatal Error. Über die Maintenance-Datenbank
            // lässt sich eindeutig unterscheiden, ob die Admin-Datenbank
            // tatsächlich fehlt oder die Verbindung aus anderem Grund scheitert.
            try {
                $maintenance=trim((string)($env['ADMIN_DB_MAINTENANCE_DATABASE']??'postgres')) ?: 'postgres';
                $server=new EnterprisePgsqlPdo($host,$port,$maintenance,$username,$password,'public');
                $st=$server->prepare('SELECT 1 FROM pg_database WHERE datname=?');
                $st->execute([$database]);
                if ($st->fetchColumn()===false) {
                    throw new RuntimeException(
                        'Die PostgreSQL-Administrationsdatenbank `'.$database.'` existiert nicht. Führen Sie das Setup bzw. den Datenbank-Assistenten aus, bevor Projekte verwaltet oder neu aufgebaut werden.',
                        0,
                        $e
                    );
                }
            } catch (RuntimeException $diagnostic) {
                throw $diagnostic;
            } catch (Throwable) {
                // Falls selbst die Maintenance-Verbindung nicht möglich ist,
                // bleibt die ursprüngliche PDO-Ursache maßgeblich.
            }
            throw new RuntimeException('PostgreSQL-Administrationsspeicher konnte nicht geöffnet werden: '.$e->getMessage(),0,$e);
        }
    }
    foreach (['ADMIN_DB_HOST','ADMIN_DB_PORT','ADMIN_DB_DATABASE','ADMIN_DB_USERNAME'] as $key) {
        if (empty($env[$key])) throw new RuntimeException("Konfiguration {$key} fehlt. Bitte Installer abschließen.");
    }
    $pdo=new PDO(
        'mysql:host='.$env['ADMIN_DB_HOST'].';port='.(int)$env['ADMIN_DB_PORT'].';dbname='.$database.';charset=utf8mb4',
        $env['ADMIN_DB_USERNAME'], $env['ADMIN_DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    return $pdo;
}

function enterprise_upgrade(PDO $pdo): void {
    if ($pdo instanceof EnterpriseSqlitePdo) { enterprise_upgrade_sqlite($pdo); return; }
    $cols=$pdo->query("SHOW COLUMNS FROM projects LIKE 'product_type'")->fetch();
    if (!$cols) $pdo->exec("ALTER TABLE projects ADD product_type VARCHAR(60) NOT NULL DEFAULT 'dataform' AFTER slug");
    $cols=$pdo->query("SHOW COLUMNS FROM projects LIKE 'description'")->fetch();
    if (!$cols) $pdo->exec("ALTER TABLE projects ADD description TEXT NULL AFTER database_name");
    $pdo->exec("CREATE TABLE IF NOT EXISTS installed_products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, product_key VARCHAR(80) NOT NULL UNIQUE, label VARCHAR(120) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'available', version VARCHAR(40) NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_module_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_name VARCHAR(190) NOT NULL,scope_type VARCHAR(30) NOT NULL DEFAULT 'enterprise',scope_id VARCHAR(190) NOT NULL DEFAULT 'global',config_key VARCHAR(190) NOT NULL,config_value_json LONGTEXT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_module_setting(module_name,scope_type,scope_id,config_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_module_secrets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_name VARCHAR(190) NOT NULL,scope_type VARCHAR(30) NOT NULL DEFAULT 'enterprise',scope_id VARCHAR(190) NOT NULL DEFAULT 'global',config_key VARCHAR(190) NOT NULL,ciphertext LONGTEXT NOT NULL,nonce VARCHAR(255) NOT NULL,tag VARCHAR(255) NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_module_secret(module_name,scope_type,scope_id,config_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS capabilities (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(190) NOT NULL UNIQUE,module_name VARCHAR(190) NULL,label VARCHAR(190) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_cap_module(module_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS role_capabilities (role_id BIGINT UNSIGNED NOT NULL,capability_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(role_id,capability_id),CONSTRAINT fk_rc_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,CONSTRAINT fk_rc_cap FOREIGN KEY(capability_id) REFERENCES capabilities(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO roles(name,label) VALUES ('superadmin','Superadministrator')");
    if ($pdo instanceof EnterpriseOraclePdo) { $capSeed=$pdo->prepare('INSERT IGNORE INTO capabilities(name,module_name,label) VALUES (?,?,?)'); foreach ([['projects.view','core','Projekte anzeigen'],['projects.create','core','Projekte anlegen'],['projects.update','core','Projekte bearbeiten'],['projects.delete','core','Projekte löschen'],['projects.backup','core','Projekte sichern'],['projects.restore','core','Projekte wiederherstellen'],['modules.view','core','Module anzeigen'],['modules.manage','core','Module verwalten'],['modules.configure','core','Module konfigurieren'],['permissions.manage','core','Berechtigungen verwalten'],['background.view','core','Hintergrundprozesse anzeigen'],['background.manage','core','Hintergrundprozesse verwalten'],['monitoring.view','core','Monitoring anzeigen'],['monitoring.manage','core','Monitoring verwalten'],['cluster.view','core','Cluster anzeigen'],['cluster.manage','core','Cluster verwalten'],['storage.view','core','Storage anzeigen'],['storage.manage','core','Storage verwalten'],['replication.view','core','Replikation anzeigen'],['replication.manage','core','Replikation verwalten'],['cluster.security','core','Cluster-Sicherheit verwalten'],['developer.view','core','Developer Mode anzeigen']] as $capRow) $capSeed->execute($capRow); } else { $pdo->exec("INSERT IGNORE INTO capabilities(name,module_name,label) VALUES ('projects.view','core','Projekte anzeigen'),('projects.create','core','Projekte anlegen'),('projects.update','core','Projekte bearbeiten'),('projects.delete','core','Projekte löschen'),('projects.backup','core','Projekte sichern'),('projects.restore','core','Projekte wiederherstellen'),('modules.view','core','Module anzeigen'),('modules.manage','core','Module verwalten'),('modules.configure','core','Module konfigurieren'),('permissions.manage','core','Berechtigungen verwalten'),('background.view','core','Hintergrundprozesse anzeigen'),('background.manage','core','Hintergrundprozesse verwalten'),('monitoring.view','core','Monitoring anzeigen'),('monitoring.manage','core','Monitoring verwalten'),('cluster.view','core','Cluster anzeigen'),('cluster.manage','core','Cluster verwalten'),('storage.view','core','Storage anzeigen'),('storage.manage','core','Storage verwalten'),('replication.view','core','Replikation anzeigen'),('replication.manage','core','Replikation verwalten'),('cluster.security','core','Cluster-Sicherheit verwalten'),('developer.view','core','Developer Mode anzeigen')"); }
    if ($pdo instanceof EnterpriseOraclePdo) {
        foreach (['admin','superadmin'] as $roleName) {
            $roleSql="INSERT INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name='".$roleName."' AND NOT EXISTS (SELECT 1 FROM role_capabilities rc WHERE rc.role_id=r.id AND rc.capability_id=c.id)";
            $pdo->exec($roleSql);
        }
    } else {
        $pdo->exec("INSERT IGNORE INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name='admin'");
        $pdo->exec("INSERT IGNORE INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name='superadmin'");
    }
    enterprise_ensure_superadmin_assignment($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_module_migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_name VARCHAR(190) NOT NULL, migration_version VARCHAR(190) NOT NULL, checksum CHAR(64) NOT NULL, batch INT UNSIGNED NOT NULL DEFAULT 1, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_module_migration(module_name,migration_version), KEY idx_module_migration_batch(module_name,batch)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // HF87 Phase 4.1: customer_key is a schema-upgrade column.  Do not put its
    // index into CREATE TABLE here: PostgreSQL translates MySQL inline indexes
    // into a second CREATE INDEX statement.  On an existing legacy table the
    // CREATE TABLE IF NOT EXISTS is a no-op, but that second statement would run
    // before the column migration and fail with SQLSTATE[42703].  SQLite has the
    // same ordering hazard when an old table is followed by CREATE INDEX.
    $pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_licenses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, license_key VARCHAR(190) NOT NULL UNIQUE, customer_key VARCHAR(320) NULL, holder VARCHAR(190) NOT NULL, edition VARCHAR(80) NOT NULL DEFAULT 'community', capabilities_json LONGTEXT NULL, products_json LONGTEXT NULL, valid_from DATE NULL, expires_at DATE NULL, grace_days INT UNSIGNED NOT NULL DEFAULT 0, enabled TINYINT(1) NOT NULL DEFAULT 1, is_primary TINYINT(1) NOT NULL DEFAULT 0, metadata_json LONGTEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_license_enabled(enabled), INDEX idx_license_primary(is_primary), INDEX idx_license_expires(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // The migration adds/backfills customer_key first and creates its index only
    // afterwards.  This order is portable across MySQL/MariaDB, PostgreSQL,
    // SQLite, Oracle, MSSQL and the CSV compatibility adapter.
    enterprise_upgrade_license_customer_key($pdo);
    enterprise_project_storage_upgrade($pdo, enterprise_env(dirname(__DIR__,2).'/DataForm5-Core/.env'));
    $stmt=$pdo->prepare("INSERT IGNORE INTO installed_products(product_key,label,status,version) VALUES (?,?,?,?)");
    foreach ([['dataform','DataForm','available','5-dev'],['calendar','easyITCalendar','available','2.0'],['dialog','Dialog','planned',null],['nachhilfe','Nachhilfe','planned',null],['csv-engine','CSV-Engine','available','phase1'],['sqlite-engine','SQLite','available','phase2'],['pgsql-engine','PostgreSQL','available','phase3'],['oracle-engine','Oracle XE','available','phase4'],['mssql-engine','Microsoft SQL Server','available','phase5']] as $row) $stmt->execute($row);
}
function enterprise_upgrade_sqlite(PDO $pdo): void {
    $ddl=[
        "CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,label TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,email TEXT NULL UNIQUE,password_hash TEXT NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER NOT NULL,role_id INTEGER NOT NULL,PRIMARY KEY(user_id,role_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE)",
        "CREATE TABLE IF NOT EXISTS projects (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT NOT NULL UNIQUE,storage_key TEXT NULL,product_type TEXT NOT NULL DEFAULT 'dataform',database_driver TEXT NOT NULL DEFAULT 'mysql',database_name TEXT NOT NULL,description TEXT NULL,status TEXT NOT NULL DEFAULT 'active',created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,action TEXT NOT NULL,object_type TEXT NULL,object_id TEXT NULL,context_json TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)",
        "CREATE TABLE IF NOT EXISTS installed_products (id INTEGER PRIMARY KEY AUTOINCREMENT,product_key TEXT NOT NULL UNIQUE,label TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'available',version TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS enterprise_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT,module_name TEXT NOT NULL,scope_type TEXT NOT NULL DEFAULT 'enterprise',scope_id TEXT NOT NULL DEFAULT 'global',config_key TEXT NOT NULL,config_value_json TEXT NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,scope_type,scope_id,config_key))",
        "CREATE TABLE IF NOT EXISTS enterprise_module_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT,module_name TEXT NOT NULL,scope_type TEXT NOT NULL DEFAULT 'enterprise',scope_id TEXT NOT NULL DEFAULT 'global',config_key TEXT NOT NULL,ciphertext TEXT NOT NULL,nonce TEXT NOT NULL,tag TEXT NOT NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,scope_type,scope_id,config_key))",
        "CREATE TABLE IF NOT EXISTS capabilities (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,module_name TEXT NULL,label TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
        "CREATE TABLE IF NOT EXISTS role_capabilities (role_id INTEGER NOT NULL,capability_id INTEGER NOT NULL,PRIMARY KEY(role_id,capability_id),FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,FOREIGN KEY(capability_id) REFERENCES capabilities(id) ON DELETE CASCADE)",
        "CREATE TABLE IF NOT EXISTS enterprise_module_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT,module_name TEXT NOT NULL,migration_version TEXT NOT NULL,checksum TEXT NOT NULL,batch INTEGER NOT NULL DEFAULT 1,applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,migration_version))",
        "CREATE TABLE IF NOT EXISTS enterprise_licenses (id INTEGER PRIMARY KEY AUTOINCREMENT,license_key TEXT NOT NULL UNIQUE,customer_key TEXT NULL,holder TEXT NOT NULL,edition TEXT NOT NULL DEFAULT 'community',capabilities_json TEXT NULL,products_json TEXT NULL,valid_from TEXT NULL,expires_at TEXT NULL,grace_days INTEGER NOT NULL DEFAULT 0,enabled INTEGER NOT NULL DEFAULT 1,is_primary INTEGER NOT NULL DEFAULT 0,metadata_json TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)"
    ];
    foreach($ddl as $sql)$pdo->exec($sql);
    foreach([
        "CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_cap_module ON capabilities(module_name)",
        "CREATE INDEX IF NOT EXISTS idx_module_migration_batch ON enterprise_module_migrations(module_name,batch)",
        // customer_key index is intentionally created by
        // enterprise_upgrade_license_customer_key() after the legacy column has
        // been added.  Creating it here breaks existing SQLite databases.
        "CREATE INDEX IF NOT EXISTS idx_license_enabled ON enterprise_licenses(enabled)",
        "CREATE INDEX IF NOT EXISTS idx_license_primary ON enterprise_licenses(is_primary)",
        "CREATE INDEX IF NOT EXISTS idx_license_expires ON enterprise_licenses(expires_at)"
    ] as $sql)$pdo->exec($sql);
    enterprise_upgrade_license_customer_key($pdo);
    enterprise_project_storage_upgrade($pdo, enterprise_env(dirname(__DIR__,2).'/DataForm5-Core/.env'));
    $pdo->exec("INSERT OR IGNORE INTO roles(name,label) VALUES ('admin','Administrator'),('editor','Bearbeiter'),('viewer','Leser'),('superadmin','Superadministrator')");
    $caps=[['projects.view','core','Projekte anzeigen'],['projects.create','core','Projekte anlegen'],['projects.update','core','Projekte bearbeiten'],['projects.delete','core','Projekte löschen'],['projects.backup','core','Projekte sichern'],['projects.restore','core','Projekte wiederherstellen'],['modules.view','core','Module anzeigen'],['modules.manage','core','Module verwalten'],['modules.configure','core','Module konfigurieren'],['permissions.manage','core','Berechtigungen verwalten'],['background.view','core','Hintergrundprozesse anzeigen'],['background.manage','core','Hintergrundprozesse verwalten'],['monitoring.view','core','Monitoring anzeigen'],['monitoring.manage','core','Monitoring verwalten'],['cluster.view','core','Cluster anzeigen'],['cluster.manage','core','Cluster verwalten'],['storage.view','core','Storage anzeigen'],['storage.manage','core','Storage verwalten'],['replication.view','core','Replikation anzeigen'],['replication.manage','core','Replikation verwalten'],['cluster.security','core','Cluster-Sicherheit verwalten'],['developer.view','core','Developer Mode anzeigen']];
    $st=$pdo->prepare("INSERT OR IGNORE INTO capabilities(name,module_name,label) VALUES (?,?,?)"); foreach($caps as $cap)$st->execute($cap);
    $pdo->exec("INSERT OR IGNORE INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name IN ('admin','superadmin')");
    enterprise_ensure_superadmin_assignment($pdo);
    $st=$pdo->prepare("INSERT OR IGNORE INTO installed_products(product_key,label,status,version) VALUES (?,?,?,?)");
    foreach ([['dataform','DataForm','available','5-dev'],['calendar','easyITCalendar','available','2.0'],['dialog','Dialog','planned',null],['nachhilfe','Nachhilfe','planned',null],['csv-engine','CSV-Engine','available','phase1'],['sqlite-engine','SQLite','available','phase2'],['pgsql-engine','PostgreSQL','available','phase3'],['oracle-engine','Oracle XE','available','phase4'],['mssql-engine','Microsoft SQL Server','available','phase5']] as $row) $st->execute($row);
}

/** HF87 Phase 2: ensure local Core licenses are bound to the unique customer e-mail key. */
function enterprise_upgrade_license_customer_key(PDO $pdo): void {
    try {
        $has=false;
        if ($pdo instanceof EnterpriseSqlitePdo) {
            $rows=$pdo->query('PRAGMA table_info(enterprise_licenses)')->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as $row){if(strtolower((string)($row['name']??''))==='customer_key'){$has=true;break;}}
        } else {
            $col=$pdo->query("SHOW COLUMNS FROM enterprise_licenses LIKE 'customer_key'")->fetch(PDO::FETCH_ASSOC);
            $has=(bool)$col;
        }
        if(!$has) $pdo->exec('ALTER TABLE enterprise_licenses ADD customer_key VARCHAR(320) NULL');
    } catch (Throwable $e) {
        // CSV and compatibility adapters can expose schema from their own table definition.
        try{$pdo->query('SELECT customer_key FROM enterprise_licenses WHERE 1=0');}catch(Throwable){throw $e;}
    }

    try{$pdo->exec('CREATE INDEX IF NOT EXISTS idx_license_customer_key ON enterprise_licenses(customer_key)');}catch(Throwable){
        try{$pdo->exec('CREATE INDEX idx_license_customer_key ON enterprise_licenses(customer_key)');}catch(Throwable){}
    }

    // Backfill only unambiguous legacy rows where the old holder itself was an e-mail address.
    try {
        $rows=$pdo->query('SELECT id,customer_key,holder,metadata_json FROM enterprise_licenses')->fetchAll(PDO::FETCH_ASSOC);
        $update=$pdo->prepare('UPDATE enterprise_licenses SET customer_key=?, metadata_json=? WHERE id=?');
        foreach($rows as $row){
            $current=mb_strtolower(trim((string)($row['customer_key']??'')),'UTF-8');
            if($current!=='' && filter_var($current,FILTER_VALIDATE_EMAIL)) continue;
            $legacy=mb_strtolower(trim((string)($row['holder']??'')),'UTF-8');
            if($legacy==='' || !filter_var($legacy,FILTER_VALIDATE_EMAIL)) continue;
            $meta=[];$raw=(string)($row['metadata_json']??'');
            if($raw!==''){$decoded=json_decode($raw,true);if(is_array($decoded))$meta=$decoded;}
            $meta['customer_key']=$legacy;
            $update->execute([$legacy,json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(int)$row['id']]);
        }
    } catch(Throwable) {
        // No destructive guess is made for legacy rows whose holder is a company/person name.
    }
}

function enterprise_ensure_superadmin_assignment(PDO $pdo): void {
    $superRole=$pdo->query("SELECT id FROM roles WHERE name='superadmin' LIMIT 1")->fetchColumn();
    if($superRole===false) return;
    $count=$pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id=?');
    $count->execute([(int)$superRole]);
    if((int)$count->fetchColumn()>0) return;
    $adminRole=$pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
    if($adminRole===false) return;
    $first=$pdo->prepare('SELECT user_id FROM user_roles WHERE role_id=? ORDER BY user_id LIMIT 1');
    $first->execute([(int)$adminRole]);
    $userId=$first->fetchColumn();
    if($userId===false) return;
    $assign=$pdo->prepare('INSERT IGNORE INTO user_roles(user_id,role_id) VALUES (?,?)');
    $assign->execute([(int)$userId,(int)$superRole]);
}
function enterprise_user(): ?array { return $_SESSION['enterprise_user'] ?? null; }

/** HF86 Phase 1: derive the local customer UI permission set from the current central license. */
function enterprise_customer_permissions(array $modules,array $policy,array $rolePolicy=[]): array {
    if((string)($policy['mode']??'')==='demo') return [];
    $modules=array_values(array_unique(array_map('strval',$modules)));
    $caps=array_values(array_unique(array_map('strval',(array)($policy['capabilities']??[]))));
    $legacy=(string)($policy['capability_policy']??'')==='legacy_unrestricted';
    $roleCaps=array_values(array_unique(array_map('strval',(array)($rolePolicy['capabilities']??[]))));
    $roleUnrestricted=!$rolePolicy || !empty($rolePolicy['unrestricted']) || in_array('*',$roleCaps,true);
    $internal=array_values(array_unique(array_map('strval',(array)($rolePolicy['internal_capabilities']??[]))));
    $resourceScope=(array)($rolePolicy['resource_scope']??[]);
    $resourceUnrestricted=$resourceScope===[]||!empty($resourceScope['unrestricted'])||(($resourceScope['mode']??'')==='unrestricted');
    $has=static function(string $cap)use($legacy,$caps,$roleUnrestricted,$roleCaps):bool{
        $licenseAllowed=$legacy||in_array($cap,$caps,true);
        $roleAllowed=$roleUnrestricted||in_array($cap,$roleCaps,true);
        return $licenseAllowed&&$roleAllowed;
    };
    $out=['projects.view'];
    if(in_array('account.projects.manage',$internal,true)){if($resourceUnrestricted)$out[]='projects.create';$out=array_merge($out,['projects.update','projects.delete']);}
    if(in_array('account.users.manage',$internal,true))$out[]='customer.users.manage';
    if(in_array('account.roles.manage',$internal,true))$out[]='customer.roles.manage';
    if(in_array('account.installations.manage',$internal,true))$out[]='customer.installations.manage';
    if(in_array('dataform',$modules,true)){
        $out[]='dataform.access';
        foreach(['dataset.read','dataset.create','dataset.update','dataset.delete','dataform.search','dataform.filter','dataform.pagination','relation.read','relation.createChild','relation.attach','relation.detach','dataform.events','dataform.custom_css','dataform.custom_javascript'] as $cap) if($has($cap)) $out[]=$cap;
    }
    if(in_array('designer',$modules,true)){
        foreach(['designer.validate','designer.compile','designer.publish'] as $cap) if($has($cap)){$out[]='designer.access';$out[]=$cap;}
    }
    if(in_array('export',$modules,true)&&$has('export.execute')) $out=array_merge($out,['export.execute','projects.backup']);
    if(in_array('api',$modules,true)&&$has('api.access')) $out[]='api.access';
    return array_values(array_unique($out));
}

/** HF86 Phase 1: refresh customer policy on every authenticated manager request. */
function enterprise_refresh_customer_license(array $user): array {
    if(empty($user['customer'])) return $user;
    $root=dirname(__DIR__,2);
    $cfg=EnterpriseLicenseConfigStore::load($root);
    if(!is_array($cfg)||empty($cfg['enabled'])||empty($cfg['server_url'])) throw new RuntimeException('LICENSE_SERVER_NOT_CONFIGURED');
    $token=(string)($_SESSION['enterprise_customer_account_token']??'');
    if($token==='') throw new RuntimeException('ACCOUNT_SESSION_INVALID');
    require_once $root.'/system/licensing/Autoload.php';
    $api=new \EasyIT\Enterprise\Licensing\LicenseApiClient($cfg);
    $profile=$api->accountProfile($token);
    $mode=(string)($profile['manager_mode']??((empty($profile['license']))?'demo':'licensed'));
    if(!in_array($mode,['demo','licensed'],true)) throw new RuntimeException('SERVER_RESPONSE_INVALID');
    $modules=array_values(array_map('strval',(array)($profile['modules']??[])));
    $policy=(array)($profile['license_policy']??[]);
    $rolePolicy=(array)($profile['role_policy']??[]);
    $license=(array)($profile['license']??[]);
    $surfacePolicy=(array)($profile['surface_policy']??['allowed'=>true,'error_code'=>null]);
    $surfaceAllowed=!array_key_exists('allowed',$surfacePolicy) || !empty($surfacePolicy['allowed']);
    if($mode==='licensed'&&$license===[]) throw new RuntimeException('LICENSE_INVALID');
    if($mode==='demo'){$modules=[];$license=[];$policy=array_replace(['mode'=>'demo','capability_policy'=>'demo','capabilities'=>[]],$policy);}
    $user['manager_mode']=$mode;
    $user['customer_key']=(string)($profile['customer_key']??$user['customer_key']??$user['email']??'');
    $user['modules']=$modules;
    $user['license_policy']=$policy;
    $user['license']=$license;
    $user['role_policy']=$rolePolicy;
    $user['surface_policy']=$surfacePolicy;
    $user['surface_allowed']=$surfaceAllowed;
    $user['permissions']=($mode==='demo'||!$surfaceAllowed)?[]:enterprise_customer_permissions($modules,$policy,$rolePolicy);
    $_SESSION['enterprise_user']=$user;
    $_SESSION['enterprise_customer_license_payload']=array_replace((array)($_SESSION['enterprise_customer_license_payload']??[]),[
        'manager_mode'=>$mode,'customer_key'=>$user['customer_key'],'license'=>$license,'modules'=>$modules,'license_policy'=>$policy,'role_policy'=>$rolePolicy,'surface_policy'=>$surfacePolicy,'demo'=>$profile['demo']??null,
    ]);
    return $user;
}

function enterprise_require_auth(string $base='../'): array {
    $user=enterprise_user();
    if (!$user) { header('Location: '.$base.'login.php?next='.rawurlencode($_SERVER['REQUEST_URI'] ?? '')); exit; }
    if(!empty($user['customer'])){
        try{$user=enterprise_refresh_customer_license($user);}
        catch(Throwable $e){
            $_SESSION['enterprise_login_error_code']=$e->getMessage();
            unset($_SESSION['enterprise_user'],$_SESSION['enterprise_customer_license_payload'],$_SESSION['enterprise_customer_account_token'],$_SESSION['enterprise_customer_account_expires_at']);
            header('Location: '.$base.'login.php?reauth=1');exit;
        }
        /* HF133: demo users and licensed accounts with an unresolved surface policy
         * stay inside dashboard/account. The license is already adopted; only
         * productive resources remain blocked until the current domain/IP is allowed. */
        if(enterprise_is_demo($user) || !enterprise_surface_allowed($user)){
            $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??''));
            $allowed=false;
            foreach(['/app/dashboard.php','/app/account.php'] as $suffix){
                if($script!==''&&str_ends_with($script,$suffix)){$allowed=true;break;}
            }
            if(!$allowed){
                http_response_code(403);
                exit(enterprise_is_demo($user)
                    ? 'Demo-Version: Diese Funktion benötigt eine gültige Lizenz. Produktive Projekte, DataForms, Designer, Export und API sind im Demo-Modus gesperrt.'
                    : 'Die Lizenz ist aktiv, aber die aktuelle Domain/IP ist noch nicht freigegeben. Öffnen Sie „Mein Konto“ und ergänzen Sie die zulässige Domain bzw. das Netz.');
            }
        }
    }
    return $user;
}
function enterprise_is_superadmin(array $user): bool { return in_array('superadmin',$user['roles'] ?? [],true); }
function enterprise_is_admin(array $user): bool { return enterprise_is_superadmin($user) || in_array('admin',$user['roles'] ?? [],true); }
function enterprise_is_customer(array $user): bool { return !empty($user['customer']); }
function enterprise_is_demo(array $user): bool { return enterprise_is_customer($user) && (string)($user['manager_mode']??'licensed')==='demo'; }
function enterprise_surface_allowed(array $user): bool { return !enterprise_is_customer($user) || !array_key_exists('surface_allowed',$user) || !empty($user['surface_allowed']); }
function enterprise_customer_has_module(array $user,string $module): bool { return !enterprise_is_customer($user) || (!enterprise_is_demo($user) && in_array($module,array_map('strval',(array)($user['modules']??[])),true)); }
function enterprise_license_capability(array $user,string $capability): bool {
    if(!enterprise_is_customer($user)) return true;
    if(enterprise_is_demo($user)) return false;
    $policy=(array)($user['license_policy']??[]);$role=(array)($user['role_policy']??[]);
    $licenseAllowed=(string)($policy['capability_policy']??'')==='legacy_unrestricted'||in_array($capability,array_map('strval',(array)($policy['capabilities']??[])),true);
    $roleCaps=array_map('strval',(array)($role['capabilities']??[]));$roleAllowed=$role===[]||!empty($role['unrestricted'])||in_array('*',$roleCaps,true)||in_array($capability,$roleCaps,true);
    return $licenseAllowed&&$roleAllowed;
}
function enterprise_customer_internal_capability(array $user,string $capability): bool { if(!enterprise_is_customer($user))return true;return in_array($capability,array_map('strval',(array)(($user['role_policy']['internal_capabilities']??[]))),true); }
/** HF86 Phase 4: centrally assigned project/DataForm visibility. */
function enterprise_resource_scope(array $user): array { return enterprise_is_customer($user)?(array)($user['role_policy']['resource_scope']??[]):['unrestricted'=>true,'mode'=>'unrestricted']; }
function enterprise_resource_scope_unrestricted(array $user): bool { if(!enterprise_is_customer($user))return true;$s=enterprise_resource_scope($user);return $s===[]||!empty($s['unrestricted'])||(($s['mode']??'')==='unrestricted'); }
function enterprise_project_scope_visible(array $user,string|int $projectId): bool {
    if(!enterprise_is_customer($user)||enterprise_resource_scope_unrestricted($user))return true;
    $pid=(string)$projectId;$s=enterprise_resource_scope($user);
    return in_array($pid,array_map('strval',(array)($s['visible_project_ids']??$s['project_ids']??[])),true);
}
function enterprise_project_scope_manage(array $user,string|int $projectId): bool {
    if(!enterprise_is_customer($user)||enterprise_resource_scope_unrestricted($user))return true;
    return in_array((string)$projectId,array_map('strval',(array)(enterprise_resource_scope($user)['project_ids']??[])),true);
}
function enterprise_require_project_scope(array $user,string|int $projectId,bool $manage=false): void {
    $ok=$manage?enterprise_project_scope_manage($user,$projectId):enterprise_project_scope_visible($user,$projectId);
    if(!$ok){http_response_code(403);exit('Dieses Projekt ist diesem Benutzer bzw. seinen Rollen nicht zugeordnet.');}
}
function enterprise_dataform_scope_allowed(array $user,string|int $projectId,string|int $dataformId,string $dataformUuid=''): bool {
    if(!enterprise_is_customer($user)||enterprise_resource_scope_unrestricted($user))return true;
    $s=enterprise_resource_scope($user);$pid=(string)$projectId;
    if(in_array($pid,array_map('strval',(array)($s['project_ids']??[])),true))return true;
    if($dataformUuid!==''&&in_array($dataformUuid,array_map('strval',(array)($s['dataform_uuids']??[])),true))return true;
    foreach((array)($s['dataforms']??[]) as $df)if((string)($df['project_id']??'')===$pid&&(string)($df['local_dataform_id']??'')===(string)$dataformId)return true;
    return false;
}
function enterprise_require_dataform_scope(array $user,string|int $projectId,string|int $dataformId,string $dataformUuid=''): void {
    if(!enterprise_dataform_scope_allowed($user,$projectId,$dataformId,$dataformUuid)){http_response_code(403);exit('Dieses DataForm ist diesem Benutzer bzw. seinen Rollen nicht zugeordnet.');}
}
function enterprise_filter_projects_for_scope(array $user,array $projects): array { if(!enterprise_is_customer($user)||enterprise_resource_scope_unrestricted($user))return $projects;return array_values(array_filter($projects,fn($p)=>enterprise_project_scope_visible($user,(string)($p['id']??'')))); }
function enterprise_filter_dataforms_for_scope(array $user,string|int $projectId,array $dataforms): array { if(!enterprise_is_customer($user)||enterprise_resource_scope_unrestricted($user))return $dataforms;return array_values(array_filter($dataforms,fn($df)=>enterprise_dataform_scope_allowed($user,$projectId,(string)($df['id']??$df['local_dataform_id']??''),(string)($df['df_uuid']??$df['dataform_uuid']??'')))); }
function enterprise_require_customer_module(array $user,string $module): void {
    if(!enterprise_customer_has_module($user,$module)){http_response_code(403);exit(enterprise_is_demo($user)?'Diese Funktion ist in der Demo-Version nicht verfügbar.':'Dieses Modul ist in der aktuellen Lizenz nicht freigegeben.');}
    if(enterprise_is_customer($user) && in_array($module,['dataform','dialog','nachhilfe','calendar','csv-engine','db-engine'],true)){
        $projectId=(string)($_GET['project']??$_POST['project']??($_SESSION['active_project_id']??''));
        if($projectId!==''){
            $slug='';try{$pdo=enterprise_pdo();$st=$pdo->prepare('SELECT slug FROM projects WHERE id=? LIMIT 1');$st->execute([(int)$projectId]);$slug=(string)($st->fetchColumn()?:'');}catch(Throwable){}
            enterprise_require_project_product($user,$projectId,$module,$slug);
        }
    }
}
function enterprise_require_license_capability(array $user,string $capability): void { if(!enterprise_license_capability($user,$capability)){http_response_code(403);exit(enterprise_is_demo($user)?'Diese Funktion ist in der Demo-Version nicht verfügbar.':'Diese Funktion ist in der aktuellen Lizenz nicht freigegeben: '.htmlspecialchars($capability,ENT_QUOTES,'UTF-8'));} }
function enterprise_landing_path(array $user): string { return enterprise_is_admin($user) ? 'app/projects/index.php' : 'app/dashboard.php'; }
function enterprise_sync_module_capabilities(PDO $pdo,string $modulesRoot): void { if(!is_dir($modulesRoot)) return; $stmt=$pdo->prepare('INSERT IGNORE INTO capabilities(name,module_name,label) VALUES (?,?,?)'); foreach(glob(rtrim($modulesRoot,'/\\').'/*/module.json')?:[] as $file){$data=json_decode((string)@file_get_contents($file),true);if(!is_array($data))continue;$module=(string)($data['name']??basename(dirname($file)));foreach((array)($data['capabilities']??[]) as $cap){if(is_string($cap)&&$cap!=='')$stmt->execute([$cap,$module,$cap]);}} if($pdo instanceof EnterpriseOraclePdo){foreach(['admin','superadmin'] as $roleName){$pdo->exec("INSERT INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name='".$roleName."' AND NOT EXISTS (SELECT 1 FROM role_capabilities rc WHERE rc.role_id=r.id AND rc.capability_id=c.id)");}}else{$pdo->exec("INSERT IGNORE INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name='admin'");$pdo->exec("INSERT IGNORE INTO role_capabilities(role_id,capability_id) SELECT r.id,c.id FROM roles r CROSS JOIN capabilities c WHERE r.name='superadmin'");} }
function enterprise_permissions(PDO $pdo,int $userId): array { $st=$pdo->prepare('SELECT DISTINCT c.name FROM capabilities c JOIN role_capabilities rc ON rc.capability_id=c.id JOIN user_roles ur ON ur.role_id=rc.role_id WHERE ur.user_id=?'); $st->execute([$userId]); return array_column($st->fetchAll(PDO::FETCH_ASSOC),'name'); }
function enterprise_can(array $user,string $capability): bool { return enterprise_is_superadmin($user) || enterprise_is_admin($user) || in_array($capability,$user['permissions']??[],true); }
function enterprise_require_capability(array $user,string $capability): void { if(!enterprise_can($user,$capability)){http_response_code(403);exit('Berechtigung fehlt: '.htmlspecialchars($capability,ENT_QUOTES,'UTF-8'));} }
function enterprise_csrf(): string {
    if (empty($_SESSION['enterprise_csrf'])) $_SESSION['enterprise_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['enterprise_csrf'];
}
function enterprise_check_csrf(string $token): void {
    if (!hash_equals(enterprise_csrf(),$token)) throw new RuntimeException('Sicherheitsprüfung fehlgeschlagen. Seite neu laden.');
}
/**
 * Resolve the actor for the local audit_log foreign key.
 *
 * Local administrator ids belong to easyit_license.users and may therefore be
 * stored in audit_log.user_id. Customer accounts, however, are authenticated by
 * the central license server. Their customer_user_id is a remote identifier and
 * must never be written into the local users foreign key (even when the number
 * happens to collide with a local administrator id).
 */
function enterprise_audit_local_user_id(PDO $pdo, ?int $requestedUserId, array &$context): ?int {
    $sessionUser=enterprise_user();
    if(is_array($sessionUser) && enterprise_is_customer($sessionUser)){
        $context['_actor']=array_replace((array)($context['_actor']??[]),[
            'scope'=>'customer',
            'remote_user_id'=>(string)($sessionUser['id']??($requestedUserId??'')),
        ]);
        return null;
    }
    if($requestedUserId===null || $requestedUserId<=0) return null;
    try{
        $stmt=$pdo->prepare('SELECT id FROM users WHERE id=? LIMIT 1');
        $stmt->execute([$requestedUserId]);
        if($stmt->fetchColumn()!==false) return $requestedUserId;
    }catch(Throwable $e){
        // Audit must not turn a successful business operation into a failed one
        // merely because an obsolete/stale actor id is no longer available.
    }
    $context['_actor']=array_replace((array)($context['_actor']??[]),[
        'scope'=>'unmapped_local',
        'requested_user_id'=>$requestedUserId,
    ]);
    return null;
}

function enterprise_audit(PDO $pdo, ?int $userId, string $action, ?string $type=null, ?string $id=null, array $context=[]): void {
    $localUserId=enterprise_audit_local_user_id($pdo,$userId,$context);
    $stmt=$pdo->prepare('INSERT INTO audit_log(user_id,action,object_type,object_id,context_json) VALUES (?,?,?,?,?)');
    $stmt->execute([$localUserId,$action,$type,$id,$context?json_encode($context,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
}


// RC1.7 Phase A – zentrale Enterprise Event Platform.
require_once dirname(__DIR__, 2) . '/DataForm5-Core/bootstrap/autoload.php';

function enterprise_event_config(): array {
    static $config = null;
    if (is_array($config)) return $config;
    $file = dirname(__DIR__, 2) . '/DataForm5-Core/config/events.php';
    $config = is_file($file) ? (require $file) : [];
    return is_array($config) ? $config : [];
}

function enterprise_events(): \DataForm5\Events\Core\EventDispatcher {
    static $dispatcher = null;
    if ($dispatcher instanceof \DataForm5\Events\Core\EventDispatcher) return $dispatcher;
    $dispatcher = new \DataForm5\Events\Core\EventDispatcher();
    return $dispatcher;
}

function enterprise_event_catalog(): \DataForm5\Events\Core\EventCatalog {
    static $catalog = null;
    if ($catalog instanceof \DataForm5\Events\Core\EventCatalog) return $catalog;
    $catalog = new \DataForm5\Events\Core\EventCatalog();
    foreach ((array)(enterprise_event_config()['catalog'] ?? []) as $name => $meta) {
        $meta = is_array($meta) ? $meta : [];
        $catalog->register((string)$name, (string)($meta['description'] ?? ''), (string)($meta['producer'] ?? 'enterprise'));
    }
    return $catalog;
}

function enterprise_event_listen(string $eventName, callable $listener, int $priority = 0): void {
    enterprise_events()->listen($eventName, $listener, $priority);
}

function enterprise_event_dispatch(string $eventName, array $payload = [], array $context = []): \DataForm5\Events\Core\NamedEvent {
    $event = new \DataForm5\Events\Core\NamedEvent($eventName, $payload, $context);
    $config = enterprise_event_config();
    if (($config['enabled'] ?? true) === false) return $event;
    enterprise_events()->dispatch($event, $eventName);
    if(function_exists('enterprise_developer_enabled') && enterprise_developer_enabled()){
        try{enterprise_developer_trace()->event($eventName,['payload_keys'=>array_keys($payload)]);}catch(\Throwable){}
    }
    enterprise_event_diagnostic($event);
    return $event;
}

function enterprise_event_diagnostic(\DataForm5\Events\Core\NamedEvent $event): void {
    $diag = (array)(enterprise_event_config()['diagnostics'] ?? []);
    if (($diag['enabled'] ?? false) !== true) return;
    $root = dirname(__DIR__, 2);
    $relative = (string)($diag['file'] ?? 'storage/logs/events.log');
    $file = $root . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir) || !is_writable($dir)) return;
    $max = max(65536, (int)($diag['max_bytes'] ?? 1048576));
    if (is_file($file) && filesize($file) > $max) @rename($file, $file . '.1');
    $redact = static function(array $data): array {
        $result=[];
        foreach ($data as $key=>$value) {
            $k=strtolower((string)$key);
            if (preg_match('/password|secret|token|api[_-]?key|session/', $k)) { $result[$key]='[REDACTED]'; continue; }
            $result[$key]=is_array($value) ? '[array]' : (is_scalar($value) || $value===null ? $value : '['.get_debug_type($value).']');
        }
        return $result;
    };
    $line = json_encode([
        'time'=>$event->occurredAt(),
        'event'=>$event->name(),
        'payload'=>$redact($event->payload()),
        'context'=>$redact($event->context()),
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if (is_string($line)) @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// RC1.7 Phase B – gemeinsamer Enterprise-Service-Container.
function enterprise_kernel(): \DataForm5\Core\Kernel {
    static $kernel = null;
    if ($kernel instanceof \DataForm5\Core\Kernel) return $kernel;
    $kernel = require dirname(__DIR__, 2) . '/DataForm5-Core/bootstrap/app.php';
    try {
        $pdo = enterprise_pdo();
        enterprise_upgrade($pdo);
        $kernel->container()->instance(\DataForm5\Licensing\Contracts\LicenseProviderInterface::class, new \DataForm5\Licensing\Core\PdoLicenseProvider($pdo));
        $kernel->container()->instance(\DataForm5\Licensing\Core\LicenseRegistry::class, new \DataForm5\Licensing\Core\LicenseRegistry($pdo));
    } catch (\Throwable) {
        // Vor Abschluss des Installers bleibt der konfigurierte Community-Provider aktiv.
    }
    return $kernel;
}
function enterprise_container(): \DataForm5\Core\Contracts\ContainerInterface {
    return enterprise_kernel()->container();
}


function enterprise_module_ui(): \DataForm5\Modules\UI\ModuleUiRegistry {
    static $ui=null;
    if($ui instanceof \DataForm5\Modules\UI\ModuleUiRegistry) return $ui;
    $kernel=enterprise_kernel();
    $manager=$kernel->container()->get(\DataForm5\Modules\Core\ModuleManager::class);
    $ui=new \DataForm5\Modules\UI\ModuleUiRegistry($manager);
    return $ui;
}


function enterprise_module_routes(): \DataForm5\Modules\Routing\ModuleRouteRegistry {
    return enterprise_container()->get(\DataForm5\Modules\Routing\ModuleRouteRegistry::class);
}
function enterprise_module_dispatcher(): \DataForm5\Modules\Routing\ModuleRouteDispatcher {
    return enterprise_container()->get(\DataForm5\Modules\Routing\ModuleRouteDispatcher::class);
}

function enterprise_module_api_routes(): \DataForm5\Modules\Routing\ModuleApiRegistry { return enterprise_container()->get(\DataForm5\Modules\Routing\ModuleApiRegistry::class); }
function enterprise_module_api_dispatcher(): \DataForm5\Modules\Routing\ModuleApiDispatcher { return enterprise_container()->get(\DataForm5\Modules\Routing\ModuleApiDispatcher::class); }


function enterprise_module_background(): \DataForm5\Modules\Background\ModuleBackgroundRegistry {
    return enterprise_container()->get(\DataForm5\Modules\Background\ModuleBackgroundRegistry::class);
}

function enterprise_module_background_admin(): \DataForm5\Modules\Background\ModuleBackgroundAdmin {
    return enterprise_container()->get(\DataForm5\Modules\Background\ModuleBackgroundAdmin::class);
}

function enterprise_monitoring(): \DataForm5\Monitoring\Core\HealthManager { return enterprise_container()->get(\DataForm5\Monitoring\Core\HealthManager::class); }
function enterprise_monitoring_heartbeat(): \DataForm5\Monitoring\Core\WorkerHeartbeat { return enterprise_container()->get(\DataForm5\Monitoring\Core\WorkerHeartbeat::class); }

function enterprise_cluster(): \DataForm5\Cluster\Core\ClusterManager {
    return enterprise_container()->get(\DataForm5\Cluster\Core\ClusterManager::class);
}

function enterprise_cluster_lock(): \DataForm5\Cluster\Core\ClusterLock {
    return enterprise_container()->get(\DataForm5\Cluster\Core\ClusterLock::class);
}

function enterprise_storage(): \DataForm5\Core\Filesystem\StorageManager {
    return enterprise_container()->get(\DataForm5\Core\Filesystem\StorageManager::class);
}

function enterprise_replication(): \DataForm5\Replication\Core\ClusterSynchronizer {
    return enterprise_container()->get(\DataForm5\Replication\Core\ClusterSynchronizer::class);
}
function enterprise_replication_snapshots(): \DataForm5\Replication\Core\ReplicationSnapshotService {
    return enterprise_container()->get(\DataForm5\Replication\Core\ReplicationSnapshotService::class);
}

function enterprise_cluster_trust_store(): \DataForm5\Cluster\Security\NodeTrustStore {
    return enterprise_container()->get(\DataForm5\Cluster\Security\NodeTrustStore::class);
}


function enterprise_dashboard_snapshot(): array {
    static $snapshot=null;
    if(is_array($snapshot)) return $snapshot;
    try {
        $dashboard=enterprise_container()->get(\DataForm5\Core\Dashboard\EnterpriseDashboard::class);
        return $snapshot=$dashboard->snapshot();
    } catch (\Throwable $e) {
        return $snapshot=[
            'overall'=>'failed',
            'generated_at'=>date(DATE_ATOM),
            'error'=>$e->getMessage(),
            'modules'=>['status'=>'unknown','active'=>0,'total'=>0,'issues'=>0],
            'jobs'=>['status'=>'unknown','pending'=>0,'failed'=>0],
            'monitoring'=>['status'=>'unknown','alerts'=>0],
            'cluster'=>['status'=>'unknown','online'=>0,'nodes'=>0,'leader'=>null],
            'storage'=>['status'=>'unknown','healthy'=>0,'total'=>0],
            'replication'=>['status'=>'unknown','channel'=>'','security'=>false],
        ];
    }
}


function enterprise_developer_config(): array {
    static $config=null;
    if(is_array($config)) return $config;
    $file=dirname(__DIR__,2).'/DataForm5-Core/config/developer.php';
    $config=is_file($file)?require $file:[];
    return is_array($config)?$config:[];
}
function enterprise_developer_enabled(?array $user=null): bool {
    $config=enterprise_developer_config();
    if(($config['enabled']??false)!==true) return false;
    if($user===null) return true;
    $roles=(array)($user['roles']??[]);
    foreach((array)($config['allowed_roles']??['admin']) as $role)
        if(in_array($role,$roles,true)) return true;
    return false;
}
function enterprise_developer_trace(): \DataForm5\Core\Developer\DeveloperTrace {
    return enterprise_container()->get(\DataForm5\Core\Developer\DeveloperTrace::class);
}
function enterprise_developer_inspector(): \DataForm5\Core\Developer\DeveloperInspector {
    return enterprise_container()->get(\DataForm5\Core\Developer\DeveloperInspector::class);
}

function enterprise_container_inspector(): \DataForm5\Core\Developer\ContainerInspector {
    return enterprise_container()->get(\DataForm5\Core\Developer\ContainerInspector::class);
}

function enterprise_event_inspector(): \DataForm5\Core\Developer\EventInspector {
    return enterprise_container()->get(\DataForm5\Core\Developer\EventInspector::class);
}

function enterprise_hook_inspector(): \DataForm5\Core\Developer\HookInspector {
    return enterprise_container()->get(\DataForm5\Core\Developer\HookInspector::class);
}

function enterprise_profiler(): \DataForm5\Core\Developer\RequestProfiler {
    return enterprise_container()->get(\DataForm5\Core\Developer\RequestProfiler::class);
}

function enterprise_quality_center(): \DataForm5\Testing\Core\DeveloperQualityCenter {
    return enterprise_container()->get(\DataForm5\Testing\Core\DeveloperQualityCenter::class);
}
