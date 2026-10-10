<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class DataFormModuleLibraryStore
{
    public function __construct(private string $root) { $this->root=rtrim($root,'/\\'); }

    /** @return list<array<string,mixed>> */
    public function all(?string $projectId=null):array
    {
        $out=[];
        foreach($this->metadataIn($this->systemDirectory(false)) as $m)$out[]=$this->summary($this->defaults($m,'system',null));
        if($projectId!==null&&$this->validId($projectId))foreach($this->metadataIn($this->projectDirectory($projectId,false)) as $m)$out[]=$this->summary($this->defaults($m,'project',$projectId));
        usort($out,static fn($a,$b)=>strcmp((string)($b['updatedAt']??''),(string)($a['updatedAt']??'')));
        return $out;
    }

    /** @return array<string,mixed> */
    public function get(string $id,?string $projectId=null):array
    {
        if(!$this->validId($id))return [];
        if($projectId!==null&&$this->validId($projectId)){$m=$this->getInScope($id,'project',$projectId);if($m!==[])return $this->defaults($m,'project',$projectId);}
        $m=$this->getInScope($id,'system',null);return $m!==[]?$this->defaults($m,'system',null):[];
    }

    /** @return array<string,mixed> */
    public function getInScope(string $id,string $visibility,?string $projectId=null):array
    {
        if(!$this->validId($id))return [];$dir=$this->scopeDirectory($visibility,$projectId,false);if($dir===null)return [];
        $path=$dir.'/'.$id.'.json';if(!is_file($path))return [];$raw=@file_get_contents($path);$m=$raw===false?null:json_decode($raw,true);
        return is_array($m)&&($m['schema']??'')==='easyit.assistant.dataform-module-library-item.v1'?$m:[];
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function save(array $metadata,string $packagePath,string $visibility='system',?string $projectId=null):array
    {
        $dir=$this->scopeDirectory($visibility,$projectId,true);if($dir===null)throw new \RuntimeException('Modulbibliothek kann nicht erstellt werden.');
        $id=trim((string)($metadata['id']??''));$this->assertId($id);if(!is_file($packagePath))throw new \RuntimeException('Modulpaket fehlt.');
        $existing=$this->getInScope($id,$visibility,$projectId);$now=gmdate('c');
        $meta=$metadata;$meta['schema']='easyit.assistant.dataform-module-library-item.v1';$meta['id']=$id;$meta['createdAt']=(string)($existing['createdAt']??$meta['createdAt']??$now);$meta['updatedAt']=$now;
        $meta['library']=array_merge(is_array($meta['library']??null)?$meta['library']:[],['visibility'=>$visibility,'ownerProject'=>$visibility==='project'?$projectId:null]);
        $meta['package']=array_merge(is_array($meta['package']??null)?$meta['package']:[],['file'=>$id.'.dataform-module.zip','sha256'=>(string)hash_file('sha256',$packagePath),'size'=>(int)filesize($packagePath)]);
        $target=$dir.'/'.$id.'.dataform-module.zip';$tmp=$target.'.tmp.'.bin2hex(random_bytes(4));if(!@copy($packagePath,$tmp)||!@rename($tmp,$target)){@unlink($tmp);throw new \RuntimeException('Modulpaket kann nicht atomar gespeichert werden.');}
        $this->atomicJson($dir.'/'.$id.'.json',$meta);return $meta;
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function updateMetadata(array $metadata,string $visibility,?string $projectId=null):array
    {
        $id=(string)($metadata['id']??'');$this->assertId($id);$path=$this->packagePath($id,$visibility,$projectId);if(!is_file($path))throw new \RuntimeException('Modulpaket fehlt.');
        return $this->save($metadata,$path,$visibility,$projectId);
    }

    public function packagePath(string $id,string $visibility='system',?string $projectId=null):string
    {
        $this->assertId($id);$dir=$this->scopeDirectory($visibility,$projectId,false);return $dir===null?'':$dir.'/'.$id.'.dataform-module.zip';
    }

    public function delete(string $id,string $visibility='system',?string $projectId=null):bool
    {
        $dir=$this->scopeDirectory($visibility,$projectId,false);if($dir===null)return false;$ok=true;
        foreach([$dir.'/'.$id.'.json',$dir.'/'.$id.'.dataform-module.zip'] as $p)if(is_file($p)&&!@unlink($p))$ok=false;return $ok;
    }

    public function makeId(string $name,string $visibility='system',?string $projectId=null):string
    {
        $this->scopeDirectory($visibility,$projectId,false);$slug=strtolower(trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',$name),'-.'));if($slug==='')$slug='dataform-module';$slug=substr($slug,0,80);$id=$slug;$n=2;
        while($this->getInScope($id,$visibility,$projectId)!==[])$id=$slug.'-'.$n++;return $id;
    }

    public function baseDirectory(string $visibility='system',?string $projectId=null,bool $create=false):?string{return $this->scopeDirectory($visibility,$projectId,$create);}

    private function systemDirectory(bool $create):?string{$d=$this->root.'/storage/assistant/modules';if($create&&!is_dir($d)&&!@mkdir($d,0770,true)&&!is_dir($d))return null;return $d;}
    private function projectDirectory(string $id,bool $create):?string{if(!$this->validId($id)||!is_dir($this->root.'/projects/'.$id))return null;$d=$this->root.'/projects/'.$id.'/config/assistant/modules';if($create&&!is_dir($d)&&!@mkdir($d,0770,true)&&!is_dir($d))return null;return $d;}
    private function scopeDirectory(string $v,?string $p,bool $create):?string{if(!in_array($v,['system','project'],true))throw new \InvalidArgumentException('Ungültige Modulsichtbarkeit.');if($v==='project'){if($p===null||!$this->validId($p)||!is_dir($this->root.'/projects/'.$p))throw new \InvalidArgumentException('Für ein Projektmodul ist ein gültiges Projekt erforderlich.');return $this->projectDirectory($p,$create);}return $this->systemDirectory($create);}
    /** @return list<array<string,mixed>> */
    private function metadataIn(?string $d):array{$out=[];if($d===null||!is_dir($d))return [];foreach(glob($d.'/*.json')?:[] as $p){$raw=@file_get_contents($p);$m=$raw===false?null:json_decode($raw,true);if(is_array($m)&&($m['schema']??'')==='easyit.assistant.dataform-module-library-item.v1')$out[]=$m;}return $out;}
    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function summary(array $m):array{return ['id'=>(string)($m['id']??''),'name'=>(string)($m['name']??''),'description'=>(string)($m['description']??''),'visibility'=>(string)($m['library']['visibility']??'system'),'ownerProject'=>$m['library']['ownerProject']??null,'version'=>(int)($m['library']['version']??1),'releaseVersion'=>(string)($m['release']['version']??'1.0.0'),'dependencyCount'=>count((array)($m['release']['dependencies']??[])),'migrationCount'=>count((array)($m['release']['migrations']??[])),'releaseGateStatus'=>(string)($m['releaseGate']['status']??'LEGACY_RELEASED'),'installable'=>!array_key_exists('installable',(array)($m['releaseGate']??[]))||!empty($m['releaseGate']['installable']),'sourceProject'=>(string)($m['source']['projectId']??''),'dataForms'=>(array)($m['module']['dataForms']??[]),'rootDataForms'=>(array)($m['module']['rootDataForms']??[]),'createdAt'=>(string)($m['createdAt']??''),'updatedAt'=>(string)($m['updatedAt']??''),'sha256'=>(string)($m['package']['sha256']??'')];}
    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function defaults(array $m,string $v,?string $p):array{$m['library']=array_merge(['visibility'=>$v,'ownerProject'=>$v==='project'?$p:null,'version'=>1],is_array($m['library']??null)?$m['library']:[]);$m['release']=array_merge(['version'=>'1.0.0','dependencies'=>[],'migrations'=>[]],is_array($m['release']??null)?$m['release']:[]);$m['releaseGate']=array_merge(['schema'=>'easyit.dataform.module-release-gate.v1','status'=>'LEGACY_RELEASED','installable'=>true,'legacy'=>true],is_array($m['releaseGate']??null)?$m['releaseGate']:[]);return $m;}
    private function assertId(string $id):void{if(!$this->validId($id))throw new \InvalidArgumentException('Ungültige Modul-ID.');}
    private function validId(string $id):bool{return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id);}
    /** @param array<string,mixed> $m */
    private function atomicJson(string $path,array $m):void{$j=json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false)throw new \RuntimeException('Modulmetadaten können nicht serialisiert werden.');$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$j,LOCK_EX)===false||!@rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Modulmetadaten können nicht atomar gespeichert werden.');}}
}
