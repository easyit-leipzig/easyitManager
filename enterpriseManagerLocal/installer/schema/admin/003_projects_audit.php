<?php
declare(strict_types=1);
return static function (PDO $pdo): void {
    $sqlite=class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo;
    if($sqlite){
        $pdo->exec("CREATE TABLE IF NOT EXISTS projects (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,slug TEXT NOT NULL UNIQUE,storage_key TEXT NULL UNIQUE,database_driver TEXT NOT NULL DEFAULT 'mysql',database_name TEXT NOT NULL,status TEXT NOT NULL DEFAULT 'active',created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NULL,action TEXT NOT NULL,object_type TEXT NULL,object_id TEXT NULL,context_json TEXT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at)"); return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, slug VARCHAR(160) NOT NULL UNIQUE, storage_key VARCHAR(190) NULL UNIQUE, database_driver VARCHAR(40) NOT NULL DEFAULT 'mysql', database_name VARCHAR(190) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NULL, action VARCHAR(120) NOT NULL, object_type VARCHAR(100) NULL, object_id VARCHAR(100) NULL, context_json LONGTEXT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_created(created_at), CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
