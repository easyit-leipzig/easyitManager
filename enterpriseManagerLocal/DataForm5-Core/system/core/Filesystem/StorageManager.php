<?php
declare(strict_types=1);

namespace DataForm5\Core\Filesystem;

use DataForm5\Core\Filesystem\Contracts\StorageDriverInterface;
use DataForm5\Core\Filesystem\Drivers\{LocalStorageDriver,SharedFilesystemStorageDriver,S3CompatibleStorageDriver};

final class StorageManager
{
    /** @var array<string,StorageDriverInterface> */
    private array $drivers=[];

    public function __construct(
        private readonly array $config,
        private readonly string $basePath,
        private readonly Filesystem $filesystem
    ) {}

    public function disk(?string $name=null): StorageDriverInterface
    {
        $name ??= (string)($this->config['default']??'local');
        if(isset($this->drivers[$name])) return $this->drivers[$name];
        $definition=$this->config['disks'][$name]??null;
        if(!is_array($definition)) throw new \RuntimeException("Storage-Disk '{$name}' ist nicht konfiguriert.");

        $driver=(string)($definition['driver']??'local');
        return $this->drivers[$name]=match($driver){
            'local'=>new LocalStorageDriver($name,$this->absolute((string)($definition['root']??'storage/app')),$this->filesystem),
            'shared','shared-filesystem'=>new SharedFilesystemStorageDriver($name,$this->absolute((string)($definition['root']??'storage/shared')),$this->filesystem),
            's3','s3-compatible'=>new S3CompatibleStorageDriver($name,$definition),
            default=>throw new \RuntimeException("Unbekannter Storage-Driver '{$driver}'."),
        };
    }

    public function defaultDisk(): StorageDriverInterface { return $this->disk(); }

    public function health(): array
    {
        $result=[];
        foreach(array_keys((array)($this->config['disks']??[])) as $name){
            try{$result[$name]=$this->disk((string)$name)->health();}
            catch(\Throwable $e){$result[$name]=['name'=>$name,'status'=>'failed','error'=>$e->getMessage()];}
        }
        return $result;
    }

    public function names(): array { return array_keys((array)($this->config['disks']??[])); }

    private function absolute(string $path): string
    {
        return str_starts_with($path,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$path)
            ? $path
            : rtrim($this->basePath,'/\\').'/'.ltrim($path,'/\\');
    }
}
