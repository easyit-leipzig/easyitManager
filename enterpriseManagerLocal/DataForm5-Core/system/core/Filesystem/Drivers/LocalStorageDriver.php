<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem\Drivers;

use DataForm5\Core\Filesystem\Contracts\StorageDriverInterface;
use DataForm5\Core\Filesystem\Filesystem;
use DataForm5\Core\Filesystem\PathGuard;

class LocalStorageDriver implements StorageDriverInterface
{
    public function __construct(
        private readonly string $driverName,
        private readonly string $basePath,
        private readonly Filesystem $filesystem
    ) {
        $this->filesystem->ensureDirectory($this->basePath);
    }

    public function name(): string { return $this->driverName; }
    public function root(): string { return $this->basePath; }
    private function path(string $relative=''): string { return PathGuard::within($this->basePath,$relative); }
    public function exists(string $path): bool { return $this->filesystem->exists($this->path($path)); }
    public function read(string $path): string { return $this->filesystem->read($this->path($path)); }
    public function write(string $path,string $content,bool $atomic=true): int { return $this->filesystem->write($this->path($path),$content,$atomic); }
    public function append(string $path,string $content): int { return $this->filesystem->append($this->path($path),$content); }
    public function delete(string $path): void { $this->filesystem->delete($this->path($path)); }
    public function copy(string $source,string $destination): void { $this->filesystem->copy($this->path($source),$this->path($destination)); }
    public function move(string $source,string $destination): void { $this->filesystem->move($this->path($source),$this->path($destination)); }
    public function files(string $directory='',bool $recursive=false): array { return $this->filesystem->files($this->path($directory),$recursive); }

    public function health(): array
    {
        $writable=is_dir($this->basePath)&&is_writable($this->basePath);
        $free=@disk_free_space($this->basePath);
        $total=@disk_total_space($this->basePath);
        return [
            'driver'=>'local',
            'name'=>$this->driverName,
            'root'=>$this->basePath,
            'writable'=>$writable,
            'free_bytes'=>$free===false?null:(int)$free,
            'total_bytes'=>$total===false?null:(int)$total,
            'status'=>$writable?'healthy':'degraded',
        ];
    }
}
