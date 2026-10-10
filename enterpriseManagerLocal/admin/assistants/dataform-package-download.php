<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$file = basename((string)($_GET['file'] ?? ''));
if ($file === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $file) || (!str_ends_with($file, '.dataform-package.zip') && !str_ends_with($file, '.dataform-package.zip.sha256'))) {
    http_response_code(400); exit('Ungültiger Dateiname.');
}
$path = $root . '/storage/assistant/transfers/exports/' . $file;
if (!is_file($path)) { http_response_code(404); exit('Datei nicht gefunden.'); }
header('Cache-Control: no-store');
header('Content-Type: ' . (str_ends_with($file, '.sha256') ? 'text/plain; charset=utf-8' : 'application/zip'));
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
