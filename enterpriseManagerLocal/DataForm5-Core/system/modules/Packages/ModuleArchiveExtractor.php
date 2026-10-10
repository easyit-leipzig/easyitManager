<?php
declare(strict_types=1);
namespace DataForm5\Modules\Packages;
use DataForm5\Core\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;
final class ModuleArchiveExtractor
{
    public function __construct(private readonly Filesystem $fs, private readonly int $maxBytes=20971520, private readonly int $maxFiles=1000) {}
    public function extract(string $archive,string $destination): string
    {
        if(!class_exists(ZipArchive::class)) throw new RuntimeException('PHP-Erweiterung ext-zip/ZipArchive fehlt. Bitte ext-zip aktivieren.');
        if(!is_file($archive)) throw new RuntimeException('Modul-ZIP nicht gefunden: '.$archive);
        $zip=new ZipArchive(); if($zip->open($archive)!==true) throw new RuntimeException('Modul-ZIP konnte nicht geöffnet werden.');
        try {
            if($zip->numFiles>$this->maxFiles) throw new RuntimeException('Modul-ZIP enthält zu viele Dateien.');
            $total=0;
            for($i=0;$i<$zip->numFiles;$i++){
                $stat=$zip->statIndex($i); if(!is_array($stat)) throw new RuntimeException('ZIP-Eintrag konnte nicht gelesen werden.');
                $name=str_replace('\\','/',(string)$stat['name']);
                if($name==='' || str_starts_with($name,'/') || preg_match('~(^|/)\.\.(/|$)~',$name) || preg_match('~^[A-Za-z]:/~',$name)) throw new RuntimeException('Unsicherer ZIP-Pfad: '.$name);
                $total+=(int)($stat['size']??0); if($total>$this->maxBytes) throw new RuntimeException('Modul-ZIP überschreitet das Größenlimit.');
            }
            $this->fs->ensureDirectory($destination);
            if(!$zip->extractTo($destination)) throw new RuntimeException('Modul-ZIP konnte nicht entpackt werden.');
        } finally { $zip->close(); }
        $root=$this->detectModuleRoot($destination);
        return $root;
    }
    private function detectModuleRoot(string $directory): string
    {
        if(is_file($directory.'/module.json')) return $directory;
        $items=array_values(array_filter(scandir($directory)?:[],static fn(string $x):bool=>!in_array($x,['.','..','__MACOSX'],true)));
        if(count($items)===1 && is_dir($directory.'/'.$items[0]) && is_file($directory.'/'.$items[0].'/module.json')) return $directory.'/'.$items[0];
        throw new RuntimeException('ZIP muss module.json im Wurzelverzeichnis oder in genau einem Modul-Unterordner enthalten.');
    }
}
