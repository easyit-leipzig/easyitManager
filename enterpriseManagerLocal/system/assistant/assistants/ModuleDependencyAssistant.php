<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\Module\DataFormModuleLibraryService;
use EasyIT\Assistant\Module\ModuleDependencyService;
use EasyIT\Assistant\Module\SemVersion;

final class ModuleDependencyAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore,private DataFormModuleLibraryService $library,private ModuleDependencyService $dependencies){}
    public function getId():string{return 'dataform.module-dependencies';}
    public function getTitle():string{return 'Modulabhängigkeiten und Installation';}
    public function getDescription():string{return 'Definiert SemVer-Modulabhängigkeiten, prüft fehlende oder inkompatible Voraussetzungen und installiert Module automatisch in Abhängigkeitsreihenfolge.';}

    public function getSteps(AssistantContext $c):array
    {
        $scope=$c->getProjectId()?:'global';$s=$this->stateStore->get($this->getId(),$scope);$op=(string)($s['operation']??'plan');$options=[''=>'Bitte Modul auswählen'];foreach($this->library->list($c->getProjectId()) as $m){$v=(string)($m['visibility']??'system');$p=(string)($m['ownerProject']??'');$loc=$v==='project'?'project::'.$p.'::'.$m['id']:'system::'.$m['id'];$options[$loc]=(string)$m['name'].' v'.(string)($m['releaseVersion']??'1.0.0').' ['.($v==='project'?'Projekt '.$p:'System').']';}
        $steps=[new AssistantStep('operation','1. Vorgang','Abhängigkeiten deklarieren, Installationsplan erzeugen oder installierte Module anzeigen.',['fields'=>[['name'=>'operation','label'=>'Vorgang','type'=>'select','required'=>true,'options'=>['plan'=>'Abhängigkeiten prüfen und installieren','release'=>'Modulversion und Abhängigkeiten festlegen','installed'=>'Installierte Module anzeigen']]]])];
        if($op==='release'){$steps[]=new AssistantStep('select','2. Modul','Bibliotheksmodul auswählen.',['fields'=>[['name'=>'module_locator','label'=>'Modul','type'=>'select','required'=>true,'options'=>$options]]]);$steps[]=new AssistantStep('configure','3. Release','SemVer-Version und Abhängigkeiten deklarieren.',['fields'=>[['name'=>'semantic_version','label'=>'Modulversion (SemVer)','type'=>'text','required'=>true],['name'=>'dependency_lines','label'=>'Abhängigkeiten: modul-id|versionsbereich|required/optional','type'=>'textarea','rows'=>9],['name'=>'migration_json','label'=>'Migrationen (JSON-Array; Phase 22)','type'=>'textarea','rows'=>16]]]);$steps[]=new AssistantStep('result','4. Ergebnis','Release-Metadaten versioniert als DRAFT speichern; vor Installation ist anschließend das Release-Gate auszuführen.');return $steps;}
        if($op==='installed'){$steps[]=new AssistantStep('configure','2. Zielprojekt','Installationsregister auswählen.',['fields'=>[['name'=>'target_project','label'=>'Projekt','type'=>'text','required'=>true]]]);$steps[]=new AssistantStep('result','3. Installationsregister','Installierte Module und Versionen anzeigen.');return $steps;}
        $steps[]=new AssistantStep('configure','2. Installationsziel','Startmodul, Zielprojekt und Mapping festlegen.',['fields'=>[
            ['name'=>'root_locator','label'=>'Startmodul','type'=>'select','required'=>true,'options'=>$options],['name'=>'additional_roots','label'=>'Weitere Startmodule (Locator je Zeile)','type'=>'textarea','rows'=>4],['name'=>'target_project','label'=>'Zielprojekt','type'=>'text','required'=>true],['name'=>'dataform_mapping','label'=>'DataForm-Mapping: quelle|ziel oder modul::quelle|ziel','type'=>'textarea','rows'=>7],['name'=>'reference_mapping','label'=>'Referenz-Mapping: alt|neu oder modul::alt|neu','type'=>'textarea','rows'=>7],['name'=>'allow_overwrite','label'=>'Vorhandene Konfiguration historisiert überschreiben','type'=>'checkbox'],['name'=>'apply_datasource','label'=>'Datenquellen-Snapshots übernehmen','type'=>'checkbox']]]);
        $steps[]=new AssistantStep('preview','3. Abhängigkeitsplan','Versionen, fehlende Module, Zyklen und Installationsreihenfolge prüfen.');$steps[]=new AssistantStep('apply','4. Installieren','Geprüften Plan ausführen.',['fields'=>[['name'=>'confirm_apply','label'=>'Geprüften Installationsplan jetzt ausführen','type'=>'checkbox']]]);$steps[]=new AssistantStep('result','5. Ergebnis','Installationsregister und Diagnosen anzeigen.');return $steps;
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);$s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'operation';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];$data=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId=$ids[0];$op=(string)($s['operation']??'plan');$data=['phase'=>24,'operation'=>$op,'moduleLibrary'=>$this->library->list($c->getProjectId()),'formValues'=>$this->formValues($s,$stepId,$c),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            if($op==='release'&&in_array($stepId,['select','configure','result'],true)){if(trim((string)($s['moduleLocator']??''))==='')throw new \RuntimeException('Modul fehlt.');[$v,$p,$id]=$this->locator((string)$s['moduleLocator']);$data['selectedModule']=$this->library->getScoped($id,$v,$p);if($stepId==='result'){if(!is_array($s['releaseResult']??null)){$deps=$this->parseDependencies((string)($s['dependencyLines']??''));$s['releaseResult']=$this->library->setRelease($id,$v,$p,(string)($s['semanticVersion']??'1.0.0'),$deps,$this->parseMigrations((string)($s['migrationJson']??'')),true);$this->stateStore->put($this->getId(),$s,$scope);}$data['moduleReleaseResult']=$s['releaseResult'];$data['configurationReady']=true;}}
            elseif($op==='installed'&&$stepId==='result'){$target=trim((string)($s['targetProject']??''));$data['installedModules']=$this->dependencies->installed($target);$data['configurationReady']=true;}
            elseif($op==='plan'&&in_array($stepId,['preview','apply','result'],true)){
                if(!is_array($s['prepared']??null)||$stepId==='preview'){$roots=$this->roots($s);$s['prepared']=$this->dependencies->prepare($roots,(string)($s['targetProject']??''),$this->parseMappings((string)($s['dataFormMapping']??'')),$this->parseMappings((string)($s['referenceMapping']??'')),!empty($s['allowOverwrite']),!empty($s['applyDatasource']));$this->stateStore->put($this->getId(),$s,$scope);}
                $data['moduleDependencyPlan']=$s['prepared'];if($stepId==='apply'&&!empty($s['confirmApply'])){$s['applyResult']=$this->dependencies->applyPrepared((array)$s['prepared']);$this->stateStore->put($this->getId(),$s,$scope);}$data['moduleInstallResult']=$s['applyResult']??null;if($stepId==='result'){if(!is_array($s['applyResult']??null))throw new \RuntimeException('Installationsplan wurde noch nicht ausgeführt.');$data['installedModules']=$this->dependencies->installed((string)$s['targetProject']);$data['configurationReady']=!empty($s['applyResult']['applied']);}
            }
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='operation')return ['operation'=>(string)$c->input('operation','plan')];
        if($step==='select'){$s['moduleLocator']=trim((string)$c->input('module_locator',''));unset($s['releaseResult']);$m=$s['moduleLocator']!==''?$this->selected($s['moduleLocator']):[];$s['semanticVersion']=(string)($m['release']['version']??'1.0.0');$s['dependencyLines']=$this->dependencyText((array)($m['release']['dependencies']??[]));$s['migrationJson']=json_encode((array)($m['release']['migrations']??[]),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?:'[]';}
        if($step==='configure'){$s['semanticVersion']=trim((string)$c->input('semantic_version',$s['semanticVersion']??'1.0.0'));if(($s['operation']??'')==='release'){SemVersion::normalize($s['semanticVersion']);unset($s['releaseResult']);}$s['dependencyLines']=(string)$c->input('dependency_lines',$s['dependencyLines']??'');$s['migrationJson']=(string)$c->input('migration_json',$s['migrationJson']??'[]');$s['rootLocator']=trim((string)$c->input('root_locator',$s['rootLocator']??''));$s['additionalRoots']=(string)$c->input('additional_roots',$s['additionalRoots']??'');$s['targetProject']=trim((string)$c->input('target_project',$s['targetProject']??($c->getProjectId()??'')));$s['dataFormMapping']=(string)$c->input('dataform_mapping',$s['dataFormMapping']??'');$s['referenceMapping']=(string)$c->input('reference_mapping',$s['referenceMapping']??'');$s['allowOverwrite']=$this->bool($c,'allow_overwrite');$s['applyDatasource']=$this->bool($c,'apply_datasource');unset($s['prepared'],$s['applyResult']);}
        if($step==='apply')$s['confirmApply']=$this->bool($c,'confirm_apply');return $s;
    }

    private function selected(string $locator):array{[$v,$p,$id]=$this->locator($locator);return $this->library->getScoped($id,$v,$p);}
    /** @return array{0:string,1:?string,2:string} */private function locator(string $l):array{$p=explode('::',$l);if(($p[0]??'')==='system'&&isset($p[1]))return['system',null,$p[1]];if(($p[0]??'')==='project'&&isset($p[1],$p[2]))return['project',$p[1],$p[2]];throw new \InvalidArgumentException('Ungültige Modulauswahl.');}

    /** @return list<array<string,mixed>> */
    private function parseMigrations(string $json):array{$json=trim($json);if($json===''||$json==='[]')return [];$d=json_decode($json,true);if(!is_array($d)||array_is_list($d)===false)throw new \InvalidArgumentException('Migrationen müssen als JSON-Array angegeben werden.');foreach($d as $m)if(!is_array($m))throw new \InvalidArgumentException('Jeder Migrationseintrag muss ein JSON-Objekt sein.');return array_values($d);}

    /** @return list<array{moduleId:string,constraint:string,optional:bool}> */
    private function parseDependencies(string $text):array{$out=[];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim($line);if($line==='')continue;$p=array_map('trim',explode('|',$line));$id=$p[0]??'';$constraint=$p[1]??'*';$kind=strtolower($p[2]??'required');if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige Abhängigkeits-ID: '.$id);if(!SemVersion::isValidConstraint($constraint))throw new \InvalidArgumentException('Ungültige Versionsbedingung für '.$id.': '.$constraint);$out[]=['moduleId'=>$id,'constraint'=>$constraint===''?'*':$constraint,'optional'=>in_array($kind,['optional','opt','yes','1'],true)];}return $out;}
    /** @param list<array<string,mixed>> $deps */private function dependencyText(array $deps):string{$lines=[];foreach($deps as $d)$lines[]=(string)($d['moduleId']??'').'|'.(string)($d['constraint']??'*').'|'.(!empty($d['optional'])?'optional':'required');return implode("\n",$lines);}
    /** @return list<string> */private function roots(array $s):array{$out=[];$first=trim((string)($s['rootLocator']??''));if($first!=='')$out[]=$first;foreach(preg_split('/\R/',(string)($s['additionalRoots']??''))?:[] as $line){$line=trim($line);if($line!=='')$out[]=$line;}return array_values(array_unique($out));}
    /** @return array{global:array<string,string>,modules:array<string,array<string,string>>} */
    private function parseMappings(string $text):array{$out=['global'=>[],'modules'=>[]];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim($line);if($line==='')continue;$parts=array_map('trim',explode('|',$line,2));if(count($parts)!==2||$parts[0]===''||$parts[1]==='')continue;$left=$parts[0];$target=$parts[1];if(str_contains($left,'::')){[$module,$source]=array_map('trim',explode('::',$left,2));if($module!==''&&$source!=='')$out['modules'][$module][$source]=$target;}else$out['global'][$left]=$target;}return $out;}
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){'operation'=>['operation'=>$s['operation']??'plan'],'select'=>['module_locator'=>$s['moduleLocator']??''],'configure'=>['semantic_version'=>$s['semanticVersion']??'1.0.0','dependency_lines'=>$s['dependencyLines']??'','migration_json'=>$s['migrationJson']??'[]','root_locator'=>$s['rootLocator']??'','additional_roots'=>$s['additionalRoots']??'','target_project'=>$s['targetProject']??($c->getProjectId()??''),'dataform_mapping'=>$s['dataFormMapping']??'','reference_mapping'=>$s['referenceMapping']??'','allow_overwrite'=>!empty($s['allowOverwrite']),'apply_datasource'=>!empty($s['applyDatasource'])],'apply'=>['confirm_apply'=>!empty($s['confirmApply'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
