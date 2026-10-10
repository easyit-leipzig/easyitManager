<?php
declare(strict_types=1);
return static function (PDO $pdo): void {
    $sqlite=class_exists('EnterpriseSqlitePdo',false) && $pdo instanceof EnterpriseSqlitePdo;
    if($sqlite){
        $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL UNIQUE,label TEXT NOT NULL,created_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT NOT NULL UNIQUE,email TEXT NULL UNIQUE,password_hash TEXT NOT NULL,is_active INTEGER NOT NULL DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id INTEGER NOT NULL,role_id INTEGER NOT NULL,PRIMARY KEY(user_id,role_id),FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE)");
        $pdo->exec("INSERT OR IGNORE INTO roles(name,label) VALUES ('admin','Administrator'),('editor','Bearbeiter'),('viewer','Leser')");
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS roles (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(80) NOT NULL UNIQUE, label VARCHAR(120) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(120) NOT NULL UNIQUE, email VARCHAR(190) NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (user_id BIGINT UNSIGNED NOT NULL, role_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY(user_id, role_id), CONSTRAINT fk_ur_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, CONSTRAINT fk_ur_role FOREIGN KEY(role_id) REFERENCES roles(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (class_exists('EnterpriseOraclePdo', false) && $pdo instanceof EnterpriseOraclePdo) {
    $seed=$pdo->prepare('INSERT IGNORE INTO roles (name,label) VALUES (?,?)');
    foreach ([['admin','Administrator'],['editor','Bearbeiter'],['viewer','Leser']] as $role) $seed->execute($role);
} else {
    $pdo->exec("INSERT IGNORE INTO roles (name,label) VALUES ('admin','Administrator'),('editor','Bearbeiter'),('viewer','Leser')");
}
};
