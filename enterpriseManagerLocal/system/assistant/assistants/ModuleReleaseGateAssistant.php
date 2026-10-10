<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\DataFormModuleLibraryService;
use EasyIT\Assistant\Module\ModuleReleaseGateService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ModuleReleaseGateAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore,private DataFormModuleLibraryService $library,private ModuleReleaseGateService $gate){}
    public function getId():string{return 'dataform.module-release-gate';}
    public function getTitle():string{return 'Migrations-Gate und Release-Freigabe';}
    public function getDescription():string{return 'Prüft Modul-Releases isoliert auf Paketintegrität, Abhängigkeiten, Dry-Run, Upgrade/Migration, Schema-Diff, Recovery-Checkpoint und exakten Rollback. Erst RELEASED ist installierbar.';}

    public function getSteps(AssistantContext $c):array
    {
        $options=[''=>'Bitte Modul auswählen'];foreach($this->library->list($c->getProjectId()) as $m){$v=(string)($m['visibility']??'system');$p=(string)($m['ownerProject']??'');$loc=$v==='project'?'project::'.$p.'::'.$m['id']:'system::'.$m['id'];$options[$loc]=(string)$m['name'].' v'.(string)($m['releaseVersion']??'1.0.0').' ['.(string)($m['releaseGateStatus']??'LEGACY_RELEASED').']';}
        return [
            new AssistantStep('select','1. Release auswählen','Release und Referenzprojekt für den isolierten Gate-Lauf auswählen.',['fields'=>[['name'=>'module_locator','label'=>'Modul-Release','type'=>'select','required'=>true,'options'=>$options],['name'=>'validation_project','label'=>'Referenz-/Validierungsprojekt','type'=>'text','required'=>true]]]),
            new AssistantStep('gate','2. Gate ausführen','Referenzprojekt klonen und den kompletten Prüfzyklus ohne Änderung am Originalprojekt ausführen.',['fields'=>[['name'=>'confirm_gate','label'=>'Isolierten Gate-Lauf jetzt vollständig ausführen','type'=>'checkbox']]]),
            new AssistantStep('approve','3. Release freigeben','Nur ein bestandenes Gate kann ausdrücklich als RELEASED freigegeben werden.',['fields'=>[['name'=>'release_confirmation','label'=>'Bestätigung exakt: modul-id@version','type'=>'text','required'=>true],['name'=>'accept_warnings','label'=>'Gate-Warnungen ausdrücklich akzeptieren','type'=>'checkbox'],['name'=>'confirm_release','label'=>'Release jetzt freigeben und installierbar setzen','type'=>'checkbox']]]),
            new AssistantStep('result','4. Ergebnis','Freigabestatus, Gate-Bericht und Installierbarkeit anzeigen.'),
        ];
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);$s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'select';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='select';$data=['phase'=>24,'moduleLibrary'=>$this->library->list($c->getProjectId()),'formValues'=>$this->formValues($s,$stepId,$c),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            $locator=trim((string)($s['moduleLocator']??''));if($locator!==''){$selected=$this->selected($locator);$data['selectedModule']=$selected;$data['releaseGateStatus']=$selected['releaseGate']??null;$data['releaseGateReports']=$this->gate->reports($locator);}
            if(in_array($stepId,['gate','approve','result'],true)){
                if($locator==='')throw new \RuntimeException('Modul-Release fehlt.');$project=trim((string)($s['validationProject']??''));if($project==='')throw new \RuntimeException('Validierungsprojekt fehlt.');
                if($stepId==='gate'&&!empty($s['confirmGate'])){$s['gateReport']=$this->gate->gate($locator,$project);$s['reportId']=(string)($s['gateReport']['reportId']??'');$s['confirmGate']=false;unset($s['approvalResult']);$this->stateStore->put($this->getId(),$s,$scope);}
                $data['releaseGateReport']=$s['gateReport']??null;
                if($stepId==='approve'&&!empty($s['confirmRelease'])){if(trim((string)($s['reportId']??''))==='')throw new \RuntimeException('Es liegt kein Gate-Bericht zur Freigabe vor.');$s['approvalResult']=$this->gate->release($locator,(string)$s['reportId'],(string)($s['releaseConfirmation']??''),!empty($s['acceptWarnings']));$s['confirmRelease']=false;$this->stateStore->put($this->getId(),$s,$scope);}
                $data['releaseApprovalResult']=$s['approvalResult']??null;
                if($stepId==='result'){$selected=$this->selected($locator);$data['selectedModule']=$selected;$data['releaseGateStatus']=$selected['releaseGate']??null;$data['releaseGateReports']=$this->gate->reports($locator);$data['configurationReady']=!empty($selected['releaseGate']['installable']);}
            }
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='select'){$s['moduleLocator']=trim((string)$c->input('module_locator',''));$s['validationProject']=trim((string)$c->input('validation_project',$c->getProjectId()??''));unset($s['gateReport'],$s['reportId'],$s['approvalResult']);}
        if($step==='gate')$s['confirmGate']=$this->bool($c,'confirm_gate');
        if($step==='approve'){$s['releaseConfirmation']=trim((string)$c->input('release_confirmation',''));$s['acceptWarnings']=$this->bool($c,'accept_warnings');$s['confirmRelease']=$this->bool($c,'confirm_release');}
        return $s;
    }
    private function selected(string $locator):array{[$v,$p,$id]=$this->locator($locator);$m=$this->library->getScoped($id,$v,$p);if($m===[])throw new \RuntimeException('Modul wurde nicht gefunden.');return $m;}
    /** @return array{0:string,1:?string,2:string} */private function locator(string $l):array{$p=explode('::',$l);if(($p[0]??'')==='system'&&count($p)===2)return['system',null,$p[1]];if(($p[0]??'')==='project'&&count($p)===3)return['project',$p[1],$p[2]];throw new \InvalidArgumentException('Ungültige Modulauswahl.');}
    private function formValues(array $s,string $step,AssistantContext $c):array{return match($step){'select'=>['module_locator'=>$s['moduleLocator']??'','validation_project'=>$s['validationProject']??($c->getProjectId()??'')],'gate'=>['confirm_gate'=>!empty($s['confirmGate'])],'approve'=>['release_confirmation'=>$s['releaseConfirmation']??'','accept_warnings'=>!empty($s['acceptWarnings']),'confirm_release'=>!empty($s['confirmRelease'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
