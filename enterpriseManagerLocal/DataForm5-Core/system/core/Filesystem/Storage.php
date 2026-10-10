<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem;

final class Storage
{
    public function __construct(private readonly string $root, private readonly Filesystem $filesystem)
    {
        $this->filesystem->ensureDirectory($this->root);
    }

    public function path(string $relativePath = ''): string { return PathGuard::within($this->root, $relativePath); }
    public function exists(string $path): bool { return $this->filesystem->exists($this->path($path)); }
    public function read(string $path): string { return $this->filesystem->read($this->path($path)); }
    public function write(string $path, string $content, bool $atomic = true): int { return $this->filesystem->write($this->path($path), $content, $atomic); }
    public function append(string $path, string $content): int { return $this->filesystem->append($this->path($path), $content); }
    public function delete(string $path): void { $this->filesystem->delete($this->path($path)); }
    public function copy(string $source, string $destination): void { $this->filesystem->copy($this->path($source), $this->path($destination)); }
    public function move(string $source, string $destination): void { $this->filesystem->move($this->path($source), $this->path($destination)); }
    /** @return list<string> */
    public function files(string $directory = '', bool $recursive = false): array { return $this->filesystem->files($this->path($directory), $recursive); }
}
