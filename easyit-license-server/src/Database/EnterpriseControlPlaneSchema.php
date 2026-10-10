<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Database;

use PDO;
use RuntimeException;

/**
 * HF115: Shared easyIT control-plane schema.
 *
 * The central database `easyit_license` is intentionally shared by the
 * license server and the Enterprise Manager administration. A destructive
 * license-server setup therefore has to recreate both schema families.
 */
final class EnterpriseControlPlaneSchema
{
    public const REQUIRED_TABLES = [
        'migrations','roles','users','user_roles','projects','audit_log',
        'installed_products','enterprise_module_settings','enterprise_module_secrets',
        'capabilities','role_capabilities','enterprise_module_migrations','enterprise_licenses',
    ];

    public static function migrate(PDO $pdo): void
    {
        $driver=(string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if($driver==='mysql') self::mysql($pdo);
        elseif($driver==='pgsql') self::pgsql($pdo);
        elseif($driver==='sqlite') self::sqlite($pdo);
        else throw new RuntimeException('ENTERPRISE_CONTROL_PLANE_DRIVER_NOT_SUPPORTED: '.$driver);
        self::seed($pdo,$driver);
    }

    private static function mysql(PDO $pdo): void
    {
        foreach([
            "CREATE TABLE IF NOT EXISTS migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,migration VARCHAR(190) NOT NULL UNIQUE,checksum CHAR(64) NOT NULL,executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS roles (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE,label VARCHAR(120) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,username VARCHAR(120) NOT NULL UNIQUE,email VARCHAR(190) NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS user_roles (user_id BIGINT UNSIGNED NOT NULL,role_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(user_id,role_id),CONSTRAINT fk_ur_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,CONSTRAINT fk_ur_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS projects (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,slug VARCHAR(160) NOT NULL UNIQUE,storage_key VARCHAR(190) NULL UNIQUE,product_type VARCHAR(60) NOT NULL DEFAULT 'dataform',database_driver VARCHAR(40) NOT NULL DEFAULT 'mysql',database_name VARCHAR(190) NOT NULL,description TEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NULL,action VARCHAR(120) NOT NULL,object_type VARCHAR(100) NULL,object_id VARCHAR(100) NULL,context_json LONGTEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,INDEX idx_audit_created(created_at),CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS installed_products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_key VARCHAR(80) NOT NULL UNIQUE,label VARCHAR(120) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'available',version VARCHAR(40) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS enterprise_module_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_name VARCHAR(190) NOT NULL,scope_type VARCHAR(30) NOT NULL DEFAULT 'enterprise',scope_id VARCHAR(190) NOT NULL DEFAULT 'global',config_key VARCHAR(190) NOT NULL,config_value_json LONGTEXT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_module_setting(module_name,scope_type,scope_id,config_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS enterprise_module_secrets (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_name VARCHAR(190) NOT NULL,scope_type VARCHAR(30) NOT NULL DEFAULT 'enterprise',scope_id VARCHAR(190) NOT NULL DEFAULT 'global',config_key VARCHAR(190) NOT NULL,ciphertext LONGTEXT NOT NULL,nonce VARCHAR(255) NOT NULL,tag VARCHAR(255) NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_module_secret(module_name,scope_type,scope_id,config_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS capabilities (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(190) NOT NULL UNIQUE,module_name VARCHAR(190) NULL,label VARCHAR(190) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_cap_module(module_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS role_capabilities (role_id BIGINT UNSIGNED NOT NULL,capability_id BIGINT UNSIGNED NOT NULL,PRIMARY KEY(role_id,capability_id),CONSTRAINT fk_rc_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,CONSTRAINT fk_rc_cap FOREIGN KEY(capability_id) REFERENCES capabilities(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS enterprise_module_migrations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,module_name VARCHAR(190) NOT NULL,migration_version VARCHAR(190) NOT NULL,checksum CHAR(64) NOT NULL,batch INT UNSIGNED NOT NULL DEFAULT 1,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_module_migration(module_name,migration_version),KEY idx_module_migration_batch(module_name,batch)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS enterprise_licenses (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,license_key VARCHAR(190) NOT NULL UNIQUE,customer_key VARCHAR(320) NULL,holder VARCHAR(190) NOT NULL,edition VARCHAR(80) NOT NULL DEFAULT 'community',capabilities_json LONGTEXT NULL,products_json LONGTEXT NULL,valid_from DATE NULL,expires_at DATE NULL,grace_days INT UNSIGNED NOT NULL DEFAULT 0,enabled TINYINT(1) NOT NULL DEFAULT 1,is_primary TINYINT(1) NOT NULL DEFAULT 0,metadata_json LONGTEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_license_enabled(enabled),INDEX idx_license_primary(is_primary),INDEX idx_license_expires(expires_at),INDEX idx_license_customer_key(customer_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ] as $sql) $pdo->exec($sql);
    }

    private static function pgsql(PDO $pdo): void
    {
        foreach([
            "CREATE TABLE IF NOT EXISTS migrations (id BIGSERIAL PRIMARY KEY,migration VARCHAR(190) NOT NULL UNIQUE,checksum CHAR(64) NOT NULL,executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS roles (id BIGSERIAL PRIMARY KEY,name VARCHAR(80) NOT NULL UNIQUE,label VARCHAR(120) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS users (id BIGSERIAL PRIMARY KEY,username VARCHAR(120) NOT NULL UNIQUE,email VARCHAR(190) NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS user_roles (user_id BIGINT NOT NULL,role_id BIGINT NOT NULL,PRIMARY KEY(user_id,role_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS projects (id BIGSERIAL PRIMARY KEY,name VARCHAR(160) NOT NULL,slug VARCHAR(160) NOT NULL UNIQUE,storage_key VARCHAR(190) NULL UNIQUE,product_type VARCHAR(60) NOT NULL DEFAULT 'dataform',database_driver VARCHAR(40) NOT NULL DEFAULT 'pgsql',database_name VARCHAR(190) NOT NULL,description TEXT NULL,status VARCHAR(30) NOT NULL DEFAULT 'active',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS audit_log (id BIGSERIAL PRIMARY KEY,user_id BIGINT NULL,action VARCHAR(120) NOT NULL,object_type VARCHAR(100) NULL,object_id VARCHAR(100) NULL,context_json TEXT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at)",
            "CREATE TABLE IF NOT EXISTS installed_products (id BIGSERIAL PRIMARY KEY,product_key VARCHAR(80) NOT NULL UNIQUE,label VARCHAR(120) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'available',version VARCHAR(40) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS enterprise_module_settings (id BIGSERIAL PRIMARY KEY,module_name VARCHAR(190) NOT NULL,scope_type VARCHAR(30) NOT NULL DEFAULT 'enterprise',scope_id VARCHAR(190) NOT NULL DEFAULT 'global',config_key VARCHAR(190) NOT NULL,config_value_json TEXT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,scope_type,scope_id,config_key))",
            "CREATE TABLE IF NOT EXISTS enterprise_module_secrets (id BIGSERIAL PRIMARY KEY,module_name VARCHAR(190) NOT NULL,scope_type VARCHAR(30) NOT NULL DEFAULT 'enterprise',scope_id VARCHAR(190) NOT NULL DEFAULT 'global',config_key VARCHAR(190) NOT NULL,ciphertext TEXT NOT NULL,nonce VARCHAR(255) NOT NULL,tag VARCHAR(255) NOT NULL,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,scope_type,scope_id,config_key))",
            "CREATE TABLE IF NOT EXISTS capabilities (id BIGSERIAL PRIMARY KEY,name VARCHAR(190) NOT NULL UNIQUE,module_name VARCHAR(190) NULL,label VARCHAR(190) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
            "CREATE INDEX IF NOT EXISTS idx_cap_module ON capabilities(module_name)",
            "CREATE TABLE IF NOT EXISTS role_capabilities (role_id BIGINT NOT NULL,capability_id BIGINT NOT NULL,PRIMARY KEY(role_id,capability_id),FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,FOREIGN KEY(capability_id) REFERENCES capabilities(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS enterprise_module_migrations (id BIGSERIAL PRIMARY KEY,module_name VARCHAR(190) NOT NULL,migration_version VARCHAR(190) NOT NULL,checksum CHAR(64) NOT NULL,batch INTEGER NOT NULL DEFAULT 1,applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,migration_version))",
            "CREATE INDEX IF NOT EXISTS idx_module_migration_batch ON enterprise_module_migrations(module_name,batch)",
            "CREATE TABLE IF NOT EXISTS enterprise_licenses (id BIGSERIAL PRIMARY KEY,license_key VARCHAR(190) NOT NULL UNIQUE,customer_key VARCHAR(320) NULL,holder VARCHAR(190) NOT NULL,edition VARCHAR(80) NOT NULL DEFAULT 'community',capabilities_json TEXT NULL,products_json TEXT NULL,valid_from DATE NULL,expires_at DATE NULL,grace_days INTEGER NOT NULL DEFAULT 0,enabled INTEGER NOT NULL DEFAULT 1,is_primary INTEGER NOT NULL DEFAULT 0,metadata_json TEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE INDEX IF NOT EXISTS idx_license_enabled ON enterprise_licenses(enabled)",
            "CREATE INDEX IF NOT EXISTS idx_license_primary ON enterprise_licenses(is_primary)",
            "CREATE INDEX IF NOT EXISTS idx_license_expires ON enterprise_licenses(expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_license_customer_key ON enterprise_licenses(customer_key)",
        ] as $sql) $pdo->exec($sql);
    }

    private static function sqlite(PDO $pdo): void
    {
        foreach([
            "CREATE TABLE IF NOT EXISTS migrations (id INTEGER PRIMARY KEY AUTOINCREMENT,migration TEXT NOT NULL UNIQUE,checksum TEXT NOT NULL,executed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,label TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,email TEXT NULL UNIQUE,password_hash TEXT NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER NOT NULL,role_id INTEGER NOT NULL,PRIMARY KEY(user_id,role_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS projects (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT NOT NULL UNIQUE,storage_key TEXT NULL UNIQUE,product_type TEXT NOT NULL DEFAULT 'dataform',database_driver TEXT NOT NULL DEFAULT 'sqlite',database_name TEXT NOT NULL,description TEXT NULL,status TEXT NOT NULL DEFAULT 'active',created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,action TEXT NOT NULL,object_type TEXT NULL,object_id TEXT NULL,context_json TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)",
            "CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at)",
            "CREATE TABLE IF NOT EXISTS installed_products (id INTEGER PRIMARY KEY AUTOINCREMENT,product_key TEXT NOT NULL UNIQUE,label TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'available',version TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE TABLE IF NOT EXISTS enterprise_module_settings (id INTEGER PRIMARY KEY AUTOINCREMENT,module_name TEXT NOT NULL,scope_type TEXT NOT NULL DEFAULT 'enterprise',scope_id TEXT NOT NULL DEFAULT 'global',config_key TEXT NOT NULL,config_value_json TEXT NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,scope_type,scope_id,config_key))",
            "CREATE TABLE IF NOT EXISTS enterprise_module_secrets (id INTEGER PRIMARY KEY AUTOINCREMENT,module_name TEXT NOT NULL,scope_type TEXT NOT NULL DEFAULT 'enterprise',scope_id TEXT NOT NULL DEFAULT 'global',config_key TEXT NOT NULL,ciphertext TEXT NOT NULL,nonce TEXT NOT NULL,tag TEXT NOT NULL,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,scope_type,scope_id,config_key))",
            "CREATE TABLE IF NOT EXISTS capabilities (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,module_name TEXT NULL,label TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE INDEX IF NOT EXISTS idx_cap_module ON capabilities(module_name)",
            "CREATE TABLE IF NOT EXISTS role_capabilities (role_id INTEGER NOT NULL,capability_id INTEGER NOT NULL,PRIMARY KEY(role_id,capability_id),FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE,FOREIGN KEY(capability_id) REFERENCES capabilities(id) ON DELETE CASCADE)",
            "CREATE TABLE IF NOT EXISTS enterprise_module_migrations (id INTEGER PRIMARY KEY AUTOINCREMENT,module_name TEXT NOT NULL,migration_version TEXT NOT NULL,checksum TEXT NOT NULL,batch INTEGER NOT NULL DEFAULT 1,applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(module_name,migration_version))",
            "CREATE INDEX IF NOT EXISTS idx_module_migration_batch ON enterprise_module_migrations(module_name,batch)",
            "CREATE TABLE IF NOT EXISTS enterprise_licenses (id INTEGER PRIMARY KEY AUTOINCREMENT,license_key TEXT NOT NULL UNIQUE,customer_key TEXT NULL,holder TEXT NOT NULL,edition TEXT NOT NULL DEFAULT 'community',capabilities_json TEXT NULL,products_json TEXT NULL,valid_from TEXT NULL,expires_at TEXT NULL,grace_days INTEGER NOT NULL DEFAULT 0,enabled INTEGER NOT NULL DEFAULT 1,is_primary INTEGER NOT NULL DEFAULT 0,metadata_json TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)",
            "CREATE INDEX IF NOT EXISTS idx_license_enabled ON enterprise_licenses(enabled)",
            "CREATE INDEX IF NOT EXISTS idx_license_primary ON enterprise_licenses(is_primary)",
            "CREATE INDEX IF NOT EXISTS idx_license_expires ON enterprise_licenses(expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_license_customer_key ON enterprise_licenses(customer_key)",
        ] as $sql) $pdo->exec($sql);
    }

    private static function seed(PDO $pdo,string $driver): void
    {
        foreach([
            ['admin','Administrator'],['editor','Bearbeiter'],['viewer','Leser'],['superadmin','Superadministrator'],
        ] as [$name,$label]){
            if(self::exists($pdo,'roles','name',$name)) continue;
            $pdo->prepare('INSERT INTO roles(name,label) VALUES (?,?)')->execute([$name,$label]);
        }

        $caps=[
            ['projects.view','core','Projekte anzeigen'],['projects.create','core','Projekte anlegen'],['projects.update','core','Projekte bearbeiten'],['projects.delete','core','Projekte löschen'],
            ['projects.backup','core','Projekte sichern'],['projects.restore','core','Projekte wiederherstellen'],['modules.view','core','Module anzeigen'],['modules.manage','core','Module verwalten'],
            ['modules.configure','core','Module konfigurieren'],['permissions.manage','core','Berechtigungen verwalten'],['background.view','core','Hintergrundprozesse anzeigen'],['background.manage','core','Hintergrundprozesse verwalten'],
            ['monitoring.view','core','Monitoring anzeigen'],['monitoring.manage','core','Monitoring verwalten'],['cluster.view','core','Cluster anzeigen'],['cluster.manage','core','Cluster verwalten'],
            ['storage.view','core','Storage anzeigen'],['storage.manage','core','Storage verwalten'],['replication.view','core','Replikation anzeigen'],['replication.manage','core','Replikation verwalten'],
            ['cluster.security','core','Cluster-Sicherheit verwalten'],['developer.view','core','Developer Mode anzeigen'],
        ];
        foreach($caps as [$name,$module,$label]){
            if(self::exists($pdo,'capabilities','name',$name)) continue;
            $pdo->prepare('INSERT INTO capabilities(name,module_name,label) VALUES (?,?,?)')->execute([$name,$module,$label]);
        }

        $products=[
            ['dataform','DataForm','available','5-dev'],['calendar','easyITCalendar','available','2.0'],['dialog','Dialog','planned',null],['nachhilfe','Nachhilfe','planned',null],
            ['csv-engine','CSV-Engine','available','phase1'],['sqlite-engine','SQLite','available','phase2'],['pgsql-engine','PostgreSQL','available','phase3'],['oracle-engine','Oracle XE','planned','phase4'],['mssql-engine','Microsoft SQL Server','planned','phase5'],
        ];
        foreach($products as [$key,$label,$status,$version]){
            if(self::exists($pdo,'installed_products','product_key',$key)) continue;
            $pdo->prepare('INSERT INTO installed_products(product_key,label,status,version) VALUES (?,?,?,?)')->execute([$key,$label,$status,$version]);
        }

        $roleIds=[];
        foreach(['admin','superadmin'] as $roleName){
            $st=$pdo->prepare('SELECT id FROM roles WHERE name=?');$st->execute([$roleName]);$id=$st->fetchColumn();if($id!==false)$roleIds[]=(int)$id;
        }
        $capIds=array_map('intval',$pdo->query('SELECT id FROM capabilities')->fetchAll(PDO::FETCH_COLUMN));
        $find=$pdo->prepare('SELECT 1 FROM role_capabilities WHERE role_id=? AND capability_id=?');
        $insert=$pdo->prepare('INSERT INTO role_capabilities(role_id,capability_id) VALUES (?,?)');
        foreach($roleIds as $roleId)foreach($capIds as $capId){
            $find->execute([$roleId,$capId]);if($find->fetchColumn()!==false)continue;
            $insert->execute([$roleId,$capId]);
        }
    }

    private static function exists(PDO $pdo,string $table,string $column,string $value): bool
    {
        $st=$pdo->prepare('SELECT 1 FROM '.$table.' WHERE '.$column.'=?');$st->execute([$value]);return $st->fetchColumn()!==false;
    }
}
