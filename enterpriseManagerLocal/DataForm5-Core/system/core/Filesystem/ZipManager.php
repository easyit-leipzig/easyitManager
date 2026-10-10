<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use ZipArchive;

final class ZipManager
{
    public function available(): bool { return class_exists(ZipArchive::class); }

    public function createFromDirectory(string $sourceDirectory, string $zipFile): void
    {
        if (!$this->available()) throw new FilesystemException('ZIP-Unterstützung fehlt: PHP-Erweiterung ext-zip aktivieren.');
        if (!is_dir($sourceDirectory)) throw new FilesystemException("Quellverzeichnis nicht gefunden: {$sourceDirectory}");
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new FilesystemException("ZIP-Datei konnte nicht erstellt werden: {$zipFile}");
        }
        $root = rtrim(str_replace('\\', '/', realpath($sourceDirectory) ?: $sourceDirectory), '/');
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $full = str_replace('\\', '/', $item->getPathname());
            $relative = ltrim(substr($full, strlen($root)), '/');
            if ($item->isDir()) $zip->addEmptyDir($relative); else $zip->addFile($full, $relative);
        }
        if (!$zip->close()) throw new FilesystemException("ZIP-Datei konnte nicht abgeschlossen werden: {$zipFile}");
    }

    public function extract(string $zipFile, string $destination): void
    {
        if (!$this->available()) throw new FilesystemException('ZIP-Unterstützung fehlt: PHP-Erweiterung ext-zip aktivieren.');
        $zip = new ZipArchive();
        if ($zip->open($zipFile) !== true) throw new FilesystemException("ZIP-Datei konnte nicht geöffnet werden: {$zipFile}");
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_starts_with($name, '/') || str_contains(str_replace('\\', '/', $name), '../')) {
                $zip->close();
                throw new FilesystemException('Unsicherer Eintrag im ZIP-Archiv erkannt.');
            }
        }
        if (!$zip->extractTo($destination)) { $zip->close(); throw new FilesystemException("ZIP-Datei konnte nicht entpackt werden: {$zipFile}"); }
        $zip->close();
    }
}
