<?php
declare(strict_types=1);
namespace DataForm5\Deployment\Core;
use RecursiveDirectoryIterator;use RecursiveIteratorIterator;use FilesystemIterator;
final class ReleaseManifest
{
    public static function create(string $sourcePath,string $version,array $metadata=[]):array
    {
        $root=realpath($sourcePath);if($root===false||!is_dir($root))throw new \InvalidArgumentException('Release-Quellpfad nicht gefunden.');
        $files=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if(!$file->isFile())continue;$relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));if(str_starts_with($relative,'storage/releases/'))continue;$files[$relative]=hash_file('sha256',$file->getPathname());}
        ksort($files);$manifest=['version'=>$version,'created_at'=>gmdate(DATE_ATOM),'php'=>PHP_VERSION,'files'=>$files,'metadata'=>$metadata];
        $manifest['integrity']=hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));return $manifest;
    }
    public static function verify(string $sourcePath,array $manifest):bool
    {
        $integrity=$manifest['integrity']??'';unset($manifest['integrity']);if(!hash_equals((string)$integrity,hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))))return false;
        foreach(($manifest['files']??[]) as $relative=>$hash){$file=rtrim($sourcePath,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);if(!is_file($file)||!hash_equals((string)$hash,hash_file('sha256',$file)))return false;}return true;
    }
}
