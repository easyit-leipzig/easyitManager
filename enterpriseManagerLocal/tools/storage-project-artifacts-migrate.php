<?php
declare(strict_types=1);

require dirname(__DIR__) . '/system/app/project_storage.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur CLI.\n");
}

$apply = in_array('--apply', $argv, true);
$removeLegacy = in_array('--remove-legacy', $argv, true);
$root = dirname(__DIR__);

$sets = [
    [
        'label' => 'Projekt-Löschsicherungen',
        'source' => $root . '/storage/project-delete-backups',
        'target' => enterprise_system_storage_ensure('archive/project-backups') . '/legacy',
    ],
    [
        'label' => 'Restore-Vorschauen',
        'source' => $root . '/storage/project-restore-previews',
        'target' => enterprise_system_storage_ensure('temp/project-restore-previews'),
    ],
    [
        'label' => 'Legacy-Anwenderpakete',
        'source' => $root . '/storage/project-distribution-packages',
        'target' => enterprise_system_storage_ensure('archive/legacy-project-distribution-packages'),
    ],
];

function artifact_files(string $dir): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    $base = realpath($dir);
    if ($base === false) return [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) continue;
        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($base))), '/');
        if ($rel === '.htaccess' || $rel === 'index.html') continue;
        $out[$rel] = $item->getPathname();
    }
    ksort($out);
    return $out;
}

function remove_empty_tree(string $dir): void
{
    if (!is_dir($dir)) return;
    $real = realpath($dir);
    if ($real === false) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        if ($item->isFile() || $item->isLink()) @unlink($item->getPathname());
        elseif ($item->isDir()) @rmdir($item->getPathname());
    }
    @rmdir($real);
}

$errors = 0;
foreach ($sets as $set) {
    $source = $set['source'];
    $target = $set['target'];
    $files = artifact_files($source);
    echo "\n[{$set['label']}]\n";
    echo "Quelle: {$source}\nZiel:   {$target}\n";
    if ($files === []) {
        echo "Keine Legacy-Dateien gefunden.\n";
        continue;
    }
    echo count($files) . " Datei(en) gefunden.\n";
    foreach ($files as $rel => $src) {
        $dest = rtrim($target, '/\\') . '/' . $rel;
        if (!$apply) {
            echo "PLAN  {$rel}\n";
            continue;
        }
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            fwrite(STDERR, "FEHLER Zielordner: {$dir}\n"); $errors++; continue;
        }
        $srcHash = hash_file('sha256', $src);
        if (is_file($dest)) {
            $destHash = hash_file('sha256', $dest);
            if (!is_string($srcHash) || !is_string($destHash) || !hash_equals($srcHash, $destHash)) {
                fwrite(STDERR, "KONFLIKT {$rel}\n"); $errors++; continue;
            }
            echo "OK    {$rel} (bereits identisch)\n";
        } else {
            if (!copy($src, $dest)) {
                fwrite(STDERR, "FEHLER Kopieren: {$rel}\n"); $errors++; continue;
            }
            $destHash = hash_file('sha256', $dest);
            if (!is_string($srcHash) || !is_string($destHash) || !hash_equals($srcHash, $destHash)) {
                @unlink($dest);
                fwrite(STDERR, "FEHLER SHA-256: {$rel}\n"); $errors++; continue;
            }
            echo "OK    {$rel}\n";
        }
        if ($removeLegacy && $errors === 0) @unlink($src);
    }
    if ($apply && $removeLegacy && $errors === 0) remove_empty_tree($source);
}

if (!$apply) {
    echo "\nNur Prüfung. Mit --apply kopieren; mit --apply --remove-legacy nach erfolgreicher SHA-256-Prüfung alte Bereiche entfernen.\n";
}
exit($errors === 0 ? 0 : 2);
