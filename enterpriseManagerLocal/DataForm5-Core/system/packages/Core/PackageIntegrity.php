<?php
declare(strict_types=1);
namespace DataForm5\Packages\Core;
use DataForm5\Core\Filesystem\Filesystem;
use DataForm5\Packages\Exceptions\PackageException;
final class PackageIntegrity
{
 public function __construct(private readonly Filesystem $fs){}
 /** @return array<string,string> */ public function calculate(string $payload):array{$out=[];foreach($this->fs->files($payload,true) as $file){$rel=str_replace('\\','/',substr($file,strlen(rtrim($payload,'/\\'))+1));$out[$rel]=$this->fs->checksum($file);}ksort($out);return$out;}
 public function verify(string $payload,PackageManifest $m):void{if($m->checksums===[])return;$actual=$this->calculate($payload);foreach($m->checksums as $file=>$hash){if(!isset($actual[$file])||!hash_equals((string)$hash,$actual[$file]))throw new PackageException("Integritätsprüfung fehlgeschlagen: {$file}");}if(count($actual)!==count($m->checksums))throw new PackageException('Integritätsprüfung fehlgeschlagen: Dateiliste weicht ab.');}
}
