<?php
declare(strict_types=1);
namespace DataForm5\Packages\Core;
use DataForm5\Core\Filesystem\Filesystem;
final class PackageRegistry
{
    public function __construct(private readonly string $file, private readonly Filesystem $fs){}
    /** @return array<string,array<string,mixed>> */ public function all():array{if(!is_file($this->file))return[];$d=json_decode($this->fs->read($this->file),true);return is_array($d)?$d:[];}
    /** @return array<string,mixed>|null */ public function get(string $name):?array{return $this->all()[$name]??null;}
    /** @param array<string,mixed> $record */ public function put(string $name,array $record):void{$all=$this->all();$all[$name]=$record;ksort($all);$this->fs->write($this->file,json_encode($all,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");}
    public function remove(string $name):void{$all=$this->all();unset($all[$name]);$this->fs->write($this->file,json_encode($all,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");}
}
