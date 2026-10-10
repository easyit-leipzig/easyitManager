<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\TrustRecoveryService;
use EasyIT\Assistant\State\AssistantStateStore;

final class TrustRecoveryAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store,private TrustRecoveryService $service){}
    public function getId():string{return 'dataform.trust-recovery';}
    public function getTitle():string{return 'Trust-Backup, Offline-Verifikation und Recovery';}
    public function getDescription():string{return 'Exportiert/importiert öffentliche Trust-Anker, sichert private Signing-Keys ausschließlich verschlüsselt, erzeugt Offline-Prüfpakete und führt kontrollierte Lost-Key-Recovery durch.';}

    public function getSteps(AssistantContext $c):array
    {
        $catalog=[''=>'Bitte Katalogeintrag auswählen'];foreach($this->service->catalogEntries() as $e){$id=(string)($e['catalogId']??'');$p=(array)($e['payload']??[]);if($id!=='')$catalog[$id]=$id.' · '.(string)($p['moduleId']??'').'@'.(string)($p['version']??'');}
        $keys=[''=>'Bitte verlorenen Schlüssel auswählen'];$status=$this->service->status();foreach((array)($status['trust']['keys']??[]) as $k){if(!is_array($k))continue;$id=(string)($k['keyId']??'');if($id!=='')$keys[$id]=$id.' · '.(string)($k['status']??'TRUSTED').' · '.(string)($k['trustLevel']??'RELEASE');}
        return [
            new AssistantStep('overview','1. Recovery-Status','Trust-Chain, Audit, private Key-Verfügbarkeit und vorhandene Recovery-Artefakte prüfen.'),
            new AssistantStep('public','2. Öffentliche Trust-Anker','Öffentliche Trust-Daten exportieren oder ein geprüftes Bundle importieren. Private Schlüssel sind niemals enthalten.',['fields'=>[
                ['name'=>'public_operation','label'=>'Vorgang','type'=>'select','options'=>['export'=>'Public-Trust-Bundle exportieren','import'=>'Public-Trust-Bundle importieren']],
                ['name'=>'public_bundle','label'=>'Public-Trust-Bundle (.zip)','type'=>'file','accept'=>'.zip,application/zip'],
                ['name'=>'allow_release','label'=>'Importierte neue Schlüssel ausdrücklich mit RELEASE-Vertrauen übernehmen','type'=>'checkbox'],
                ['name'=>'apply_policy','label'=>'Trust-Policy aus Bundle übernehmen','type'=>'checkbox'],
                ['name'=>'public_confirmation','label'=>'Bei RELEASE-Import exakt: IMPORT PUBLIC ANCHORS AS RELEASE','type'=>'text'],
            ]]),
            new AssistantStep('private','3. Private Signing-Keys','Private Schlüssel nur passphrasengeschützt sichern oder aus einem verschlüsselten Backup wiederherstellen. Passphrases werden nicht persistiert.',['fields'=>[
                ['name'=>'private_operation','label'=>'Vorgang','type'=>'select','options'=>['backup'=>'Verschlüsseltes Private-Key-Backup erzeugen','restore'=>'Private-Key-Backup wiederherstellen']],
                ['name'=>'private_backup','label'=>'Verschlüsseltes Private-Key-Backup (.json)','type'=>'file','accept'=>'.json,application/json'],
                ['name'=>'passphrase','label'=>'Backup-Passphrase (mind. 10 Zeichen)','type'=>'password'],
                ['name'=>'private_reason','label'=>'Restore-Grund / Audit-Kommentar','type'=>'text'],
                ['name'=>'private_confirmation','label'=>'BACKUP PRIVATE KEYS oder RESTORE PRIVATE KEYS','type'=>'text'],
            ]]),
            new AssistantStep('offline','4. Offline-Verifikation','Signiertes Release mit Public Trust Store, Audit, Gate-Bericht und Modulpaket als selbstprüfendes Offline-Bundle exportieren oder ein solches Bundle lokal prüfen.',['fields'=>[
                ['name'=>'offline_operation','label'=>'Vorgang','type'=>'select','options'=>['export'=>'Offline-Prüfpaket erzeugen','verify'=>'Offline-Prüfpaket prüfen']],
                ['name'=>'catalog_id','label'=>'Signierter Katalogeintrag','type'=>'select','options'=>$catalog],
                ['name'=>'offline_bundle','label'=>'Offline-Prüfpaket (.zip)','type'=>'file','accept'=>'.zip,application/zip'],
            ]]),
            new AssistantStep('recovery','5. Lost-Key-Recovery','Nur wenn der private Schlüssel wirklich fehlt: neuen unabhängigen Recovery-Root erzeugen. Alte Key-ID wird auf VERIFY_ONLY zurückgestuft; dies ist keine kryptographische Alt→Neu-Kontinuität.',['fields'=>[
                ['name'=>'lost_key_id','label'=>'Verlorener Schlüssel','type'=>'select','options'=>$keys],
                ['name'=>'recovery_reason','label'=>'Recovery-Grund','type'=>'text'],
                ['name'=>'recovery_confirmation','label'=>'Exakt: RECOVER LOST KEY <Key-ID>','type'=>'text'],
            ]]),
            new AssistantStep('result','6. Prüfung und Ergebnis','Aktuellen Trust-/Recovery-Zustand und die Ergebnisse der letzten Operation anzeigen.'),
        ];
    }

    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope=$c->getProjectId()?:'global';if($this->bool($c,'reset'))$this->store->clear($this->getId(),$scope);$s=$this->store->get($this->getId(),$scope);$stepId=$stepId?:'overview';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];$warnings=[];
        if($method==='POST'&&!$this->bool($c,'reset')){$s=$this->mergeSafe($s,$c,$stepId);$this->store->put($this->getId(),$s,$scope);}
        $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='overview';
        $data=['phase'=>27,'formValues'=>$this->formValues($s,$stepId),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            if($method==='POST'&&$stepId==='public'){
                if(($s['publicOperation']??'export')==='export'){$r=$this->service->exportPublicBundle();$s['publicResult']=$this->stripPath($r);$data['publicResult']=$r;$data['publicDownloadUrl']='trust-recovery-download.php?type=public&file='.rawurlencode((string)$r['file']);$data['publicShaDownloadUrl']='trust-recovery-download.php?type=public&file='.rawurlencode((string)$r['file'].'.sha256');}
                else{$upload=$this->upload($c,'public_bundle');$r=$this->service->importPublicBundle($upload,!empty($s['allowRelease']),!empty($s['applyPolicy']),(string)($s['publicConfirmation']??''));$s['publicResult']=$r;$data['publicResult']=$r;}
                $this->store->put($this->getId(),$s,$scope);
            }
            if($method==='POST'&&$stepId==='private'){
                $pass=(string)$c->input('passphrase','');$confirm=(string)$c->input('private_confirmation','');
                if(($s['privateOperation']??'backup')==='backup'){$r=$this->service->createPrivateBackup($pass,$confirm);$s['privateResult']=$this->stripPath($r);$data['privateResult']=$r;$data['privateDownloadUrl']='trust-recovery-download.php?type=private&file='.rawurlencode((string)$r['file']);$data['privateShaDownloadUrl']='trust-recovery-download.php?type=private&file='.rawurlencode((string)$r['file'].'.sha256');}
                else{$upload=$this->upload($c,'private_backup');$r=$this->service->restorePrivateBackup($upload,$pass,$confirm,(string)($s['privateReason']??''));$s['privateResult']=$r;$data['privateResult']=$r;}
                $this->store->put($this->getId(),$s,$scope);
            }
            if($method==='POST'&&$stepId==='offline'){
                if(($s['offlineOperation']??'export')==='export'){$id=(string)($s['catalogId']??'');if($id==='')throw new \RuntimeException('Katalogeintrag fehlt.');$r=$this->service->createOfflineBundle($id);$s['offlineResult']=$this->stripPath($r);$data['offlineResult']=$r;$data['offlineDownloadUrl']='trust-recovery-download.php?type=offline&file='.rawurlencode((string)$r['file']);$data['offlineShaDownloadUrl']='trust-recovery-download.php?type=offline&file='.rawurlencode((string)$r['file'].'.sha256');}
                else{$upload=$this->upload($c,'offline_bundle');$r=$this->service->verifyOfflineBundle($upload);$s['offlineResult']=$r;$data['offlineResult']=$r;if(empty($r['ok']))$errors=array_merge($errors,(array)($r['errors']??['Offline-Verifikation fehlgeschlagen.']));}
                $this->store->put($this->getId(),$s,$scope);
            }
            if($method==='POST'&&$stepId==='recovery'){$id=(string)($s['lostKeyId']??'');$r=$this->service->recoverLostSigningKey($id,(string)($s['recoveryReason']??''),(string)($s['recoveryConfirmation']??''));$s['recoveryResult']=$r;$data['recoveryResult']=$r;$warnings[]=(string)($r['warning']??'Lost-Key-Recovery erzeugt eine administrative, nicht kryptographische Kontinuität.');$this->store->put($this->getId(),$s,$scope);}
            foreach(['publicResult','privateResult','offlineResult','recoveryResult'] as $k)if(!isset($data[$k])&&!empty($s[$k]))$data[$k]=$s[$k];$data['recoveryStatus']=$this->service->status();$data['configurationReady']=!empty($data['recoveryStatus']['auditVerification']['ok'])&&!empty($data['recoveryStatus']['chainVerification']['ok']);
        }catch(\Throwable $e){$errors[]=$e->getMessage();$data['recoveryStatus']=$this->service->status();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data,array_values(array_unique($warnings)));
    }

    private function mergeSafe(array $s,AssistantContext $c,string $step):array
    {
        if($step==='public'){$s['publicOperation']=(string)$c->input('public_operation','export');$s['allowRelease']=$this->bool($c,'allow_release');$s['applyPolicy']=$this->bool($c,'apply_policy');$s['publicConfirmation']=trim((string)$c->input('public_confirmation',''));}
        elseif($step==='private'){$s['privateOperation']=(string)$c->input('private_operation','backup');$s['privateReason']=trim((string)$c->input('private_reason',''));/* passphrase + confirmation intentionally NOT persisted */}
        elseif($step==='offline'){$s['offlineOperation']=(string)$c->input('offline_operation','export');$s['catalogId']=trim((string)$c->input('catalog_id',''));}
        elseif($step==='recovery'){$s['lostKeyId']=trim((string)$c->input('lost_key_id',''));$s['recoveryReason']=trim((string)$c->input('recovery_reason',''));$s['recoveryConfirmation']=trim((string)$c->input('recovery_confirmation',''));}
        return $s;
    }
    private function formValues(array $s,string $step):array{return match($step){'public'=>['public_operation'=>$s['publicOperation']??'export','allow_release'=>!empty($s['allowRelease']),'apply_policy'=>!empty($s['applyPolicy']),'public_confirmation'=>$s['publicConfirmation']??''],'private'=>['private_operation'=>$s['privateOperation']??'backup','passphrase'=>'','private_reason'=>$s['privateReason']??'','private_confirmation'=>''],'offline'=>['offline_operation'=>$s['offlineOperation']??'export','catalog_id'=>$s['catalogId']??''],'recovery'=>['lost_key_id'=>$s['lostKeyId']??'','recovery_reason'=>$s['recoveryReason']??'','recovery_confirmation'=>$s['recoveryConfirmation']??''],default=>[]};}
    private function upload(AssistantContext $c,string $name):string{$files=(array)$c->meta('files',[]);$u=is_array($files[$name]??null)?$files[$name]:[];$err=(int)($u['error']??UPLOAD_ERR_NO_FILE);if($err!==UPLOAD_ERR_OK)throw new \RuntimeException('Upload fehlt oder ist fehlgeschlagen: '.$name);$tmp=(string)($u['tmp_name']??'');if($tmp===''||!is_file($tmp))throw new \RuntimeException('Upload-Datei ist nicht verfügbar: '.$name);return $tmp;}
    private function stripPath(array $r):array{unset($r['path']);return $r;}
    private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
