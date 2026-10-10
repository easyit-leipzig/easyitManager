<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use DataForm5\Core\Developer\ProfilerHub;

final class Filesystem
{
    public function exists(string $path): bool { return file_exists($path); }
    public function isFile(string $path): bool { return is_file($path); }
    public function isDirectory(string $path): bool { return is_dir($path); }

    public function ensureDirectory(string $path, int $mode = 0775): void
    {
        if (is_dir($path)) return;
        if (!@mkdir($path, $mode, true) && !is_dir($path)) {
            throw new FilesystemException("Verzeichnis konnte nicht erstellt werden: {$path}");
        }
    }

    public function read(string $path): string
    {
        $span=ProfilerHub::start('filesystem','read',['path'=>basename($path)]);
        try{
            if (!is_file($path)) throw new FilesystemException("Datei nicht gefunden: {$path}");
            $content = @file_get_contents($path);
            if ($content === false) throw new FilesystemException("Datei konnte nicht gelesen werden: {$path}");
            return $content;
        }finally{ProfilerHub::stop($span);}
    }

    public function write(string $path, string $content, bool $atomic = true): int
    {
        $span=ProfilerHub::start('filesystem','write',['path'=>basename($path),'bytes'=>strlen($content),'atomic'=>$atomic]);
        try{
        $this->ensureDirectory(dirname($path));
        if (!$atomic) {
            $bytes = @file_put_contents($path, $content, LOCK_EX);
            if ($bytes === false) throw new FilesystemException("Datei konnte nicht geschrieben werden: {$path}");
            return $bytes;
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        $bytes = @file_put_contents($tmp, $content, LOCK_EX);
        if ($bytes === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new FilesystemException("Datei konnte nicht atomar geschrieben werden: {$path}");
        }
        return $bytes;
        }finally{ProfilerHub::stop($span);}
    }

    public function append(string $path, string $content): int
    {
        $span=ProfilerHub::start('filesystem','append',['path'=>basename($path),'bytes'=>strlen($content)]);
        try{
        $this->ensureDirectory(dirname($path));
        $bytes = @file_put_contents($path, $content, FILE_APPEND | LOCK_EX);
        if ($bytes === false) throw new FilesystemException("Datei konnte nicht ergänzt werden: {$path}");
        return $bytes;
        }finally{ProfilerHub::stop($span);}
    }

    public function copy(string $source, string $destination): void
    {
        if (is_dir($source)) { $this->copyDirectory($source, $destination); return; }
        $this->ensureDirectory(dirname($destination));
        if (!@copy($source, $destination)) throw new FilesystemException("Kopieren fehlgeschlagen: {$source}");
    }

    public function move(string $source, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));
        if (!@rename($source, $destination)) throw new FilesystemException("Verschieben fehlgeschlagen: {$source}");
    }

    public function delete(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) return;
        if (is_file($path) || is_link($path)) {
            if (!@unlink($path)) throw new FilesystemException("Datei konnte nicht gelöscht werden: {$path}");
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $ok = $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$ok) throw new FilesystemException("Pfad konnte nicht gelöscht werden: {$item->getPathname()}");
        }
        if (!@rmdir($path)) throw new FilesystemException("Verzeichnis konnte nicht gelöscht werden: {$path}");
    }

    /** @return list<string> */
    public function files(string $directory, bool $recursive = false): array
    {
        if (!is_dir($directory)) return [];
        $result = [];
        if (!$recursive) {
            foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
                if ($item->isFile()) $result[] = $item->getPathname();
            }
        } else {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $item) if ($item->isFile()) $result[] = $item->getPathname();
        }
        sort($result);
        return $result;
    }

    public function checksum(string $path, string $algorithm = 'sha256'): string
    {
        if (!in_array($algorithm, hash_algos(), true)) throw new FilesystemException("Unbekannter Hash-Algorithmus: {$algorithm}");
        $hash = @hash_file($algorithm, $path);
        if ($hash === false) throw new FilesystemException("Prüfsumme konnte nicht erzeugt werden: {$path}");
        return $hash;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);
        foreach (new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS) as $item) {
            $target = $destination . '/' . $item->getBasename();
            $item->isDir() ? $this->copyDirectory($item->getPathname(), $target) : $this->copy($item->getPathname(), $target);
        }
    }
}
