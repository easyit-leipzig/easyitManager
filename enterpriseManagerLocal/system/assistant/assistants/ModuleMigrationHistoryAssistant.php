<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\ModuleMigrationAuditService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ModuleMigrationHistoryAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore, private ModuleMigrationAuditService $audit) {}
    public function getId(): string { return 'dataform.module-migration-history'; }
    public function getTitle(): string { return 'Migrationshistorie, Schema-Diff und Rollback'; }
    public function getDescription(): string { return 'Zeigt versionierte Migrationsläufe mit Vorher-/Nachher-Schema, diagnostiziert Fehlerschritte und setzt lokale Projekte kontrolliert auf den Recovery-Checkpoint eines Laufs zurück.'; }

    public function getSteps(AssistantContext $c): array
    {
        return [
            new AssistantStep('project','1. Projekt','Projekt auswählen, dessen Migrationsläufe untersucht werden sollen.',['fields'=>[['name'=>'target_project','label'=>'Projekt','type'=>'text','required'=>true]]]),
            new AssistantStep('history','2. Historie','Alle protokollierten Migrationsläufe mit Status, Diff-Anzahl und Fehlerstelle anzeigen.'),
            new AssistantStep('detail','3. Lauf und Schema-Diff','Eine Run-ID auswählen und Vorher-/Nachher-Snapshot, Schema-Diff, ausgeführte Schritte sowie Checkpoint prüfen.',['fields'=>[['name'=>'run_id','label'=>'Migrations-Run-ID','type'=>'text','required'=>true]]]),
            new AssistantStep('rollback','4. Kontrollierter Rollback','Den ausgewählten Lauf auf seinen Recovery-Checkpoint zurücksetzen. Zur Sicherheit muss die Projekt-ID exakt bestätigt werden.',['fields'=>[
                ['name'=>'confirm_project','label'=>'Projekt-ID zur Rollback-Bestätigung','type'=>'text','required'=>true],
                ['name'=>'confirm_rollback','label'=>'Rollback jetzt ausführen','type'=>'checkbox'],
            ]]),
            new AssistantStep('result','5. Ergebnis','Rollback- und Verifikationsdiagnose anzeigen.'),
        ];
    }

    public function run(AssistantContext $c, ?string $stepId = null): AssistantResult
    {
        $scope=$c->getProjectId()?:'global';
        if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);
        $s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'project';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='project';
        $data=['phase'=>23,'formValues'=>$this->formValues($s,$stepId,$c),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            $project=trim((string)($s['targetProject']??($c->getProjectId()??'')));
            if(in_array($stepId,['history','detail','rollback','result'],true)){
                if($project==='')throw new \RuntimeException('Zielprojekt fehlt.');
                $data['migrationRuns']=$this->audit->listRuns($project);
            }
            if(in_array($stepId,['detail','rollback','result'],true)){
                $runId=trim((string)($s['runId']??''));
                if($runId==='')throw new \RuntimeException('Migrations-Run-ID fehlt.');
                $run=$this->audit->getRun($project,$runId);
                if($run===[])throw new \RuntimeException('Migrationslauf wurde nicht gefunden: '.$runId);
                $data['migrationRun']=$run;$data['schemaDiff']=$run['schemaDiff']??null;
                $data['failureDiagnosis']=$this->failureDiagnosis($run);
                $cp=(array)($run['checkpoint']??[]);$file=(string)($cp['fileName']??'');if($file!==''){$data['rollbackDownloadUrl']='download.php?file='.rawurlencode($file);$data['rollbackShaDownloadUrl']='download.php?file='.rawurlencode($file.'.sha256');}
            }
            if($stepId==='rollback'&&!empty($s['confirmRollback'])){
                $s['rollbackResult']=$this->audit->rollback($project,(string)$s['runId'],(string)($s['confirmProject']??''));$this->stateStore->put($this->getId(),$s,$scope);$data['rollbackResult']=$s['rollbackResult'];
                if(empty($s['rollbackResult']['ok']))$errors[]=(string)($s['rollbackResult']['message']??'Rollback fehlgeschlagen.');
            }
            if($stepId==='result'){
                $data['rollbackResult']=$s['rollbackResult']??null;
                if(!is_array($s['rollbackResult']??null))$data['resultMessage']='Für diesen Assistentenlauf wurde noch kein Rollback ausgeführt.';
            }
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));
        if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);
        return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='project'){$s['targetProject']=trim((string)$c->input('target_project',$c->getProjectId()??''));unset($s['runId'],$s['rollbackResult'],$s['confirmProject'],$s['confirmRollback']);}
        if($step==='detail'){$s['runId']=trim((string)$c->input('run_id',$s['runId']??''));unset($s['rollbackResult'],$s['confirmProject'],$s['confirmRollback']);}
        if($step==='rollback'){$s['confirmProject']=trim((string)$c->input('confirm_project',''));$s['confirmRollback']=$this->bool($c,'confirm_rollback');}
        return $s;
    }
    /** @return array<string,mixed> */
    private function failureDiagnosis(array $run):array
    {
        if(($run['status']??'')!=='FAILED')return ['status'=>'NOT_FAILED','message'=>'Der Migrationslauf ist nicht fehlgeschlagen.'];
        $step=is_array($run['failedStep']??null)?$run['failedStep']:[];
        return ['status'=>'FAILED','moduleId'=>$step['moduleId']??null,'migrationId'=>$step['id']??null,'type'=>$step['type']??null,'phase'=>$step['phase']??null,'message'=>$run['error']??'Unbekannter Fehler','recommendation'=>'Fehlerursache im betroffenen Migrationsschritt korrigieren oder den Recovery-Checkpoint kontrolliert zurückspielen.'];
    }
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){'project'=>['target_project'=>$s['targetProject']??($c->getProjectId()??'')],'detail'=>['run_id'=>$s['runId']??''],'rollback'=>['confirm_project'=>$s['confirmProject']??'','confirm_rollback'=>!empty($s['confirmRollback'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
