<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\ReleaseCatalogService;
use EasyIT\Assistant\State\AssistantStateStore;

final class TrustPolicyAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $stateStore,private ReleaseCatalogService $catalog){}
    public function getId():string{return 'dataform.trust-policy';}
    public function getTitle():string{return 'Trust-Policy und Schlüsselverwaltung';}
    public function getDescription():string{return 'Verwaltet Ed25519-Schlüsselstatus, Vertrauensstufen, Gültigkeit, Sperrung/Widerruf, Rotation und den manipulationsgeschützten Trust-Audit-Verlauf.';}

    public function getSteps(AssistantContext $c):array
    {
        $status=$this->catalog->trustStatus();$keys=[''=>'Bitte Schlüssel auswählen'];foreach((array)($status['keys']??[]) as $k){if(!is_array($k))continue;$id=(string)($k['keyId']??'');$keys[$id]=$id.' · '.(string)($k['status']??'TRUSTED').' · '.(string)($k['trustLevel']??'RELEASE');}
        return [
            new AssistantStep('overview','1. Trust-Policy','Schlüsselstatus, Vertrauensstufen, Gültigkeiten und Audit-Kette anzeigen.'),
            new AssistantStep('manage','2. Schlüssel verwalten','Schlüssel sperren, reaktivieren, widerrufen, Vertrauensstufe oder Ablaufdatum ändern.',['fields'=>[
                ['name'=>'key_id','label'=>'Schlüssel','type'=>'select','required'=>true,'options'=>$keys],
                ['name'=>'operation','label'=>'Aktion','type'=>'select','required'=>true,'options'=>['evaluate'=>'Nur Installations-Policy prüfen','suspend'=>'Schlüssel sperren','reactivate'=>'Gesperrten Schlüssel reaktivieren','revoke'=>'Schlüssel unwiderruflich widerrufen','trust'=>'Vertrauensstufe ändern','validity'=>'Ablaufdatum ändern']],
                ['name'=>'trust_level','label'=>'Vertrauensstufe','type'=>'select','options'=>['RELEASE'=>'RELEASE – Signieren und Installieren','VERIFY_ONLY'=>'VERIFY_ONLY – nur historische Verifikation','NONE'=>'NONE – nicht vertrauenswürdig']],
                ['name'=>'valid_until','label'=>'Gültig bis (ISO/Datum; leer = unbegrenzt)','type'=>'text'],
                ['name'=>'reason','label'=>'Grund / Audit-Kommentar','type'=>'text'],
                ['name'=>'confirmation','label'=>'Exakte Bestätigung','type'=>'text'],
            ]]),
            new AssistantStep('rotate','3. Schlüsselrotation','Neuen aktiven Ed25519-Schlüssel kryptographisch an den bisherigen Schlüssel anbinden.',['fields'=>[['name'=>'rotation_confirmation','label'=>'Bestätigung: ROTATE <aktive Key-ID>','type'=>'text'],['name'=>'confirm_rotate','label'=>'Schlüssel jetzt rotieren','type'=>'checkbox']]]),
            new AssistantStep('audit','4. Audit und Ergebnis','Hashverketteten Trust-Audit-Verlauf und aktuelle Policy-Wirkung prüfen.'),
        ];
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->stateStore->clear($this->getId(),$scope);$s=$this->stateStore->get($this->getId(),$scope);$stepId=$stepId?:'overview';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];
        if($method==='POST'&&!$this->bool($c,'reset')){try{$s=$this->merge($s,$c,$stepId);$this->stateStore->put($this->getId(),$s,$scope);}catch(\Throwable $e){$errors[]=$e->getMessage();}}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='overview';
        $data=['phase'=>26,'formValues'=>$this->formValues($s,$stepId),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId),'catalogExportUrl'=>'release-catalog-export.php?type=catalog','trustExportUrl'=>'release-catalog-export.php?type=trust'];
        try{
            if($stepId==='overview'){ $ts=$this->catalog->trustStatus(); if(empty($ts['activeKeyId'])&&empty($ts['keys']))$this->catalog->ensureKey(); }
            if($method==='POST'&&$stepId==='manage'){$id=(string)($s['keyId']??'');$op=(string)($s['operation']??'evaluate');$reason=(string)($s['reason']??'');$confirm=(string)($s['confirmation']??'');$result=match($op){'suspend'=>$this->catalog->suspendKey($id,$reason,$confirm),'reactivate'=>$this->catalog->reactivateKey($id,$reason,$confirm),'revoke'=>$this->catalog->revokeKey($id,$reason,$confirm),'trust'=>$this->catalog->setKeyTrustLevel($id,(string)($s['trustLevel']??'RELEASE'),$reason,$confirm),'validity'=>$this->catalog->setKeyValidity($id,trim((string)($s['validUntil']??''))!==''?(string)$s['validUntil']:null,$reason,$confirm),default=>$this->catalog->evaluateKey($id,'install')};$s['operationResult']=$result;$this->stateStore->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='rotate'&&!empty($s['confirmRotate'])){$s['rotationResult']=$this->catalog->rotateKey((string)($s['rotationConfirmation']??''));$s['confirmRotate']=false;$s['rotationConfirmation']='';$this->stateStore->put($this->getId(),$s,$scope);}
            $data['trustStatus']=$this->catalog->trustStatus();$data['trustAudit']=$this->catalog->trustAudit();$data['auditVerification']=$this->catalog->verifyTrustAudit();$data['operationResult']=$s['operationResult']??null;$data['rotationResult']=$s['rotationResult']??null;$data['configurationReady']=!empty($data['auditVerification']['ok'])&&!empty($data['trustStatus']['chainVerification']['ok']);
        }catch(\Throwable $e){$errors[]=$e->getMessage();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data);
    }

    private function merge(array $s,AssistantContext $c,string $step):array
    {
        if($step==='manage'){$s['keyId']=trim((string)$c->input('key_id',''));$s['operation']=trim((string)$c->input('operation','evaluate'));$s['trustLevel']=strtoupper(trim((string)$c->input('trust_level','RELEASE')));$s['validUntil']=trim((string)$c->input('valid_until',''));$s['reason']=trim((string)$c->input('reason',''));$s['confirmation']=trim((string)$c->input('confirmation',''));}
        if($step==='rotate'){$s['rotationConfirmation']=trim((string)$c->input('rotation_confirmation',''));$s['confirmRotate']=$this->bool($c,'confirm_rotate');}
        return $s;
    }
    private function formValues(array $s,string $step):array{return match($step){'manage'=>['key_id'=>$s['keyId']??'','operation'=>$s['operation']??'evaluate','trust_level'=>$s['trustLevel']??'RELEASE','valid_until'=>$s['validUntil']??'','reason'=>$s['reason']??'','confirmation'=>$s['confirmation']??''],'rotate'=>['rotation_confirmation'=>$s['rotationConfirmation']??'','confirm_rotate'=>!empty($s['confirmRotate'])],default=>[]};}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
