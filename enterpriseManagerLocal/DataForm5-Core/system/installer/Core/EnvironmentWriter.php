<?php
declare(strict_types=1);

namespace DataForm5\Installer\Core;

use DataForm5\Installer\Exceptions\InstallerException;

final class EnvironmentWriter
{
    public function __construct(private readonly string $file) {}

    public function write(array $values): void
    {
        $lines=[];
        foreach($values as $key=>$value){
            if(!preg_match('/^[A-Z0-9_]+$/',(string)$key)) continue;
            $value=(string)$value;
            $escaped=str_replace(["\\","\"","\r","\n"],["\\\\","\\\"","",""],$value);
            $lines[]=$key.'="'.$escaped.'"';
        }
        $dir=dirname($this->file);
        if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)){
            throw new InstallerException('Konfigurationsverzeichnis konnte nicht angelegt werden.');
        }
        $tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));
        if(file_put_contents($tmp,implode(PHP_EOL,$lines).PHP_EOL,LOCK_EX)===false||!@rename($tmp,$this->file)){
            @unlink($tmp);
            throw new InstallerException('.env konnte nicht geschrieben werden.');
        }
        @chmod($this->file,0600);
    }
}
