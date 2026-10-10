<?php
declare(strict_types=1);
namespace DataForm5\Modules\Migrations;

use PDO;
use RuntimeException;
use Throwable;

final class ModuleMigrationManager
{
    public function __construct(private readonly PDO $pdo,private readonly ModuleMigrationRepository $repository) {}

    /** @return list<array{version:string,file:string,checksum:string,applied:bool,changed:bool}> */
    public function status(string $module,string $modulePath): array
    {
        $applied=$this->repository->applied($module); $rows=[];
        foreach($this->files($modulePath) as $version=>$file){
            $checksum=hash_file('sha256',$file) ?: '';
            $rows[]=['version'=>$version,'file'=>$file,'checksum'=>$checksum,'applied'=>isset($applied[$version]),'changed'=>isset($applied[$version]) && !hash_equals((string)$applied[$version]['checksum'],$checksum)];
        }
        foreach($applied as $version=>$record) if(!isset($this->files($modulePath)[$version])) $rows[]=['version'=>$version,'file'=>'','checksum'=>(string)$record['checksum'],'applied'=>true,'changed'=>true];
        usort($rows,fn($a,$b)=>strcmp($a['version'],$b['version'])); return $rows;
    }

    /** @return array{applied:list<string>,pending:int} */
    public function migrate(string $module,string $modulePath): array
    {
        $files=$this->files($modulePath); $applied=$this->repository->applied($module);
        foreach($files as $version=>$file){
            $checksum=hash_file('sha256',$file) ?: '';
            if(isset($applied[$version]) && !hash_equals((string)$applied[$version]['checksum'],$checksum)) throw new RuntimeException("Migration {$module}:{$version} wurde nachträglich verändert.");
        }
        $pending=array_diff_key($files,$applied); if($pending===[]) return ['applied'=>[],'pending'=>0];
        $batch=$this->repository->nextBatch($module); $done=[];
        try{
            foreach($pending as $version=>$file){ $migration=$this->load($file); $this->transactional($migration['up']); $this->repository->record($module,$version,hash_file('sha256',$file) ?: '',$batch); $done[]=$version; }
        }catch(Throwable $e){
            foreach(array_reverse($done) as $version){ try{$migration=$this->load($files[$version]); if(is_callable($migration['down']??null)) $this->transactional($migration['down']); $this->repository->forget($module,$version);}catch(Throwable){} }
            throw new RuntimeException('Modulmigration fehlgeschlagen: '.$e->getMessage(),0,$e);
        }
        return ['applied'=>$done,'pending'=>0];
    }

    /** @return list<string> */
    public function rollbackLastBatch(string $module,string $modulePath): array
    {
        $applied=$this->repository->applied($module); if($applied===[]) return [];
        $max=max(array_map(fn($r)=>(int)$r['batch'],$applied)); $files=$this->files($modulePath); $versions=[];
        foreach($applied as $version=>$row) if((int)$row['batch']===$max) $versions[]=$version;
        rsort($versions,SORT_STRING); $rolled=[];
        foreach($versions as $version){ if(!isset($files[$version])) throw new RuntimeException("Rollback-Datei fehlt: {$module}:{$version}"); $migration=$this->load($files[$version]); if(!is_callable($migration['down']??null)) throw new RuntimeException("Migration {$module}:{$version} besitzt kein Rollback."); $this->transactional($migration['down']); $this->repository->forget($module,$version); $rolled[]=$version; }
        return $rolled;
    }

    /** @return array<string,string> */
    private function files(string $modulePath): array
    {
        $dir=rtrim($modulePath,'/\\').'/database/migrations'; if(!is_dir($dir)) return [];
        $files=[]; foreach(glob($dir.'/*.php') ?: [] as $file){$version=basename($file,'.php'); if(!preg_match('/^[A-Za-z0-9._-]+$/',$version)) continue; $files[$version]=$file;} ksort($files,SORT_STRING); return $files;
    }
    /** @return array{up:callable,down?:callable} */
    private function load(string $file): array
    {
        $migration=require $file; if(!is_array($migration)||!is_callable($migration['up']??null)) throw new RuntimeException('Ungültige Modulmigration: '.basename($file)); return $migration;
    }
    private function transactional(callable $callback): void
    {
        $owns=!$this->pdo->inTransaction(); if($owns)$this->pdo->beginTransaction();
        try{$callback($this->pdo); if($owns && $this->pdo->inTransaction())$this->pdo->commit();}
        catch(Throwable $e){if($owns && $this->pdo->inTransaction())$this->pdo->rollBack(); throw $e;}
    }
}
