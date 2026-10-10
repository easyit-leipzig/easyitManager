<?php
declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec("INSERT IGNORE INTO roles (name,label) VALUES ('superadmin','Superadministrator')");

    $superRole = $pdo->query("SELECT id FROM roles WHERE name='superadmin' LIMIT 1")->fetchColumn();
    if ($superRole === false) {
        throw new RuntimeException('Systemrolle superadmin konnte nicht angelegt werden.');
    }

    $count = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id=?');
    $count->execute([(int)$superRole]);
    if ((int)$count->fetchColumn() > 0) {
        return;
    }

    // Bestehende Installationen: den ältesten Administrator einmalig zum
    // Superadministrator hochstufen. Die Admin-Rolle bleibt zusätzlich bestehen.
    $adminRole = $pdo->query("SELECT id FROM roles WHERE name='admin' LIMIT 1")->fetchColumn();
    if ($adminRole === false) {
        return;
    }

    $firstAdmin = $pdo->prepare('SELECT user_id FROM user_roles WHERE role_id=? ORDER BY user_id LIMIT 1');
    $firstAdmin->execute([(int)$adminRole]);
    $userId = $firstAdmin->fetchColumn();
    if ($userId === false) {
        return;
    }

    $assign = $pdo->prepare('INSERT IGNORE INTO user_roles (user_id,role_id) VALUES (?,?)');
    $assign->execute([(int)$userId,(int)$superRole]);
};
