<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

use EasyIT\Assistant\Recovery\ProjectArchiveService;

final class ModuleUpdateService
{
    public function __construct(
        private DataFormModuleLibraryStore $store,
        private DataFormModuleLibraryService $library,
        private ModuleInstallationRegistry $installations,
        private ModuleDependencyService $dependencies,
        private ProjectArchiveService $archives,
        private string $root
    ) { $this->root = rtrim($root, '/\\'); }

    /** @return array<string,mixed> */
    public function scan(string $projectId): array
    {
        $this->assertProject($projectId);
        $items=[];$updates=[];$warnings=[];
        foreach($this->installations->all($projectId) as $installed){
            $id=(string)($installed['id']??'');$iv=SemVersion::normalize((string)($installed['version']??'1.0.0'));
            $candidate=$this->candidate($id,$projectId,(string)($installed['sourceLocator']??''));
            if($candidate===null){$items[]=['id'=>$id,'installedVersion'=>$iv,'status'=>'LIBRARY_MISSING','candidateVersion'=>null,'locator'=>null,'message'=>'Installiertes Modul ist in der sichtbaren Bibliothek nicht mehr vorhanden.'];$warnings[]=$id.': Bibliothekskandidat fehlt.';continue;}
            $cv=(string)$candidate['release']['version'];$gate=$this->gate((array)$candidate['metadata']);$cmp=SemVersion::compare($cv,$iv);$sha=(string)($candidate['metadata']['package']['sha256']??'');$isha=(string)($installed['packageSha256']??'');
            if($cmp>0&&!$gate['installable']){$status='RELEASE_BLOCKED';$msg='Update '.$iv.' → '.$cv.' vorhanden, aber Release-Gate '.$gate['status'].' ist nicht freigegeben.';$warnings[]=$id.': Release '.$cv.' ist nicht freigegeben.';}elseif($cmp>0){$status='UPDATE_AVAILABLE';$updates[]=$id;$msg='Update '.$iv.' → '.$cv.' verfügbar.';}
            elseif($cmp<0){$status='DOWNGRADE_AVAILABLE';$msg='Bibliothek enthält '.$cv.', installiert ist bereits '.$iv.'.';}
            elseif($sha!==''&&$isha!==''&&!hash_equals($sha,$isha)){$status='REINSTALL_AVAILABLE';$msg='Gleiche Version '.$cv.' mit abweichendem Paketstand.';}
            else{$status='CURRENT';$msg='Installierte Version ist aktuell.';}
            $items[]=['id'=>$id,'name'=>(string)($installed['name']??$id),'installedVersion'=>$iv,'candidateVersion'=>$cv,'status'=>$status,'locator'=>$candidate['locator'],'packageSha256'=>$sha,'installedPackageSha256'=>$isha,'dependencies'=>(array)$candidate['release']['dependencies'],'releaseGate'=>$gate,'message'=>$msg];
        }
        return ['schema'=>'easyit.assistant.module-update-scan.v1','projectId'=>$projectId,'status'=>$updates!==[]?'UPDATES_AVAILABLE':'CURRENT','updates'=>$updates,'items'=>$items,'warnings'=>array_values(array_unique($warnings)),'scannedAt'=>gmdate('c')];
    }

