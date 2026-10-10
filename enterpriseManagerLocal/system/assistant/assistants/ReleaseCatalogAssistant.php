<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\DataFormModuleLibraryService;
use EasyIT\Assistant\Module\ReleaseCatalogService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ReleaseCatalogAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore,private DataFormModuleLibraryService $library,private ReleaseCatalogService $catalog){}
    public function getId():string{return 'dataform.release-catalog';}
    public function getTitle():string{return 'Release-Katalog und Vertrauenskette';}
    public function getDescription():string{return 'Katalogisiert RELEASED-Module, signiert die Bindung aus Paket-SHA, Release-Fingerprint und Gate-Bericht mit Ed25519 und verifiziert die Vertrauenskette vor Installation oder Update.';}

    public function getSteps(AssistantContext $c):array
    {
        $options=[''=>'Bitte RELEASED-Modul auswählen'];foreach($this->library->list($c->getProjectId()) as $m){$status=(string)($m['releaseGateStatus']??'LEGACY_RELEASED');if($status!=='RELEASED')continue;$v=(string)($m['visibility']??'system');$p=(string)($m['ownerProject']??'');$loc=$v==='project'?'project::'.$p.'::'.$m['id']:'system::'.$m['id'];$options[$loc]=(string)$m['name'].' v'.(string)($m['releaseVersion']??'1.0.0').' ['.$status.']';}
        return [
            new AssistantStep('overview','1. Vertrauensstatus','Aktiven Ed25519-Schlüssel, bekannte vertrauenswürdige Schlüssel und Release-Katalog anzeigen.'),
            new AssistantStep('release','2. Release prüfen/signieren','Ein RELEASED-Modul auswählen und entweder verifizieren oder neu in den signierten Katalog aufnehmen.',['fields'=>[['name'=>'module_locator','label'=>'RELEASED-Modul','type'=>'select','required'=>true,'options'=>$options],['name'=>'operation','label'=>'Aktion','type'=>'select','required'=>true,'options'=>['verify'=>'Vertrauenskette verifizieren','catalog'=>'Neu/erneut signiert katalogisieren']]]]),
            new AssistantStep('key','3. Signaturschlüssel','Optional den aktiven Ed25519-Schlüssel rotieren. Alte Releases bleiben über den Trust-Store verifizierbar.',['fields'=>[['name'=>'rotation_confirmation','label'=>'Bestätigung: ROTATE <aktuelle Key-ID>','type'=>'text'],['name'=>'confirm_rotate','label'=>'Signaturschlüssel jetzt rotieren','type'=>'checkbox']]]),
            new AssistantStep('result','4. Ergebnis','Katalog, Signatur- und Trust-Ergebnis anzeigen.'),
        ];
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);$s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'overview';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='overview';$data=['phase'=>25,'catalogExportUrl'=>'release-catalog-export.php?type=catalog','trustExportUrl'=>'release-catalog-export.php?type=trust','formValues'=>$this->formValues($s,$stepId),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            $data['trustStatus']=$this->catalog->trustStatus();$data['releaseCatalog']=$this->catalog->entries();
            if($stepId==='overview'&&empty($data['trustStatus']['activeKeyId'])&&empty($data['trustStatus']['keys'])){$data['activeTrustKey']=$this->catalog->ensureKey();$data['trustStatus']=$this->catalog->trustStatus();}
            if(in_array($stepId,['release','result'],true)&&trim((string)($s['moduleLocator']??''))!==''){$locator=(string)$s['moduleLocator'];[$v,$p,$id]=$this->locator($locator);if(($s['operation']??'verify')==='catalog'&&$method==='POST'&&$stepId==='release'){$s['catalogResult']=$this->catalog->catalogReleased($id,$v,$p);$s['operation']='verify';$this->stateStore->put($this->getId(),$s,$scope);}$s['verificationResult']=$this->catalog->verifyLibraryRelease($id,$v,$p);$this->stateStore->put($this->getId(),$s,$scope);$data['catalogResult']=$s['catalogResult']??null;$data['verificationResult']=$s['verificationResult'];$data['configurationReady']=!empty($s['verificationResult']['ok']);}
            if($stepId==='key'&&!empty($s['confirmRotate'])){$s['rotationResult']=$this->catalog->rotateKey((string)($s['rotationConfirmation']??''));$s['confirmRotate']=false;$s['rotationConfirmation']='';$this->stateStore->put($this->getId(),$s,$scope);$data['rotationResult']=$s['rotationResult'];$data['trustStatus']=$this->catalog->trustStatus();}
            if($stepId==='result'){$data['rotationResult']=$s['rotationResult']??null;$data['catalogResult']=$s['catalogResult']??null;$data['verificationResult']=$s['verificationResult']??null;$data['releaseCatalog']=$this->catalog->entries();$data['trustStatus']=$this->catalog->trustStatus();}
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array{if($step==='release'){$s['moduleLocator']=trim((string)$c->input('module_locator',''));$s['operation']=trim((string)$c->input('operation','verify'));unset($s['verificationResult']);}if($step==='key'){$s['rotationConfirmation']=trim((string)$c->input('rotation_confirmation',''));$s['confirmRotate']=$this->bool($c,'confirm_rotate');}return $s;}
    /** @return array{0:string,1:?string,2:string} */private function locator(string $l):array{$p=explode('::',$l);if(($p[0]??'')==='system'&&count($p)===2)return['system',null,$p[1]];if(($p[0]??'')==='project'&&count($p)===3)return['project',$p[1],$p[2]];throw new \InvalidArgumentException('Ungültige Modulauswahl.');}
    private function formValues(array $s,string $step):array{return match($step){'release'=>['module_locator'=>$s['moduleLocator']??'','operation'=>$s['operation']??'verify'],'key'=>['rotation_confirmation'=>$s['rotationConfirmation']??'','confirm_rotate'=>!empty($s['confirmRotate'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
