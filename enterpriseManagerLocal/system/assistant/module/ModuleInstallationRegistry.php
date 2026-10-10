<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class ModuleInstallationRegistry
{
    public function __construct(private string $root){$this->root=rtrim($root,'/\\');}

    /** @return array<string,mixed> */
    public function document(string $projectId):array
    {
        $this->assertProject($projectId);$path=$this->path($projectId);
        if(!is_file($path))return ['schema'=>'easyit.assistant.module-installations.v1','projectId'=>$projectId,'updatedAt'=>null,'modules'=>[]];
        $raw=@file_get_contents($path);$d=$raw===false?null:json_decode($raw,true);
        if(!is_array($d)||($d['schema']??'')!=='easyit.assistant.module-installations.v1')return ['schema'=>'easyit.assistant.module-installations.v1','projectId'=>$projectId,'updatedAt'=>null,'modules'=>[]];
        $d['modules']=is_array($d['modules']??null)?$d['modules']:[];return $d;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $projectId):array
    {
        $mods=array_values((array)$this->document($projectId)['modules']);
        usort($mods,static fn($a,$b)=>strcmp((string)($a['id']??''),(string)($b['id']??'')));return $mods;
    }

    /** @return array<string,mixed> */
    public function get(string $projectId,string $moduleId):array
    {
        $mods=(array)$this->document($projectId)['modules'];return is_array($mods[$moduleId]??null)?$mods[$moduleId]:[];
    }

    /** @param array<string,mixed> $module @return array<string,mixed> */
    public function register(string $projectId,array $module):array
    {
        $this->assertProject($projectId);$id=trim((string)($module['id']??''));if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige installierte Modul-ID.');
        $version=SemVersion::normalize((string)($module['version']??'1.0.0'));$doc=$this->document($projectId);$now=gmdate('c');$old=is_array($doc['modules'][$id]??null)?$doc['modules'][$id]:[];
        $entry=array_merge($module,['id'=>$id,'version'=>$version,'installedAt'=>$now,'firstInstalledAt'=>(string)($old['firstInstalledAt']??$now)]);
        $doc['modules'][$id]=$entry;$doc['updatedAt']=$now;$this->write($projectId,$doc);return $entry;
    }

    public function remove(string $projectId,string $moduleId):bool
    {
        $doc=$this->document($projectId);if(!isset($doc['modules'][$moduleId]))return false;unset($doc['modules'][$moduleId]);$doc['updatedAt']=gmdate('c');$this->write($projectId,$doc);return true;
    }

    private function path(string $projectId):string{return $this->root.'/projects/'.$projectId.'/config/assistant/module-installations.json';}
    private function assertProject(string $projectId):void{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$projectId)||!is_dir($this->root.'/projects/'.$projectId))throw new \InvalidArgumentException('Projekt für Modulinstallationsregister fehlt: '.$projectId);}
    /** @param array<string,mixed> $doc */
    private function write(string $projectId,array $doc):void{$path=$this->path($projectId);$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new \RuntimeException('Installationsregister-Verzeichnis kann nicht angelegt werden.');$json=json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new \RuntimeException('Installationsregister kann nicht serialisiert werden.');$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$json."\n",LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Installationsregister kann nicht atomar geschrieben werden.');}}
}
