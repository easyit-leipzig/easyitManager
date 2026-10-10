<?php
declare(strict_types=1);
namespace DataForm5\Installer\Core;
use DataForm5\Installer\Contracts\InstallerInterface;
use DataForm5\Installer\Exceptions\InstallerException;
final class Installer implements InstallerInterface
{
    public function __construct(private readonly string $basePath, private readonly SystemInspector $inspector, private readonly InstallationLock $lock) {}
    public function inspect(): array { return $this->inspector->inspect(); }
    public function isInstalled(): bool { return $this->lock->exists(); }
    public function status(): array { return ['installed'=>$this->isInstalled(),'lock'=>$this->lock->read(),'requirements'=>$this->inspect()]; }
    public function install(array $configuration=[]): array
    {
        if($this->isInstalled()) throw new InstallerException('DataForm5-Core ist bereits installiert.');
        $inspection=$this->inspect(); if(!$inspection['ready']) throw new InstallerException('Die Systemanforderungen sind nicht erfüllt.');
        foreach(['storage/framework/cache','storage/framework/queue','storage/logs','storage/test-reports','storage/releases','storage/recovery'] as $directory){$path=$this->basePath.'/'.$directory;if(!is_dir($path)&&!mkdir($path,0775,true)&&!is_dir($path))throw new InstallerException('Verzeichnis konnte nicht angelegt werden: '.$directory);}
        $metadata=['core_version'=>trim((string)@file_get_contents($this->basePath.'/VERSION')),'environment'=>(string)($configuration['environment']??'production'),'application_name'=>(string)($configuration['application_name']??'DataForm5 Core')];
        $this->lock->create($metadata);
        return $this->status();
    }
}
