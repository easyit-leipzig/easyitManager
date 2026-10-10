<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\ModuleUpdateService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ModuleUpdateAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore,private ModuleUpdateService $updates){}
    public function getId():string{return 'dataform.module-updates';}
    public function getTitle():string{return 'Modul-Updates und Upgrades';}
    public function getDescription():string{return 'Vergleicht installierte Module mit der Bibliothek, prüft Abhängigkeiten und Auswirkungen, erzeugt einen Upgrade-Plan und sichert vor der Änderung einen rollbackfähigen Projektstand.';}

    public function getSteps(AssistantContext $c):array
    {
        return [
            new AssistantStep('project','1. Projekt','Projekt auswählen und installierte Modulversionen laden.',['fields'=>[['name'=>'target_project','label'=>'Projekt','type'=>'text','required'=>true]]]),
            new AssistantStep('scan','2. Updates suchen','Installierte Module mit den sichtbaren Bibliotheks-Releases vergleichen.'),
            new AssistantStep('configure','3. Upgrade festlegen','Zu aktualisierende Module und Mapping festlegen. Leer bedeutet: alle verfügbaren Updates.',['fields'=>[
                ['name'=>'module_ids','label'=>'Module aktualisieren (eine Modul-ID je Zeile; leer = alle Updates)','type'=>'textarea','rows'=>7],
                ['name'=>'cascade','label'=>'Abhängige Module bei Bedarf automatisch mit aktualisieren','type'=>'checkbox'],
                ['name'=>'dataform_mapping','label'=>'Optionales DataForm-Mapping: quelle|ziel oder modul::quelle|ziel','type'=>'textarea','rows'=>6],
                ['name'=>'reference_mapping','label'=>'Optionales Referenz-Mapping: alt|neu oder modul::alt|neu','type'=>'textarea','rows'=>6],
                ['name'=>'apply_datasource','label'=>'Datenquellen-Snapshots aus Updates übernehmen','type'=>'checkbox'],
            ]]),
            new AssistantStep('preview','4. Upgrade-Plan','Abhängigkeiten, Reverse-Dependencies, Zielversionen und Installationsreihenfolge vollständig prüfen.'),
            new AssistantStep('apply','5. Sichern und aktualisieren','Vor dem ersten Schreibzugriff vollständiges Recovery-Backup erzeugen und danach den geprüften Upgrade-Plan ausführen.',['fields'=>[['name'=>'confirm_apply','label'=>'Rollback-Backup erzeugen und Upgrade jetzt ausführen','type'=>'checkbox']]]),
            new AssistantStep('result','6. Ergebnis','Upgrade-Ergebnis, installierte Versionen und Rollback-Checkpoint anzeigen.'),
        ];
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);$s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'project';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='project';$project=trim((string)($s['targetProject']??($c->getProjectId()??'')));
        $data=['phase'=>21,'formValues'=>$this->formValues($s,$stepId,$c),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            if(in_array($stepId,['scan','configure','preview','apply','result'],true)){$data['moduleUpdateScan']=$this->updates->scan($project);$data['moduleUpdateCheckpoints']=$this->updates->checkpoints($project);}
            if(in_array($stepId,['preview','apply','result'],true)){
                if(!is_array($s['prepared']??null)||$stepId==='preview'){$s['prepared']=$this->updates->prepare($project,$this->moduleIds((string)($s['moduleIds']??'')),$this->parseMappings((string)($s['dataFormMapping']??'')),$this->parseMappings((string)($s['referenceMapping']??'')),!array_key_exists('cascade',$s)||!empty($s['cascade']),!empty($s['applyDatasource']));$this->stateStore->put($this->getId(),$s,$scope);}
                $data['moduleUpdatePlan']=$s['prepared'];if(!empty($s['prepared']['requiresMigrationAssistant'])){$data['handoffUrl']='run.php?'.http_build_query(['assistant'=>'dataform.module-migrations','project_id'=>$project,'step'=>'project']);$data['handoffLabel']='Migrationspflichtiges Update mit Phase 22 fortsetzen';}
                if($stepId==='apply'&&!empty($s['confirmApply'])&&!is_array($s['applyResult']??null)){$s['applyResult']=$this->updates->apply((array)$s['prepared']);$this->stateStore->put($this->getId(),$s,$scope);}
                $data['moduleUpdateResult']=$s['applyResult']??null;if(is_array($s['applyResult']??null)){ $f=(string)($s['applyResult']['rollbackCheckpoint']['fileName']??''); if($f!==''){ $data['rollbackDownloadUrl']='download.php?file='.rawurlencode($f); $data['rollbackShaDownloadUrl']='download.php?file='.rawurlencode($f.'.sha256'); }}
                if($stepId==='result'){$data['moduleUpdateScan']=$this->updates->scan($project);$data['moduleUpdateCheckpoints']=$this->updates->checkpoints($project);$data['configurationReady']=is_array($s['applyResult']??null)&&!empty($s['applyResult']['applied']);}
            }
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));
        if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='project'){$s=['targetProject'=>trim((string)$c->input('target_project',$c->getProjectId()??'')),'cascade'=>true];}
        if($step==='configure'){$s['moduleIds']=(string)$c->input('module_ids',$s['moduleIds']??'');$s['cascade']=$this->bool($c,'cascade');$s['dataFormMapping']=(string)$c->input('dataform_mapping',$s['dataFormMapping']??'');$s['referenceMapping']=(string)$c->input('reference_mapping',$s['referenceMapping']??'');$s['applyDatasource']=$this->bool($c,'apply_datasource');unset($s['prepared'],$s['applyResult']);}
        if($step==='apply')$s['confirmApply']=$this->bool($c,'confirm_apply');return $s;
    }
    /** @return list<string> */private function moduleIds(string $text):array{$out=[];foreach(preg_split('/\R/',$text)?:[] as $line){$id=trim($line);if($id==='')continue;if(!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/',$id))throw new \InvalidArgumentException('Ungültige Modul-ID: '.$id);$out[]=$id;}return array_values(array_unique($out));}
    /** @return array{global:array<string,string>,modules:array<string,array<string,string>>} */private function parseMappings(string $text):array{$out=['global'=>[],'modules'=>[]];foreach(preg_split('/\R/',$text)?:[] as $line){$line=trim($line);if($line==='')continue;$parts=array_map('trim',explode('|',$line,2));if(count($parts)!==2||$parts[0]===''||$parts[1]==='')continue;if(str_contains($parts[0],'::')){[$m,$src]=array_map('trim',explode('::',$parts[0],2));if($m!==''&&$src!=='')$out['modules'][$m][$src]=$parts[1];}else$out['global'][$parts[0]]=$parts[1];}return $out;}
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){'project'=>['target_project'=>$s['targetProject']??($c->getProjectId()??'')],'configure'=>['module_ids'=>$s['moduleIds']??'','cascade'=>!array_key_exists('cascade',$s)||!empty($s['cascade']),'dataform_mapping'=>$s['dataFormMapping']??'','reference_mapping'=>$s['referenceMapping']??'','apply_datasource'=>!empty($s['applyDatasource'])],'apply'=>['confirm_apply'=>!empty($s['confirmApply'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
