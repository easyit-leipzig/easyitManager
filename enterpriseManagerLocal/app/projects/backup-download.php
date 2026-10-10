<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/app/project_backup.php';

$user = enterprise_require_auth('../../');
enterprise_require_capability($user,'projects.backup');
$token = trim((string)($_GET['token'] ?? ''));

try {
    $backup = enterprise_project_backup_resolve_download($token);
    $path = (string)$backup['path'];
    $filename = (string)$backup['filename'];
    $size = (int)filesize($path);

    header('Content-Type: application/zip');
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="project-backup.zip"; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Die Sicherungsdatei konnte nicht geöffnet werden.');
    }
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false) {
            fclose($handle);
            throw new RuntimeException('Die Sicherungsdatei konnte nicht vollständig gelesen werden.');
        }
        echo $chunk;
        flush();
    }
    fclose($handle);
    exit;
} catch (Throwable $e) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sicherungsdownload nicht verfügbar: ' . $e->getMessage();
    exit;
}
