<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class ModuleDependencyService
{
    public function __construct(
        private DataFormModuleLibraryStore $store,
        private DataFormModuleLibraryService $library,
        private ModuleInstallationRegistry $installations,
        private string $root
    ){$this->root=rtrim($root,'/\\');}

    /** @param list<string> $rootLocators @return array<string,mixed> */
    public function plan(array $rootLocators,string $targetProject,bool $allowUnreleased=false):array
    {
        $this->assertProject($targetProject);$roots=array_values(array_unique(array_filter(array_map('trim',$rootLocators),static fn($v)=>$v!=='')));
        if($roots===[])return $this->report(['Mindestens ein Startmodul ist erforderlich.']);
        $errors=[];$warnings=[];$checks=[];$nodes=[];$order=[];$visiting=[];$rootIds=[];

        $resolve=function(string $locator,?string $constraint,bool $isRoot) use (&$resolve,&$errors,&$warnings,&$checks,&$nodes,&$order,&$visiting,&$rootIds,$targetProject,$allowUnreleased):void{
            [$visibility,$projectId,$id]=$this->parseLocator($locator);$m=$this->store->getInScope($id,$visibility,$projectId);if($m===[]){$errors[]='Modul nicht gefunden: '.$locator;return;}
            $release=$this->release($m);$gate=$this->gate($m);if(!$allowUnreleased&&!$gate['installable']){$errors[]='Modul '.$id.' ist nicht freigegeben (Release-Gate: '.$gate['status'].').';$checks[]=['id'=>'module.'.$id.'.release-gate','status'=>'FAIL','message'=>'Release ist nicht installierbar.','details'=>$gate];return;}$version=(string)$release['version'];if($constraint!==null&&!SemVersion::satisfies($version,$constraint)){$errors[]='Modul '.$id.' '.$version.' erfüllt '.$constraint.' nicht.';return;}
            if($isRoot)$rootIds[$id]=true;
            if(isset($visiting[$id])){$errors[]='Zyklische Modulabhängigkeit erkannt: '.implode(' -> ',array_keys($visiting)).' -> '.$id;return;}
            if(isset($nodes[$id])){
                $existing=(string)$nodes[$id]['version'];if($constraint!==null&&!SemVersion::satisfies($existing,$constraint))$errors[]='Abhängigkeitskonflikt für '.$id.': '.$existing.' erfüllt '.$constraint.' nicht.';
                if((string)$nodes[$id]['locator']!==$locator&&$existing!==$version)$errors[]='Mehrere Bibliotheksvarianten von '.$id.' mit unterschiedlichen Versionen wurden aufgelöst.';return;
            }
            $visiting[$id]=true;$installed=$this->installations->get($targetProject,$id);$sha=(string)($m['package']['sha256']??'');$status='INSTALL';$reason='noch nicht installiert';$needsInstall=true;
            if($installed!==[]){$iv=(string)($installed['version']??'0.0.0');$isha=(string)($installed['packageSha256']??'');
                if(!$isRoot&&($constraint===null||SemVersion::satisfies($iv,$constraint))){$status='SATISFIED';$reason='bereits kompatibel installiert';$needsInstall=false;}
                elseif($iv===$version&&$isha!==''&&$sha!==''&&hash_equals($isha,$sha)){$status='SATISFIED';$reason='identische Version und Paket-SHA bereits installiert';$needsInstall=false;}
                elseif($iv===$version){$status='REINSTALL';$reason='gleiche Version mit abweichendem Paketstand';$warnings[]=$id.' '.$version.' wird wegen abweichender Paket-SHA erneut installiert.';}
                else{$cmp=SemVersion::compare($version,$iv);$status=$cmp>0?'UPGRADE':'DOWNGRADE';$reason='installiert '.$iv.' → Bibliothek '.$version;$warnings[]=$id.' wird von '.$iv.' auf '.$version.' '.($cmp>0?'aktualisiert':'zurückgestuft').'.';}
            }
            $nodes[$id]=['id'=>$id,'name'=>(string)($m['name']??$id),'locator'=>$locator,'visibility'=>$visibility,'ownerProject'=>$projectId,'version'=>$version,'packageSha256'=>$sha,'status'=>$status,'reason'=>$reason,'needsInstall'=>$needsInstall,'dependencies'=>$release['dependencies'],'dataForms'=>(array)($m['module']['dataForms']??[])];
            $checks[]=['id'=>'module.'.$id.'.candidate','status'=>$needsInstall?($status==='DOWNGRADE'?'WARN':'PASS'):'PASS','message'=>$reason,'details'=>['version'=>$version,'locator'=>$locator,'installed'=>$installed]];
            foreach((array)$release['dependencies'] as $dep){$depId=(string)$dep['moduleId'];$depConstraint=(string)$dep['constraint'];$optional=!empty($dep['optional']);$installedDep=$this->installations->get($targetProject,$depId);
                if($installedDep!==[]&&SemVersion::satisfies((string)$installedDep['version'],$depConstraint)){$checks[]=['id'=>'dependency.'.$id.'.'.$depId,'status'=>'PASS','message'=>'Bereits installiert: '.$depId.' '.$installedDep['version'].' erfüllt '.$depConstraint.'.'];continue;}
                $candidate=$this->findCandidate($depId,$targetProject);if($candidate===null){$msg='Abhängigkeit '.$depId.' '.$depConstraint.' für '.$id.' fehlt.';if($optional){$warnings[]=$msg;$checks[]=['id'=>'dependency.'.$id.'.'.$depId,'status'=>'WARN','message'=>$msg];continue;}$errors[]=$msg;$checks[]=['id'=>'dependency.'.$id.'.'.$depId,'status'=>'FAIL','message'=>$msg];continue;}
                $candRelease=$this->release($candidate['metadata']);if(!SemVersion::satisfies((string)$candRelease['version'],$depConstraint)){$msg='Verfügbare Version '.$candRelease['version'].' von '.$depId.' erfüllt '.$depConstraint.' nicht.';if($optional){$warnings[]=$msg;$checks[]=['id'=>'dependency.'.$id.'.'.$depId,'status'=>'WARN','message'=>$msg];continue;}$errors[]=$msg;$checks[]=['id'=>'dependency.'.$id.'.'.$depId,'status'=>'FAIL','message'=>$msg];continue;}
                $checks[]=['id'=>'dependency.'.$id.'.'.$depId,'status'=>'PASS','message'=>$depId.' '.$candRelease['version'].' erfüllt '.$depConstraint.'.'];$resolve($candidate['locator'],$depConstraint,false);
            }
            unset($visiting[$id]);if($needsInstall&&!in_array($locator,$order,true))$order[]=$locator;
        };

        foreach($roots as $locator){try{$resolve($locator,null,true);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $installIds=[];foreach($order as $loc){try{[,,$id]=$this->parseLocator($loc);$installIds[]=$id;}catch(\Throwable){}}
        return ['schema'=>'easyit.assistant.module-install-plan.v1','verdict'=>$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS'),'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings)),'checks'=>$checks,'targetProject'=>$targetProject,'roots'=>$roots,'rootModuleIds'=>array_keys($rootIds),'nodes'=>$nodes,'installOrder'=>$order,'installModuleIds'=>$installIds,'installed'=>$this->installations->all($targetProject)];
    }

    /** @param list<string> $rootLocators @param array<string,mixed> $dataFormMappings @param array<string,mixed> $referenceMappings @return array<string,mixed> */
    public function prepare(array $rootLocators,string $targetProject,array $dataFormMappings,array $referenceMappings,bool $allowOverwrite,bool $applyDatasource,bool $allowUnreleased=false):array
    {
        $plan=$this->plan($rootLocators,$targetProject,$allowUnreleased);if(($plan['verdict']??'')==='FAIL')return $plan+['prepared'=>false,'modulePreviews'=>[],'tokens'=>[]];
        $errors=[];$warnings=(array)$plan['warnings'];$previews=[];$tokens=[];$targetOwners=[];
        foreach((array)$plan['installOrder'] as $locator){[,,$id]=$this->parseLocator((string)$locator);$node=(array)$plan['nodes'][$id];$df=$this->mappingFor($dataFormMappings,$id);foreach((array)$node['dataForms'] as $sourceDf){$targetDf=(string)($df[$sourceDf]??$sourceDf);if(isset($targetOwners[$targetDf])&&$targetOwners[$targetDf]!==$id)$errors[]='DataForm-Ziel '.$targetDf.' wird von mehreren Modulen belegt: '.$targetOwners[$targetDf].' und '.$id.'.';else $targetOwners[$targetDf]=$id;}}
        if($errors!==[])return $plan+['verdict'=>'FAIL','errors'=>array_values(array_unique(array_merge((array)$plan['errors'],$errors))),'warnings'=>$warnings,'prepared'=>false,'modulePreviews'=>[],'tokens'=>[]];
        foreach((array)$plan['installOrder'] as $locator){[$v,$p,$id]=$this->parseLocator((string)$locator);$stage=$this->library->stage($id,$v,$p,$allowUnreleased);$tokens[$id]=(string)$stage['token'];$df=$this->mappingFor($dataFormMappings,$id);$ref=$this->mappingFor($referenceMappings,$id);$pre=$this->library->previewInstall((string)$stage['token'],$targetProject,$df,$ref,$allowOverwrite,$applyDatasource);$previews[$id]=$pre;if(($pre['verdict']??'')==='FAIL')foreach((array)($pre['errors']??[]) as $e)$errors[]=$id.': '.$e;foreach((array)($pre['warnings']??[]) as $w)$warnings[]=$id.': '.$w;}
        return $plan+['verdict'=>$errors!==[]?'FAIL':($warnings!==[]?'PASS_WITH_WARNINGS':'PASS'),'errors'=>array_values(array_unique(array_merge((array)$plan['errors'],$errors))),'warnings'=>array_values(array_unique($warnings)),'prepared'=>$errors===[],'modulePreviews'=>$previews,'tokens'=>$tokens,'dataFormMappings'=>$dataFormMappings,'referenceMappings'=>$referenceMappings,'allowOverwrite'=>$allowOverwrite,'applyDatasource'=>$applyDatasource,'preparedAt'=>gmdate('c')];
    }

    /** @param array<string,mixed> $prepared @return array<string,mixed> */
    public function applyPrepared(array $prepared):array
    {
        if(empty($prepared['prepared'])||($prepared['verdict']??'')==='FAIL')return $prepared+['applied'=>false,'message'=>'Installationsplan ist nicht ausführbar.'];$target=(string)$prepared['targetProject'];$results=[];$registered=[];$partial=false;
        foreach((array)$prepared['installOrder'] as $locator){[$v,$p,$id]=$this->parseLocator((string)$locator);$token=(string)($prepared['tokens'][$id]??'');if($token===''){return $prepared+['applied'=>false,'partial'=>$partial,'results'=>$results,'message'=>'Staging-Token fehlt für '.$id.'.'];}$df=$this->mappingFor((array)$prepared['dataFormMappings'],$id);$ref=$this->mappingFor((array)$prepared['referenceMappings'],$id);$app=$this->library->applyInstall($token,$target,$df,$ref,!empty($prepared['allowOverwrite']),!empty($prepared['applyDatasource']));$results[$id]=$app;if(empty($app['applied']))return $prepared+['applied'=>false,'partial'=>$partial,'results'=>$results,'message'=>'Installation von '.$id.' ist fehlgeschlagen.'];$partial=true;$meta=$this->store->getInScope($id,$v,$p);$release=$this->release($meta);$mappedDataForms=[];foreach((array)($meta['module']['dataForms']??[]) as $source)$mappedDataForms[]=(string)($df[$source]??$source);$registered[$id]=$this->installations->register($target,['id'=>$id,'name'=>(string)($meta['name']??$id),'version'=>(string)$release['version'],'sourceLocator'=>(string)$locator,'packageSha256'=>(string)($meta['package']['sha256']??''),'dataForms'=>$mappedDataForms,'dependencies'=>$release['dependencies'],'diagnosticVerdicts'=>array_map(static fn($r)=>(string)($r['verdict']??''),(array)($app['diagnosticReports']??[])),'dataFormMapping'=>$df,'referenceMapping'=>$ref,'applyDatasource'=>!empty($prepared['applyDatasource'])]);}
        return $prepared+['applied'=>true,'partial'=>false,'results'=>$results,'registered'=>$registered,'appliedAt'=>gmdate('c'),'message'=>'Alle geplanten Module wurden in Abhängigkeitsreihenfolge installiert.'];
    }

    /** @return list<array<string,mixed>> */ public function installed(string $projectId):array{return $this->installations->all($projectId);}

    /** @return array{visibility:string,projectId:?string,id:string} */
    public function locatorParts(string $locator):array{[$v,$p,$id]=$this->parseLocator($locator);return ['visibility'=>$v,'projectId'=>$p,'id'=>$id];}

    /** @return array<string,mixed> */
    private function release(array $m):array{$r=is_array($m['release']??null)?$m['release']:[];$version=SemVersion::normalize((string)($r['version']??'1.0.0'));$deps=[];foreach((array)($r['dependencies']??[]) as $d)if(is_array($d))$deps[]=['moduleId'=>(string)($d['moduleId']??''),'constraint'=>(string)($d['constraint']??'*'),'optional'=>!empty($d['optional'])];return ['version'=>$version,'dependencies'=>$deps];}

    /** @return array{status:string,installable:bool,legacy:bool} */
    private function gate(array $m):array{$g=is_array($m['releaseGate']??null)?$m['releaseGate']:[];if($g===[])return ['status'=>'LEGACY_RELEASED','installable'=>true,'legacy'=>true];return ['status'=>(string)($g['status']??'DRAFT'),'installable'=>!empty($g['installable']),'legacy'=>!empty($g['legacy'])];}

    /** @return array{locator:string,metadata:array<string,mixed>}|null */
    private function findCandidate(string $id,string $targetProject):?array{$m=$this->store->getInScope($id,'project',$targetProject);if($m!==[])return ['locator'=>'project::'.$targetProject.'::'.$id,'metadata'=>$m];$m=$this->store->getInScope($id,'system',null);if($m!==[])return ['locator'=>'system::'.$id,'metadata'=>$m];return null;}

    /** @return array{0:string,1:?string,2:string} */
    private function parseLocator(string $locator):array{$p=explode('::',trim($locator));if(($p[0]??'')==='system'&&isset($p[1])&&count($p)===2)return['system',null,$this->assertId($p[1])];if(($p[0]??'')==='project'&&isset($p[1],$p[2])&&count($p)===3){$this->assertId($p[1]);return['project',$p[1],$this->assertId($p[2])];}throw new \InvalidArgumentException('Ungültiger Modullocator: '.$locator);}
    private function assertId(string $id):string{if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige Modul-/Projekt-ID: '.$id);return $id;}
    private function assertProject(string $project):void{$this->assertId($project);if(!is_dir($this->root.'/projects/'.$project))throw new \InvalidArgumentException('Zielprojekt wurde nicht gefunden: '.$project);}
    /** @param array<string,mixed> $maps @return array<string,string> */
    private function mappingFor(array $maps,string $moduleId):array{$out=[];foreach((array)($maps['global']??[]) as $k=>$v)$out[(string)$k]=(string)$v;foreach((array)($maps['modules'][$moduleId]??[]) as $k=>$v)$out[(string)$k]=(string)$v;return $out;}
    /** @param list<string> $errors @return array<string,mixed> */
    private function report(array $errors):array{return ['schema'=>'easyit.assistant.module-install-plan.v1','verdict'=>'FAIL','errors'=>$errors,'warnings'=>[],'checks'=>[],'nodes'=>[],'installOrder'=>[],'installModuleIds'=>[]];}
}
