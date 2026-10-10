<?php
declare(strict_types=1);

namespace DataForm5\Modules\SDK;

final class ModulePackager
{
    public function __construct(
        private readonly string $enterpriseRoot,
        private readonly ModuleQualityValidator $validator
    ) {}

    public function package(string $module,?string $outputDir=null): array
    {
        $validation=$this->validator->validate($module);
        if(!$validation['valid']){
            throw new \RuntimeException('Modulvalidierung fehlgeschlagen: '.implode('; ',$validation['errors']));
        }

        $moduleDir=$this->enterpriseRoot.'/modules/'.$module;
        $manifest=json_decode((string)file_get_contents($moduleDir.'/module.json'),true);
        if(!is_array($manifest)) throw new \RuntimeException('module.json ist ungültig.');

        $outputDir=$outputDir!==null&&trim($outputDir)!==''?rtrim($outputDir,'/\\'):$this->enterpriseRoot.'/storage/module-packages';
        if(!is_dir($outputDir)&&!mkdir($outputDir,0775,true)&&!is_dir($outputDir)){
            throw new \RuntimeException('Paketverzeichnis konnte nicht angelegt werden.');
        }

        $version=(string)($manifest['version']??'0.0.0');
        $zipPath=$outputDir.'/'.$module.'-'.$version.'.zip';
        @unlink($zipPath);

        $files=$this->collect($moduleDir);
        $packageManifest=[
            'format'=>'easyit-module-package/1',
            'module'=>$module,
            'version'=>$version,
            'generated_at'=>date(DATE_ATOM),
            'validation'=>[
                'score'=>(int)$validation['score'],
                'warnings'=>$validation['warnings'],
            ],
            'files'=>[],
        ];

        foreach($files as $file){
            $rel=str_replace('\\','/',substr($file,strlen($moduleDir)+1));
            $packageManifest['files'][$rel]=[
                'sha256'=>hash_file('sha256',$file),
                'size'=>(int)(filesize($file)?:0),
            ];
        }
        ksort($packageManifest['files']);

        if(class_exists(\ZipArchive::class)){
            $this->writeWithZipArchive($zipPath,$moduleDir,$files,$packageManifest);
            $backend='ziparchive';
        }elseif(class_exists(\PharData::class)){
            $this->writeWithPharData($zipPath,$moduleDir,$files,$packageManifest);
            $backend='phardata';
        }else{
            throw new \RuntimeException('Kein ZIP-Backend verfügbar (ZipArchive oder PharData erforderlich).');
        }

        if(!is_file($zipPath)||filesize($zipPath)===0){
            throw new \RuntimeException('ZIP wurde nicht erzeugt.');
        }

        $sha=hash_file('sha256',$zipPath);
        file_put_contents($zipPath.'.sha256',$sha.'  '.basename($zipPath).PHP_EOL);

        return [
            'module'=>$module,
            'version'=>$version,
            'zip'=>$zipPath,
            'sha256'=>$sha,
            'sha256_file'=>$zipPath.'.sha256',
            'files'=>count($files),
            'validation_score'=>(int)$validation['score'],
            'backend'=>$backend,
        ];
    }

    private function writeWithZipArchive(string $zipPath,string $moduleDir,array $files,array $manifest): void
    {
        $zip=new \ZipArchive();
        if($zip->open($zipPath,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true){
            throw new \RuntimeException('ZIP konnte nicht erstellt werden.');
        }
        foreach($files as $file){
            $rel=str_replace('\\','/',substr($file,strlen($moduleDir)+1));
            $zip->addFile($file,'root/'.$rel);
        }
        $zip->addFromString('root/PACKAGE_MANIFEST.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
        $zip->close();
    }

    private function writeWithPharData(string $zipPath,string $moduleDir,array $files,array $manifest): void
    {
        try{
            $zip=new \PharData($zipPath,0,null,\Phar::ZIP);
            foreach($files as $file){
                $rel=str_replace('\\','/',substr($file,strlen($moduleDir)+1));
                $zip->addFile($file,'root/'.$rel);
            }
            $zip->addFromString('root/PACKAGE_MANIFEST.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
            unset($zip);
        }catch(\Throwable $e){
            @unlink($zipPath);
            throw new \RuntimeException('PharData-ZIP konnte nicht erstellt werden: '.$e->getMessage(),0,$e);
        }
    }

    private function collect(string $moduleDir): array
    {
        $files=[];
        $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleDir,\FilesystemIterator::SKIP_DOTS));
        foreach($iterator as $item){
            if(!$item->isFile()) continue;
            $path=$item->getPathname();
            $rel=str_replace('\\','/',substr($path,strlen($moduleDir)+1));
            if(str_starts_with($rel,'.git/')) continue;
            if($rel==='.DS_Store'||str_ends_with($rel,'/.DS_Store')) continue;
            $files[]=$path;
        }
        sort($files,SORT_STRING);
        return $files;
    }
}
