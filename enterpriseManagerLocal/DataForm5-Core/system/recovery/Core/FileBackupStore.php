<?php
declare(strict_types=1);
namespace DataForm5\Recovery\Core;
use DataForm5\Recovery\Contracts\BackupStoreInterface;
use DataForm5\Recovery\Exceptions\RecoveryException;
use FilesystemIterator; use RecursiveDirectoryIterator; use RecursiveIteratorIterator;
final class FileBackupStore implements BackupStoreInterface
{
    public function __construct(private readonly string $root)
    {
        if(!is_dir($root) && !mkdir($root,0775,true) && !is_dir($root)) throw new RecoveryException('Backup-Verzeichnis konnte nicht erstellt werden.');
    }
    public function create(string $source,string $name,array $metadata=[]): array
    {
        $source=rtrim($source,'/\\'); if(!is_dir($source)) throw new RecoveryException('Sicherungsquelle ist kein Verzeichnis: '.$source);
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',trim($name)) ?: 'backup';
        $id=$safe.'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3)); $dir=$this->root.'/'.$id; $payload=$dir.'/payload';
        if(!mkdir($payload,0775,true)) throw new RecoveryException('Backup-Ziel konnte nicht erstellt werden.');
        $files=$this->copyTreeAndHash($source,$payload);
        $manifest=['format'=>1,'id'=>$id,'name'=>$safe,'created_at'=>gmdate(DATE_ATOM),'source'=>basename($source),'files'=>$files,'file_count'=>count($files),'metadata'=>$metadata];
        $manifest['integrity_hash']=$this->manifestHash($manifest);
        (new RecoveryManifest($manifest))->write($dir.'/manifest.json');
        return $manifest;
    }
    public function verify(string $backupId): bool
    {
        try{$manifest=RecoveryManifest::load($this->path($backupId).'/manifest.json')->data;}catch(\Throwable){return false;}
        $expected=$manifest['integrity_hash']??''; unset($manifest['integrity_hash']);
        if(!is_string($expected)||!hash_equals($expected,$this->manifestHash($manifest))) return false;
        foreach(($manifest['files']??[]) as $relative=>$hash){$file=$this->path($backupId).'/payload/'.$relative;if(!is_file($file)||!hash_equals((string)$hash,hash_file('sha256',$file))) return false;}
        return true;
    }
    public function restore(string $backupId,string $target): void
    {
        if(!$this->verify($backupId)) throw new RecoveryException('Backup-Integritätsprüfung fehlgeschlagen: '.$backupId);
        $target=rtrim($target,'/\\'); $parent=dirname($target); if(!is_dir($parent)&&!mkdir($parent,0775,true)&&!is_dir($parent)) throw new RecoveryException('Restore-Ziel konnte nicht vorbereitet werden.');
        $staging=$parent.'/.'.basename($target).'.restore.'.bin2hex(random_bytes(4)); $previous=$parent.'/.'.basename($target).'.previous.'.bin2hex(random_bytes(4));
        $this->copyTree($this->path($backupId).'/payload',$staging);
        try{
            if(file_exists($target)&&!rename($target,$previous)) throw new RecoveryException('Bestehendes Ziel konnte nicht gesichert werden.');
            if(!rename($staging,$target)) throw new RecoveryException('Wiederherstellung konnte nicht aktiviert werden.');
            if(is_dir($previous)) $this->removeTree($previous);
        }catch(\Throwable $e){if(is_dir($staging))$this->removeTree($staging);if(is_dir($previous)&&!file_exists($target))rename($previous,$target);throw $e;}
    }
    public function list(): array
    {
        $items=[]; foreach(new FilesystemIterator($this->root,FilesystemIterator::SKIP_DOTS) as $entry){if(!$entry->isDir()||!is_file($entry->getPathname().'/manifest.json'))continue;try{$items[]=RecoveryManifest::load($entry->getPathname().'/manifest.json')->data;}catch(\Throwable){}}
        usort($items,fn($a,$b)=>strcmp((string)($b['created_at']??''),(string)($a['created_at']??''))); return $items;
    }
    public function delete(string $backupId): void {$this->removeTree($this->path($backupId));}
    private function path(string $id): string {if(!preg_match('/^[A-Za-z0-9._-]+$/',$id))throw new RecoveryException('Ungültige Backup-ID.');return $this->root.'/'.$id;}
    private function manifestHash(array $manifest): string {return hash('sha256',json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));}
    private function copyTreeAndHash(string $source,string $target): array {$this->copyTree($source,$target);$files=[];$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,\FilesystemIterator::SKIP_DOTS));foreach($it as $file){if($file->isFile()){$rel=str_replace('\\','/',substr($file->getPathname(),strlen($target)+1));$files[$rel]=hash_file('sha256',$file->getPathname());}}ksort($files);return $files;}
    private function copyTree(string $source,string $target): void {if(!is_dir($target)&&!mkdir($target,0775,true)&&!is_dir($target))throw new RecoveryException('Verzeichnis konnte nicht erstellt werden.');$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $item){$rel=substr($item->getPathname(),strlen($source)+1);$dest=$target.'/'.$rel;if($item->isDir()){if(!is_dir($dest)&&!mkdir($dest,0775,true)&&!is_dir($dest))throw new RecoveryException('Verzeichnis konnte nicht kopiert werden.');}elseif(!copy($item->getPathname(),$dest))throw new RecoveryException('Datei konnte nicht kopiert werden: '.$rel);}}
    private function removeTree(string $path): void {if(!file_exists($path))return;if(is_file($path)||is_link($path)){unlink($path);return;}$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($path);}
}
