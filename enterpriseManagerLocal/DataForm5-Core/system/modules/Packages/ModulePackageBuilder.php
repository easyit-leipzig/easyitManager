<?php
declare(strict_types=1);
namespace DataForm5\Modules\Packages;
use DataForm5\Modules\SDK\ModuleValidator;
use RecursiveDirectoryIterator; use RecursiveIteratorIterator; use FilesystemIterator; use RuntimeException; use ZipArchive;
final class ModulePackageBuilder
{
    public function __construct(private readonly ModuleValidator $validator) {}
    public function build(string $moduleDirectory,string $archive): string
    {
        if(!class_exists(ZipArchive::class)) throw new RuntimeException('PHP-Erweiterung ext-zip/ZipArchive fehlt. Bitte ext-zip aktivieren.');
        $check=$this->validator->validateDirectory($moduleDirectory); if(!$check['valid']) throw new RuntimeException('Modulvalidierung fehlgeschlagen: '.implode(' | ',$check['errors']));
        $zip=new ZipArchive(); if($zip->open($archive,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('ZIP konnte nicht erzeugt werden.');
        $root=rtrim(realpath($moduleDirectory)?:$moduleDirectory,'/\\');
        try { $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST); foreach($it as $item){ $path=$item->getPathname(); $rel=ltrim(str_replace('\\','/',substr($path,strlen($root))),'/'); if($item->isDir()) $zip->addEmptyDir($rel); else $zip->addFile($path,$rel); } } finally { $zip->close(); }
        return hash_file('sha256',$archive)?:'';
    }
}
