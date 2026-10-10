<?php
declare(strict_types=1);
namespace DataForm5\Installer\Core;
use DataForm5\Installer\Exceptions\InstallerException;
final class InstallationLock
{
    public function __construct(private readonly string $file) {}
    public function exists(): bool { return is_file($this->file); }
    public function read(): array { if(!$this->exists()) return []; $data=json_decode((string)file_get_contents($this->file),true); return is_array($data)?$data:[]; }
    public function create(array $metadata): void
    {
        if ($this->exists()) throw new InstallerException('Die Installation ist bereits abgeschlossen.');
        $directory=dirname($this->file); if(!is_dir($directory)&&!mkdir($directory,0775,true)&&!is_dir($directory)) throw new InstallerException('Installationsverzeichnis konnte nicht angelegt werden.');
        $payload=['installed_at'=>gmdate(DATE_ATOM),'php_version'=>PHP_VERSION]+$metadata;
        $tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) throw new InstallerException('Installations-Lock konnte nicht geschrieben werden.');
        if(!rename($tmp,$this->file)){@unlink($tmp);throw new InstallerException('Installations-Lock konnte nicht aktiviert werden.');}
    }
}
