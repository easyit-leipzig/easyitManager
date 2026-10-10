<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\ModuleMigrationService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ModuleMigrationAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore,private ModuleMigrationService $migrations){}
    public function getId():string{return 'dataform.module-migrations';}
    public function getTitle():string{return 'Modul-Updates und Migrationen';}
    public function getDescription():string{return 'Simuliert deklarative Modulmigrationen, erzeugt vor der ersten Änderung ein Recovery-Backup und führt Migrationen und Upgrade in definierter Reihenfolge aus.';}

    public function getSteps(AssistantContext $c):array
    {
        return [
            new AssistantStep('project','1. Projekt','Projekt auswählen, dessen installierte Module migriert werden sollen.',['fields'=>[['name'=>'target_project','label'=>'Projekt','type'=>'text','required'=>true]]]),
            new AssistantStep('configure','2. Update und Mapping','Zu aktualisierende Module und vorhandene Zielabbildungen festlegen. Leer bedeutet: alle verfügbaren Updates.',['fields'=>[
                ['name'=>'module_ids','label'=>'Module aktualisieren (eine Modul-ID je Zeile; leer = alle verfügbaren Updates)','type'=>'textarea','rows'=>7],
                ['name'=>'cascade','label'=>'Abhängige Module bei Bedarf automatisch mit aktualisieren','type'=>'checkbox'],
                ['name'=>'dataform_mapping','label'=>'Optionales DataForm-Mapping: quelle|ziel oder modul::quelle|ziel','type'=>'textarea','rows'=>6],
                ['name'=>'reference_mapping','label'=>'Optionales Referenz-Mapping: alt|neu oder modul::alt|neu','type'=>'textarea','rows'=>6],
                ['name'=>'apply_datasource','label'=>'Datenquellen-Snapshot des Releases übernehmen','type'=>'checkbox'],
            ]]),
            new AssistantStep('preview','3. Migration simulieren','Versionspfad, Abhängigkeiten, Migrationsreihenfolge, Zielzustände und destruktive Schritte ohne Schreibzugriff prüfen.'),
            new AssistantStep('apply','4. Sichern und migrieren','Recovery-Backup erzeugen und nur den vollständig geprüften Plan ausführen.',['fields'=>[['name'=>'confirm_apply','label'=>'Geprüften Migrations- und Upgrade-Plan jetzt ausführen','type'=>'checkbox']]]),
            new AssistantStep('result','5. Ergebnis','Migrationsregister, Rollback-Checkpoint und Upgrade-Ergebnis anzeigen.'),
        ];
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);$s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'project';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='project';$data=['phase'=>22,'formValues'=>$this->formValues($s,$stepId,$c),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            $project=trim((string)($s['targetProject']??($c->getProjectId()??'')));if(in_array($stepId,['preview','apply','result'],true)){
                if($project==='')throw new \RuntimeException('Zielprojekt fehlt.');
                $data['migrationHistoryUrl']='run.php?'.http_build_query(['assistant'=>'dataform.module-migration-history','project_id'=>$project,'step'=>'history']);if(!is_array($s['prepared']??null)||$stepId==='preview'){$s['prepared']=$this->migrations->prepare($project,$this->moduleIds((string)($s['moduleIds']??'')),$this->parseMappings((string)($s['dataFormMapping']??'')),$this->parseMappings((string)($s['referenceMapping']??'')),!empty($s['cascade']),!empty($s['applyDatasource']));$this->stateStore->put($this->getId(),$s,$scope);} $data['moduleMigrationPlan']=$s['prepared'];
                if($stepId==='apply'&&!empty($s['confirmApply'])){$s['applyResult']=$this->migrations->apply((array)$s['prepared']);$this->stateStore->put($this->getId(),$s,$scope);}$data['moduleMigrationResult']=$s['applyResult']??null;if(is_array($s['applyResult']??null)){$f=(string)($s['applyResult']['rollbackCheckpoint']['fileName']??'');if($f!==''){$data['rollbackDownloadUrl']='download.php?file='.rawurlencode($f);$data['rollbackShaDownloadUrl']='download.php?file='.rawurlencode($f.'.sha256');}}
                if($stepId==='result'){$data['migrationHistory']=$this->migrations->history($project);if(!is_array($s['applyResult']??null))throw new \RuntimeException('Migrationsplan wurde noch nicht ausgeführt.');$data['configurationReady']=!empty($s['applyResult']['applied']);}
            }
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='project'){$s['targetProject']=trim((string)$c->input('target_project',$c->getProjectId()??''));unset($s['prepared'],$s['applyResult']);}
        if($step==='configure'){$s['moduleIds']=(string)$c->input('module_ids',$s['moduleIds']??'');$s['cascade']=$this->bool($c,'cascade');$s['dataFormMapping']=(string)$c->input('dataform_mapping',$s['dataFormMapping']??'');$s['referenceMapping']=(string)$c->input('reference_mapping',$s['referenceMapping']??'');$s['applyDatasource']=$this->bool($c,'apply_datasource');unset($s['prepared'],$s['applyResult']);}
        if($step==='apply')$s['confirmApply']=$this->bool($c,'confirm_apply');return $s;
    }
    /** @return list<string> */ private function moduleIds(string $text):array{$out=[];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim($line);if($line!=='')$out[]=$line;}return array_values(array_unique($out));}
    /** @return array{global:array<string,string>,modules:array<string,array<string,string>>} */ private function parseMappings(string $text):array{$out=['global'=>[],'modules'=>[]];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim($line);if($line==='')continue;$p=array_map('trim',explode('|',$line,2));if(count($p)!==2||$p[0]===''||$p[1]==='')continue;if(str_contains($p[0],'::')){[$m,$src]=array_map('trim',explode('::',$p[0],2));if($m!==''&&$src!=='')$out['modules'][$m][$src]=$p[1];}else$out['global'][$p[0]]=$p[1];}return $out;}
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){'project'=>['target_project'=>$s['targetProject']??($c->getProjectId()??'')],'configure'=>['module_ids'=>$s['moduleIds']??'','cascade'=>array_key_exists('cascade',$s)?!empty($s['cascade']):true,'dataform_mapping'=>$s['dataFormMapping']??'','reference_mapping'=>$s['referenceMapping']??'','apply_datasource'=>!empty($s['applyDatasource'])],'apply'=>['confirm_apply'=>!empty($s['confirmApply'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