    /**
     * @param list<string> $moduleIds
     * @param array<string,mixed> $dataFormMappings
     * @param array<string,mixed> $referenceMappings
     * @return array<string,mixed>
     */
    public function prepare(string $projectId,array $moduleIds,array $dataFormMappings=[],array $referenceMappings=[],bool $cascade=true,bool $applyDatasource=false,bool $allowUnreleased=false):array
    {
        $scan=$this->scan($projectId);$selected=array_values(array_unique(array_filter(array_map('trim',$moduleIds),static fn($v)=>$v!=='')));
        if($selected===[])$selected=(array)$scan['updates'];
        if($selected===[])return ['schema'=>'easyit.assistant.module-update-plan.v1','verdict'=>'PASS','errors'=>[],'warnings'=>[],'projectId'=>$projectId,'selected'=>[],'prepared'=>false,'message'=>'Keine Updates ausgewählt bzw. verfügbar.','scan'=>$scan];
        $errors=[];$warnings=[];$roots=[];$plannedIds=[];
        foreach($selected as $id){$installed=$this->installations->get($projectId,$id);if($installed===[]){$errors[]='Modul ist nicht installiert und kann nicht als Update behandelt werden: '.$id;continue;}$cand=$this->candidate($id,$projectId,(string)($installed['sourceLocator']??''));if($cand===null){$errors[]='Kein Bibliothekskandidat für '.$id.' gefunden.';continue;}$iv=(string)$installed['version'];$cv=(string)$cand['release']['version'];$gate=$this->gate((array)$cand['metadata']);if(!$allowUnreleased&&!$gate['installable']){$errors[]='Release '.$id.' '.$cv.' ist nicht freigegeben ('.$gate['status'].').';continue;}if(SemVersion::compare($cv,$iv)<=0){$errors[]='Für '.$id.' ist kein höheres Release verfügbar (installiert '.$iv.', Bibliothek '.$cv.').';continue;}$roots[]=$cand['locator'];$plannedIds[$id]=true;}
        if($errors!==[])return $this->failedPlan($projectId,$scan,$selected,$errors,$warnings);

        // Reverse dependencies: if an installed module would reject a selected new version,
        // optionally add a newer compatible candidate of that dependent module.
        if($cascade){
            $changed=true;$guard=0;
            while($changed&&$guard++<50){$changed=false;$versions=$this->prospectiveVersions($projectId,$roots);
                foreach($this->installations->all($projectId) as $entry){$id=(string)$entry['id'];if(isset($plannedIds[$id]))continue;foreach((array)($entry['dependencies']??[]) as $dep){if(!is_array($dep)||!empty($dep['optional']))continue;$depId=(string)($dep['moduleId']??'');if(!isset($versions[$depId]))continue;$constraint=(string)($dep['constraint']??'*');if(SemVersion::satisfies($versions[$depId],$constraint))continue;
                    $cand=$this->candidate($id,$projectId,(string)($entry['sourceLocator']??''));if($cand!==null&&($allowUnreleased||$this->gate((array)$cand['metadata'])['installable'])&&SemVersion::compare((string)$cand['release']['version'],(string)$entry['version'])>0&&$this->releaseAccepts((array)$cand['release'],$depId,$versions[$depId])){$roots[]=$cand['locator'];$plannedIds[$id]=true;$warnings[]='Abhängiges Modul '.$id.' wird automatisch mit aktualisiert, weil '.$depId.' '.$versions[$depId].' die bisherige Bedingung '.$constraint.' verletzt.';$changed=true;break;}
                    $errors[]='Update von '.$depId.' auf '.$versions[$depId].' würde installiertes Modul '.$id.' verletzen (erwartet '.$constraint.'). Kein kompatibles Folgeupdate wurde gefunden.';
                }}
                if($errors!==[])break;
            }
        }
        if($errors!==[])return $this->failedPlan($projectId,$scan,array_keys($plannedIds),$errors,$warnings);

        $autoDf=$this->automaticDataFormMappings($projectId,$roots);$mergedDf=$this->mergeMappings($autoDf,$dataFormMappings);$autoRef=$this->automaticReferenceMappings($projectId,$roots);$mergedRef=$this->mergeMappings($autoRef,$referenceMappings);
        $prepared=$this->dependencies->prepare(array_values(array_unique($roots)),$projectId,$mergedDf,$mergedRef,true,$applyDatasource,$allowUnreleased);
        $errors=array_merge($errors,(array)($prepared['errors']??[]));$warnings=array_merge($warnings,(array)($prepared['warnings']??[]));
        $compat=$this->compatibilityAfterPlan($projectId,$prepared);$errors=array_merge($errors,$compat['errors']);$warnings=array_merge($warnings,$compat['warnings']);
        $migrationCount=0;$migrationModules=[];foreach(array_values(array_unique($roots)) as $loc){try{[$mv,$mp,$mid]=$this->parseLocator($loc);$mm=$this->store->getInScope($mid,$mv,$mp);$mc=count((array)($this->release($mm)['migrations']??[]));if($mc>0){$migrationCount+=$mc;$migrationModules[$mid]=$mc;}}catch(\Throwable){}}
        return ['schema'=>'easyit.assistant.module-update-plan.v1','verdict'=>$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS'),'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'projectId'=>$projectId,'selected'=>array_keys($plannedIds),'rootLocators'=>array_values(array_unique($roots)),'scan'=>$scan,'dependencyPlan'=>$prepared,'compatibility'=>$compat,'dataFormMappings'=>$mergedDf,'referenceMappings'=>$mergedRef,'applyDatasource'=>$applyDatasource,'migrationCount'=>$migrationCount,'migrationModules'=>$migrationModules,'requiresMigrationAssistant'=>$migrationCount>0,'prepared'=>$errors===[]&&!empty($prepared['prepared']),'preparedAt'=>gmdate('c')];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function apply(array $plan):array
    {
        if(empty($plan['prepared'])||($plan['verdict']??'')==='FAIL')return $plan+['applied'=>false,'message'=>'Upgrade-Plan ist nicht ausführbar.'];
        if((int)($plan['migrationCount']??0)>0)return $plan+['applied'=>false,'message'=>'Dieses Release enthält Migrationen und muss über den Modul-Migrations-Assistenten ausgeführt werden.','requiresMigrationAssistant'=>true];
        $project=(string)$plan['projectId'];$checkpoint=$this->archives->backup($project,true,false);
        if(!$checkpoint->isOk())return $plan+['applied'=>false,'message'=>'Rollback-Backup konnte nicht erzeugt werden: '.$checkpoint->getMessage(),'rollbackCheckpoint'=>$checkpoint->jsonSerialize()];
        return $this->applyWithExistingCheckpoint($plan,$checkpoint->jsonSerialize());
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $checkpointData @return array<string,mixed> */
    public function applyWithExistingCheckpoint(array $plan,array $checkpointData):array
    {
        if(empty($plan['prepared'])||($plan['verdict']??'')==='FAIL')return $plan+['applied'=>false,'message'=>'Upgrade-Plan ist nicht ausführbar.'];
        $project=(string)$plan['projectId'];$result=$this->dependencies->applyPrepared((array)$plan['dependencyPlan']);$this->writeCheckpoint($project,$plan,$checkpointData,$result);
        return $plan+['applied'=>!empty($result['applied']),'moduleInstallResult'=>$result,'rollbackCheckpoint'=>$checkpointData,'rollbackReady'=>true,'appliedAt'=>gmdate('c'),'message'=>!empty($result['applied'])?'Upgrade erfolgreich; vorhandener Rollback-Checkpoint wurde verwendet.':'Upgrade fehlgeschlagen; Rollback-Backup steht zur Recovery bereit.'];
    }

    /** @return list<array<string,mixed>> */
    public function checkpoints(string $projectId):array
    {
        $this->assertProject($projectId);$dir=$this->root.'/storage/assistant/module-update-rollbacks/'.$projectId;if(!is_dir($dir))return [];$out=[];foreach(glob($dir.'/*.json')?:[] as $p){$d=json_decode((string)@file_get_contents($p),true);if(is_array($d))$out[]=$d;}usort($out,static fn($a,$b)=>strcmp((string)($b['createdAt']??''),(string)($a['createdAt']??'')));return $out;
    }

    /** @return array{locator:string,metadata:array<string,mixed>,release:array<string,mixed>}|null */
    private function candidate(string $id,string $projectId,string $sourceLocator=''):?array
    {
        if($sourceLocator!==''){try{[$v,$p,$sid]=$this->parseLocator($sourceLocator);if($sid===$id){$m=$this->store->getInScope($id,$v,$p);if($m!==[])return ['locator'=>$sourceLocator,'metadata'=>$m,'release'=>$this->release($m)];}}catch(\Throwable){}}
        $m=$this->store->getInScope($id,'project',$projectId);if($m!==[])return ['locator'=>'project::'.$projectId.'::'.$id,'metadata'=>$m,'release'=>$this->release($m)];
        $m=$this->store->getInScope($id,'system',null);if($m!==[])return ['locator'=>'system::'.$id,'metadata'=>$m,'release'=>$this->release($m)];return null;
    }

    /** @param list<string> $roots @return array<string,string> */
    private function prospectiveVersions(string $projectId,array $roots):array{$v=[];foreach($this->installations->all($projectId) as $m)$v[(string)$m['id']]=(string)$m['version'];foreach($roots as $loc){try{[$vis,$p,$id]=$this->parseLocator($loc);$m=$this->store->getInScope($id,$vis,$p);if($m!==[])$v[$id]=(string)$this->release($m)['version'];}catch(\Throwable){}}return $v;}
    /** @param array<string,mixed> $release */
    private function releaseAccepts(array $release,string $depId,string $version):bool{foreach((array)($release['dependencies']??[]) as $d)if(is_array($d)&&(string)($d['moduleId']??'')===$depId&&!empty($d['optional'])===false)return SemVersion::satisfies($version,(string)($d['constraint']??'*'));return true;}

    /** @return array{errors:list<string>,warnings:list<string>,versions:array<string,string>} */
    private function compatibilityAfterPlan(string $projectId,array $prepared):array
    {
        $versions=[];$depsByModule=[];$errors=[];$warnings=[];
        foreach($this->installations->all($projectId) as $m){$id=(string)$m['id'];$versions[$id]=(string)$m['version'];$depsByModule[$id]=(array)($m['dependencies']??[]);}
        foreach((array)($prepared['nodes']??[]) as $id=>$node){if(empty($node['needsInstall']))continue;$versions[(string)$id]=(string)($node['version']??'0.0.0');$depsByModule[(string)$id]=(array)($node['dependencies']??[]);}
        foreach($depsByModule as $id=>$deps)foreach($deps as $dep){if(!is_array($dep))continue;$depId=(string)($dep['moduleId']??'');$constraint=(string)($dep['constraint']??'*');$optional=!empty($dep['optional']);if(!isset($versions[$depId])){$msg=$id.' benötigt '.$depId.' '.$constraint.', aber das Modul wäre nach dem Upgrade nicht installiert.';if($optional)$warnings[]=$msg;else $errors[]=$msg;continue;}if(!SemVersion::satisfies($versions[$depId],$constraint)){$msg=$id.' benötigt '.$depId.' '.$constraint.', geplant ist jedoch '.$versions[$depId].'.';if($optional)$warnings[]=$msg;else $errors[]=$msg;}}
        return ['errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'versions'=>$versions];
    }

    /** @param list<string> $roots @return array<string,mixed> */
    private function automaticDataFormMappings(string $projectId,array $roots):array
    {
        $maps=['global'=>[],'modules'=>[]];foreach($roots as $loc){[$v,$p,$id]=$this->parseLocator($loc);$meta=$this->store->getInScope($id,$v,$p);$installed=$this->installations->get($projectId,$id);$stored=(array)($installed['dataFormMapping']??[]);if($stored!==[]){foreach($stored as $k=>$v2)$maps['modules'][$id][(string)$k]=(string)$v2;continue;}$source=array_values((array)($meta['module']['dataForms']??[]));$target=array_values((array)($installed['dataForms']??[]));if(count($source)===count($target)&&$source!==[])foreach($source as $i=>$s)$maps['modules'][$id][(string)$s]=(string)$target[$i];}return $maps;
    }
    /** @param list<string> $roots @return array<string,mixed> */
    private function automaticReferenceMappings(string $projectId,array $roots):array{$maps=['global'=>[],'modules'=>[]];foreach($roots as $loc){[,,$id]=$this->parseLocator($loc);$installed=$this->installations->get($projectId,$id);foreach((array)($installed['referenceMapping']??[]) as $k=>$v)$maps['modules'][$id][(string)$k]=(string)$v;}return $maps;}
    /** @param array<string,mixed> $base @param array<string,mixed> $override @return array<string,mixed> */
    private function mergeMappings(array $base,array $override):array{$out=['global'=>(array)($base['global']??[]),'modules'=>(array)($base['modules']??[])];foreach((array)($override['global']??[]) as $k=>$v)$out['global'][(string)$k]=(string)$v;foreach((array)($override['modules']??[]) as $m=>$map)foreach((array)$map as $k=>$v)$out['modules'][(string)$m][(string)$k]=(string)$v;return $out;}

    /** @return array{status:string,installable:bool,legacy:bool} */
    private function gate(array $m):array{$g=is_array($m['releaseGate']??null)?$m['releaseGate']:[];if($g===[])return ['status'=>'LEGACY_RELEASED','installable'=>true,'legacy'=>true];return ['status'=>(string)($g['status']??'DRAFT'),'installable'=>!empty($g['installable']),'legacy'=>!empty($g['legacy'])];}

    /** @return array<string,mixed> */
    private function failedPlan(string $projectId,array $scan,array $selected,array $errors,array $warnings):array{return ['schema'=>'easyit.assistant.module-update-plan.v1','verdict'=>'FAIL','errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'projectId'=>$projectId,'selected'=>$selected,'scan'=>$scan,'prepared'=>false];}
    /** @return array<string,mixed> */
    private function release(array $m):array{$r=is_array($m['release']??null)?$m['release']:[];return ['version'=>SemVersion::normalize((string)($r['version']??'1.0.0')),'dependencies'=>array_values(array_filter((array)($r['dependencies']??[]),'is_array')),'migrations'=>array_values(array_filter((array)($r['migrations']??[]),'is_array'))];}
    /** @return array{0:string,1:?string,2:string} */
    private function parseLocator(string $locator):array{$p=explode('::',trim($locator));if(($p[0]??'')==='system'&&count($p)===2)return['system',null,$p[1]];if(($p[0]??'')==='project'&&count($p)===3)return['project',$p[1],$p[2]];throw new \InvalidArgumentException('Ungültiger Modullocator: '.$locator);}
    private function assertProject(string $project):void{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$project)||!is_dir($this->root.'/projects/'.$project))throw new \InvalidArgumentException('Projekt wurde nicht gefunden: '.$project);}
    /** @param array<string,mixed> $plan @param array<string,mixed> $checkpoint @param array<string,mixed> $result */
    private function writeCheckpoint(string $project,array $plan,array $checkpoint,array $result):void{$dir=$this->root.'/storage/assistant/module-update-rollbacks/'.$project;if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))return;$doc=['schema'=>'easyit.assistant.module-update-checkpoint.v1','projectId'=>$project,'createdAt'=>gmdate('c'),'selected'=>(array)($plan['selected']??[]),'targetVersions'=>(array)($plan['compatibility']['versions']??[]),'backup'=>$checkpoint,'updateResult'=>['applied'=>!empty($result['applied']),'message'=>(string)($result['message']??'')]];$json=json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json!==false)@file_put_contents($dir.'/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json',$json."\n",LOCK_EX);}
}
