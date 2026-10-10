<?php
declare(strict_types=1);
return static function (PDO $pdo): void {
    $sqlite=class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo;
    if($sqlite){$pdo->exec("CREATE TABLE IF NOT EXISTS dataform_managed_tables (id INTEGER PRIMARY KEY AUTOINCREMENT,table_name TEXT NOT NULL UNIQUE,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");return;}
    $pdo->exec("CREATE TABLE IF NOT EXISTS dataform_managed_tables (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,table_name VARCHAR(64) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_dataform_managed_table(table_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
