<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$backupRoot = $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'projects';
$file = isset($_GET['file']) ? basename((string) $_GET['file']) : '';
if ($file === '' || !preg_match('/^[a-z0-9_-]+-\d{8}-\d{6}-[a-f0-9]{10}\.zip(?:\.sha256)?$/', $file)) {
    http_response_code(400);
    exit('Ungültige Backup-Datei.');
}
$path = $backupRoot . DIRECTORY_SEPARATOR . $file;
$realRoot = realpath($backupRoot);
$real = realpath($path);
if ($realRoot === false || $real === false || !is_file($real) || !str_starts_with(str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $realRoot), '/') . '/')) {
    http_response_code(404);
    exit('Backup-Datei nicht gefunden.');
}
header('Content-Type: ' . (str_ends_with($file, '.sha256') ? 'text/plain; charset=utf-8' : 'application/zip'));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . (string) filesize($real));
header('Cache-Control: no-store');
readfile($real);
