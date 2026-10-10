<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem;

final class BackupManager
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly ZipManager $zip,
        private readonly string $backupRoot
    ) { $this->filesystem->ensureDirectory($this->backupRoot); }

    public function create(string $source, string $name, bool $preferZip = true): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'backup';
        $stamp = date('Ymd_His');
        if ($preferZip && $this->zip->available()) {
            $target = $this->backupRoot . '/' . $safeName . '_' . $stamp . '.zip';
            $this->zip->createFromDirectory($source, $target);
            return $target;
        }
        $target = $this->backupRoot . '/' . $safeName . '_' . $stamp;
        $this->filesystem->copy($source, $target);
        return $target;
    }
}
