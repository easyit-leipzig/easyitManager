<?php
declare(strict_types=1);
return static function (PDO $pdo): void {
    $sqlite=class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo;
    if($sqlite){
        $pdo->exec("CREATE TABLE IF NOT EXISTS data_sources (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,driver TEXT NOT NULL,config_json TEXT NOT NULL,secret_ciphertext TEXT NULL,secret_nonce TEXT NULL,secret_tag TEXT NULL,is_enabled INTEGER NOT NULL DEFAULT 1,last_test_status TEXT NOT NULL DEFAULT 'unknown',last_test_message TEXT NULL,last_test_at TEXT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_data_sources_driver ON data_sources(driver)");$pdo->exec("CREATE INDEX IF NOT EXISTS idx_data_sources_enabled ON data_sources(is_enabled)"); return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS data_sources (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(160) NOT NULL, driver VARCHAR(30) NOT NULL, config_json LONGTEXT NOT NULL, secret_ciphertext LONGTEXT NULL, secret_nonce VARCHAR(255) NULL, secret_tag VARCHAR(255) NULL, is_enabled TINYINT(1) NOT NULL DEFAULT 1, last_test_status VARCHAR(20) NOT NULL DEFAULT 'unknown', last_test_message TEXT NULL, last_test_at TIMESTAMP NULL DEFAULT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_data_source_name(name), KEY idx_data_sources_driver(driver), KEY idx_data_sources_enabled(is_enabled)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
