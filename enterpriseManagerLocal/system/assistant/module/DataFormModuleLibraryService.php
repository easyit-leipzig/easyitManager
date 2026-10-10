<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class DataFormModuleLibraryService
{
    public function __construct(private DataFormModuleLibraryStore $store,private DataFormModulePackageService $packages,private string $root,private ?ReleaseCatalogService $releaseCatalog=null){$this->root=rtrim($root,'/\\');}

    /** @return list<array<string,mixed>> */ public function list(?string $projectId=null):array{return $this->store->all($projectId);}
    /** @return array<string,mixed> */ public function get(string $id,?string $projectId=null):array{return $this->store->get($id,$projectId);}
    /** @return array<string,mixed> */ public function getScoped(string $id,string $visibility,?string $projectId=null):array{$m=$this->store->getInScope($id,$visibility,$projectId);if($m===[])return [];$m['release']=array_merge(['version'=>'1.0.0','dependencies'=>[],'migrations'=>[]],is_array($m['release']??null)?$m['release']:[]);$m['releaseGate']=array_merge(['schema'=>'easyit.dataform.module-release-gate.v1','status'=>'LEGACY_RELEASED','installable'=>true,'legacy'=>true],is_array($m['releaseGate']??null)?$m['releaseGate']:[]);return $m;}

    /** @param list<string> $roots @return array<string,mixed> */
    public function createFromProject(string $sourceProject,string $name,string $description,array $roots,bool $transitive,bool $includeDatasource,string $visibility,?string $ownerProject):array
    {
        $this->assertScope($visibility,$ownerProject);$export=$this->packages->export($sourceProject,$name,$roots,$transitive,$includeDatasource);$inspection=$this->packages->inspect((string)$export['path']);if(!($inspection['ok']??false))throw new \RuntimeException('Erzeugtes Modulpaket ist ungültig.');
        $id=$this->store->makeId($name,$visibility,$ownerProject);$meta=$this->metadata($id,$name,$description,$visibility,$ownerProject,$inspection,['createdFrom'=>'project']);$saved=$this->store->save($meta,(string)$export['path'],$visibility,$ownerProject);$this->snapshot($saved,$visibility,$ownerProject,'create');return $saved;
    }

    /** @return array<string,mixed> */
    public function importPath(string $path,string $name,string $description,string $visibility,?string $ownerProject):array
    {
        $this->assertScope($visibility,$ownerProject);$inspection=$this->packages->inspect($path);if(!($inspection['ok']??false))throw new \RuntimeException('Modulpaket ist ungültig: '.implode(' ',(array)($inspection['errors']??[])));
        $base=trim($name)!==''?trim($name):trim((string)($inspection['manifest']['module']['name']??'Importiertes Modul'));$id=$this->store->makeId($base,$visibility,$ownerProject);$meta=$this->metadata($id,$base,$description,$visibility,$ownerProject,$inspection,['importedAt'=>gmdate('c')]);$saved=$this->store->save($meta,$path,$visibility,$ownerProject);$this->snapshot($saved,$visibility,$ownerProject,'import');return $saved;
    }

    /** @param array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $upload @return array<string,mixed> */
    public function importUpload(array $upload,string $name,string $description,string $visibility,?string $ownerProject):array
    {
        $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)throw new \RuntimeException('Moduldatei wurde nicht korrekt hochgeladen (Code '.$error.').');$tmp=(string)($upload['tmp_name']??'');if($tmp===''||!is_file($tmp))throw new \RuntimeException('Temporäre Moduldatei fehlt.');if((int)($upload['size']??filesize($tmp)?:0)>50*1024*1024)throw new \RuntimeException('Moduldatei ist größer als 50 MiB.');return $this->importPath($tmp,$name,$description,$visibility,$ownerProject);
    }

    /** @return list<array<string,mixed>> */
    public function history(string $id,string $visibility,?string $projectId):array
    {
        $dir=$this->versionDir($id,$visibility,$projectId,false);if($dir===null||!is_dir($dir)||(glob($dir.'/v*.json')?:[])===[]){$m=$this->must($id,$visibility,$projectId);$this->snapshot($m,$visibility,$projectId,'baseline');$dir=$this->versionDir($id,$visibility,$projectId,false);}if($dir===null||!is_dir($dir))return [];$out=[];
        foreach(glob($dir.'/v*.json')?:[] as $p){$m=json_decode((string)@file_get_contents($p),true);if(!is_array($m))continue;$out[]=['version'=>(int)($m['_version']['number']??0),'releaseVersion'=>(string)($m['release']['version']??'1.0.0'),'dependencyCount'=>count((array)($m['release']['dependencies']??[])),'releaseGateStatus'=>(string)($m['releaseGate']['status']??'LEGACY_RELEASED'),'installable'=>!array_key_exists('installable',(array)($m['releaseGate']??[]))||!empty($m['releaseGate']['installable']),'reason'=>(string)($m['_version']['reason']??''),'createdAt'=>(string)($m['_version']['createdAt']??''),'name'=>(string)($m['name']??''),'sha256'=>(string)($m['package']['sha256']??'')];}usort($out,static fn($a,$b)=>$b['version']<=>$a['version']);return $out;
    }

    /** @param list<array{moduleId:string,constraint:string,optional:bool}> $dependencies @return array<string,mixed> */
    public function setRelease(string $id,string $visibility,?string $projectId,string $version,array $dependencies,array $migrations=[],bool $requireGate=false):array
    {
        $m=$this->must($id,$visibility,$projectId);$normalized=SemVersion::normalize($version);$release=['schema'=>'easyit.dataform.module-release.v1','version'=>$normalized,'dependencies'=>$dependencies,'migrations'=>$migrations];$gate=$requireGate?['schema'=>'easyit.dataform.module-release-gate.v1','status'=>'DRAFT','installable'=>false,'legacy'=>false,'releaseVersion'=>$normalized,'reportId'=>null,'reportSha256'=>null,'releaseFingerprint'=>null,'validationProject'=>null,'checkedAt'=>null,'releasedAt'=>null,'warningsAccepted'=>false]:null;
        $this->snapshot($m,$visibility,$projectId,'before-release-change');$package=$this->packages->withReleaseMetadata($this->store->packagePath($id,$visibility,$projectId),$release,$gate);$inspection=$this->packages->inspect($package);if(!($inspection['ok']??false))throw new \RuntimeException('Release-Paketprüfung fehlgeschlagen.');
        $m['release']=$inspection['release'];$m['releaseGate']=$inspection['releaseGate'];$m['library']['version']=$this->nextVersion($id,$visibility,$projectId);$saved=$this->store->save($m,$package,$visibility,$projectId);$this->snapshot($saved,$visibility,$projectId,'release-change');@unlink($package);return $saved;
    }

    /** @param array<string,mixed> $gate @return array<string,mixed> */
    public function updateReleaseGate(string $id,string $visibility,?string $projectId,array $gate,string $reason='release-gate'):array
    {
        $m=$this->must($id,$visibility,$projectId);$this->snapshot($m,$visibility,$projectId,'before-'.$reason);$package=$this->packages->withReleaseGateMetadata($this->store->packagePath($id,$visibility,$projectId),$gate);$inspection=$this->packages->inspect($package);if(!($inspection['ok']??false))throw new \RuntimeException('Release-Gate-Paketprüfung fehlgeschlagen.');$m['releaseGate']=$inspection['releaseGate'];$m['library']['version']=$this->nextVersion($id,$visibility,$projectId);$saved=$this->store->save($m,$package,$visibility,$projectId);$this->snapshot($saved,$visibility,$projectId,$reason);@unlink($package);return $saved;
    }

    /** @return array<string,mixed> */
    public function rename(string $id,string $name,string $description,string $visibility,?string $projectId):array
    {
        $m=$this->must($id,$visibility,$projectId);$this->snapshot($m,$visibility,$projectId,'before-rename');$name=trim($name);if($name==='')throw new \InvalidArgumentException('Modulname darf nicht leer sein.');$m['name']=$name;$m['description']=trim($description);$m['library']['version']=$this->nextVersion($id,$visibility,$projectId);$saved=$this->store->updateMetadata($m,$visibility,$projectId);$this->snapshot($saved,$visibility,$projectId,'rename');return $saved;
    }

    /** @return array<string,mixed> */
    public function copy(string $id,string $newName,string $sourceVisibility,?string $sourceProject,string $targetVisibility,?string $targetProject):array
    {
        $m=$this->must($id,$sourceVisibility,$sourceProject);$this->assertScope($targetVisibility,$targetProject);$name=trim($newName)!==''?trim($newName):((string)$m['name'].' Kopie');$newId=$this->store->makeId($name,$targetVisibility,$targetProject);unset($m['createdAt'],$m['updatedAt'],$m['_version']);$m['id']=$newId;$m['name']=$name;$m['library']=['visibility'=>$targetVisibility,'ownerProject'=>$targetVisibility==='project'?$targetProject:null,'version'=>1,'copiedFrom'=>$id];$saved=$this->store->save($m,$this->store->packagePath($id,$sourceVisibility,$sourceProject),$targetVisibility,$targetProject);$this->snapshot($saved,$targetVisibility,$targetProject,'copy');return $saved;
    }

    /** @return array<string,mixed> */
    public function changeVisibility(string $id,string $sourceVisibility,?string $sourceProject,string $targetVisibility,?string $targetProject):array
    {
        $m=$this->must($id,$sourceVisibility,$sourceProject);$this->assertScope($targetVisibility,$targetProject);if($sourceVisibility===$targetVisibility&&($sourceVisibility!=='project'||$sourceProject===$targetProject))return $m;if($this->store->getInScope($id,$targetVisibility,$targetProject)!==[])throw new \RuntimeException('Im Zielbereich existiert bereits ein Modul mit derselben ID.');
        $this->snapshot($m,$sourceVisibility,$sourceProject,'before-visibility-change');$this->copyHistory($id,$sourceVisibility,$sourceProject,$targetVisibility,$targetProject);$m['library']['visibility']=$targetVisibility;$m['library']['ownerProject']=$targetVisibility==='project'?$targetProject:null;$m['library']['version']=$this->nextVersion($id,$targetVisibility,$targetProject);$saved=$this->store->save($m,$this->store->packagePath($id,$sourceVisibility,$sourceProject),$targetVisibility,$targetProject);$this->store->delete($id,$sourceVisibility,$sourceProject);$this->snapshot($saved,$targetVisibility,$targetProject,'visibility-change');return $saved;
    }

    /** @return array<string,mixed> */
    public function softDelete(string $id,string $visibility,?string $projectId,string $confirmedName):array
    {
        $m=$this->must($id,$visibility,$projectId);if(trim($confirmedName)!==trim((string)$m['name']))throw new \RuntimeException('Der eingegebene Modulname stimmt nicht überein. Löschen abgebrochen.');$this->snapshot($m,$visibility,$projectId,'before-delete');$base=$this->store->baseDirectory($visibility,$projectId,true);if($base===null)throw new \RuntimeException('Modulbibliothek fehlt.');$trash=$base.'/.trash';$this->ensure($trash);$stamp=gmdate('Ymd-His');$package=$this->store->packagePath($id,$visibility,$projectId);$m['library']['deletedAt']=gmdate('c');$m['library']['deletedFrom']=['visibility'=>$visibility,'projectId'=>$projectId];$this->writeJson($trash.'/'.$id.'-'.$stamp.'.json',$m);if(!@copy($package,$trash.'/'.$id.'-'.$stamp.'.dataform-module.zip'))throw new \RuntimeException('Modulpaket konnte nicht in den Papierkorb kopiert werden.');if(!$this->store->delete($id,$visibility,$projectId))throw new \RuntimeException('Modul konnte nicht aus der Bibliothek entfernt werden.');return ['deleted'=>true,'id'=>$id,'name'=>$m['name'],'trash'=>$trash];
    }

    public function exportPath(string $id,string $visibility,?string $projectId):string{$this->must($id,$visibility,$projectId);$p=$this->store->packagePath($id,$visibility,$projectId);if(!is_file($p))throw new \RuntimeException('Modulpaket wurde nicht gefunden.');return $p;}

    /** @return array<string,mixed> */
    public function stage(string $id,string $visibility,?string $projectId,bool $allowUnreleased=false):array{$m=$this->must($id,$visibility,$projectId);$g=is_array($m['releaseGate']??null)?$m['releaseGate']:[];if(!$allowUnreleased&&$g!==[]&&empty($g['installable']))throw new \RuntimeException('Release ist nicht installierbar (Gate-Status '.(string)($g['status']??'DRAFT').').');if(!$allowUnreleased&&$this->releaseCatalog!==null&&!empty($g)&&empty($g['legacy'])&&($g['status']??'')==='RELEASED'){$verification=$this->releaseCatalog->verifyLibraryRelease($id,$visibility,$projectId);if(empty($verification['ok']))throw new \RuntimeException('Release-Vertrauensprüfung fehlgeschlagen: '.implode(' ',(array)($verification['errors']??[])));}return $this->packages->stagePath($this->exportPath($id,$visibility,$projectId));}
    /** @param array<string,string> $dfMap @param array<string,string> $refMap @return array<string,mixed> */
    public function previewInstall(string $token,string $targetProject,array $dfMap,array $refMap,bool $overwrite,bool $datasource):array{return $this->packages->preview($token,$targetProject,$dfMap,$refMap,$overwrite,$datasource);}
    /** @param array<string,string> $dfMap @param array<string,string> $refMap @return array<string,mixed> */
    public function applyInstall(string $token,string $targetProject,array $dfMap,array $refMap,bool $overwrite,bool $datasource):array{return $this->packages->apply($token,$targetProject,$dfMap,$refMap,$overwrite,$datasource);}

    /** @return array<string,mixed> */
    private function metadata(string $id,string $name,string $description,string $visibility,?string $owner,array $inspection,array $extra):array
    {
        $manifest=(array)($inspection['manifest']??[]);return ['schema'=>'easyit.assistant.dataform-module-library-item.v1','id'=>$id,'name'=>trim($name),'description'=>trim($description),'source'=>['projectId'=>(string)($manifest['module']['sourceProject']??'')],'module'=>['rootDataForms'=>(array)($manifest['module']['rootDataForms']??[]),'dataForms'=>(array)($manifest['module']['dataForms']??[]),'transitive'=>(bool)($manifest['module']['transitive']??false),'graph'=>(array)($inspection['graph']??[]),'dependencies'=>(array)($inspection['dependencies']??[])],'release'=>array_merge(['version'=>'1.0.0','dependencies'=>[],'migrations'=>[]],is_array($inspection['release']??null)?$inspection['release']:[]),'releaseGate'=>array_merge(['schema'=>'easyit.dataform.module-release-gate.v1','status'=>'LEGACY_RELEASED','installable'=>true,'legacy'=>true],is_array($inspection['releaseGate']??null)?$inspection['releaseGate']:[]),'library'=>array_merge(['visibility'=>$visibility,'ownerProject'=>$visibility==='project'?$owner:null,'version'=>1],$extra)];
    }
    /** @return array<string,mixed> */ private function must(string $id,string $v,?string $p):array{$m=$this->store->getInScope($id,$v,$p);if($m===[])throw new \RuntimeException('Modul wurde im angegebenen Bereich nicht gefunden.');return $m;}
    private function assertScope(string $v,?string $p):void{if(!in_array($v,['system','project'],true))throw new \InvalidArgumentException('Ungültige Modulsichtbarkeit.');if($v==='project'&&($p===null||!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$p)||!is_dir($this->root.'/projects/'.$p)))throw new \InvalidArgumentException('Für ein projektbezogenes Modul ist ein gültiges Projekt erforderlich.');}
    private function versionDir(string $id,string $v,?string $p,bool $create):?string{$base=$this->store->baseDirectory($v,$p,$create);if($base===null)return null;$d=$base.'/.versions/'.$id;if($create)$this->ensure($d);return $d;}
    /** @param array<string,mixed> $m */ private function snapshot(array $m,string $v,?string $p,string $reason):void{$id=(string)$m['id'];$d=$this->versionDir($id,$v,$p,true);if($d===null)throw new \RuntimeException('Modulversionsverzeichnis kann nicht erstellt werden.');$n=$this->nextVersion($id,$v,$p);$m['_version']=['number'=>$n,'reason'=>$reason,'createdAt'=>gmdate('c')];$this->writeJson($d.'/v'.str_pad((string)$n,6,'0',STR_PAD_LEFT).'.json',$m);$src=$this->store->packagePath($id,$v,$p);if(is_file($src))@copy($src,$d.'/v'.str_pad((string)$n,6,'0',STR_PAD_LEFT).'.dataform-module.zip');}
    private function nextVersion(string $id,string $v,?string $p):int{$d=$this->versionDir($id,$v,$p,false);$max=0;if($d&&is_dir($d))foreach(glob($d.'/v*.json')?:[] as $f)if(preg_match('/v(\d+)\.json$/',$f,$m))$max=max($max,(int)$m[1]);return $max+1;}
    private function copyHistory(string $id,string $sv,?string $sp,string $tv,?string $tp):void{$s=$this->versionDir($id,$sv,$sp,false);if($s===null||!is_dir($s))return;$t=$this->versionDir($id,$tv,$tp,true);if($t===null)return;foreach(glob($s.'/v*.*')?:[] as $f)@copy($f,$t.'/'.basename($f));}
    private function ensure(string $d):void{if(!is_dir($d)&&!@mkdir($d,0770,true)&&!is_dir($d))throw new \RuntimeException('Verzeichnis konnte nicht angelegt werden: '.$d);}
    /** @param array<string,mixed> $m */ private function writeJson(string $p,array $m):void{$j=json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false||@file_put_contents($p,$j,LOCK_EX)===false)throw new \RuntimeException('JSON konnte nicht geschrieben werden.');}
}
