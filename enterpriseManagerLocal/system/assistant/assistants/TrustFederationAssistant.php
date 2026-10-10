<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\TrustFederationService;
use EasyIT\Assistant\State\AssistantStateStore;

final class TrustFederationAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store, private TrustFederationService $service) {}
    public function getId(): string { return 'dataform.trust-federation'; }
    public function getTitle(): string { return 'Trust-Disaster-Recovery und Mehrinstanz-Vertrauen'; }
    public function getDescription(): string { return 'Verwaltet PRIMARY/SECONDARY/VERIFY_ONLY-Instanzen, autoritative Public-Trust-Synchronisation, Divergenzerkennung und vollständige verschlüsselte Trust-Disaster-Recovery.'; }

    public function getSteps(AssistantContext $c): array
    {
        return [
            new AssistantStep('overview','1. Instanz- und Sync-Status','Lokale Rolle, Public-Trust-Digest, Peers, Trust-Chain und Federation-Audit prüfen.'),
            new AssistantStep('setup','2. Lokale Instanz initialisieren','Einmalige Instanz-ID und Rolle festlegen. PRIMARY darf Releases signieren; SECONDARY/VERIFY_ONLY nicht.',['fields'=>[
                ['name'=>'instance_name','label'=>'Instanzname','type'=>'text'],
                ['name'=>'instance_role','label'=>'Rolle','type'=>'select','options'=>['PRIMARY'=>'PRIMARY','SECONDARY'=>'SECONDARY','VERIFY_ONLY'=>'VERIFY_ONLY']],
                ['name'=>'setup_confirmation','label'=>'Exakt: INIT INSTANCE <ROLLE> <Name>','type'=>'text'],
            ]]),
            new AssistantStep('sync-export','3. PRIMARY-Sync exportieren','Autoritatives, signiertes Public-Trust-Synchronisationspaket erzeugen. Nur PRIMARY.',['fields'=>[
                ['name'=>'sync_export','label'=>'Autoritatives Sync-Paket jetzt erzeugen','type'=>'checkbox'],
            ]]),
            new AssistantStep('sync-import','4. Sync vergleichen / anwenden','PRIMARY-Sync-Paket prüfen. IN_SYNC, FAST_FORWARD, DIVERGED und PRIMARY_CONFLICT werden unterschieden.',['fields'=>[
                ['name'=>'sync_bundle','label'=>'Trust-Sync-Paket (.zip)','type'=>'file','accept'=>'.zip,application/zip'],
                ['name'=>'sync_operation','label'=>'Vorgang','type'=>'select','options'=>['inspect'=>'Nur vergleichen','apply'=>'Vergleichen und anwenden']],
                ['name'=>'sync_confirmation','label'=>'APPLY TRUST SYNC <source> bzw. FORCE TRUST SYNC <source>','type'=>'text'],
            ]]),
            new AssistantStep('disaster-export','5. Disaster-Recovery-Paket','PRIMARY: vollständiges Trust-Recovery-Paket einschließlich passphrasengeschütztem Private-Key-Backup erzeugen.',['fields'=>[
                ['name'=>'disaster_passphrase','label'=>'Backup-Passphrase (mind. 10 Zeichen)','type'=>'password'],
                ['name'=>'disaster_confirmation','label'=>'Exakt: CREATE TRUST DISASTER <Instanz-ID>','type'=>'text'],
            ]]),
            new AssistantStep('disaster-restore','6. Auf neuem Server wiederherstellen','Nur auf leerer Trust-Instanz. Recovery startet absichtlich als SECONDARY, um Dual-Primary zu verhindern.',['fields'=>[
                ['name'=>'disaster_bundle','label'=>'Trust-Disaster-Paket (.zip)','type'=>'file','accept'=>'.zip,application/zip'],
                ['name'=>'restore_passphrase','label'=>'Backup-Passphrase','type'=>'password'],
                ['name'=>'restore_instance_name','label'=>'Name der neuen Instanz','type'=>'text'],
                ['name'=>'restore_confirmation','label'=>'Exakt: RESTORE TRUST DISASTER <source-instance-id>','type'=>'text'],
            ]]),
            new AssistantStep('role','7. Rollenwechsel / Promotion','SECONDARY erst nach Trust-/Audit-Prüfung und vorhandenem privaten Key kontrolliert auf PRIMARY promoten.',['fields'=>[
                ['name'=>'target_role','label'=>'Neue Rolle','type'=>'select','options'=>['PRIMARY'=>'PRIMARY','SECONDARY'=>'SECONDARY','VERIFY_ONLY'=>'VERIFY_ONLY']],
                ['name'=>'role_reason','label'=>'Grund','type'=>'text'],
                ['name'=>'role_confirmation','label'=>'PROMOTE <id> TO PRIMARY oder DEMOTE <id> TO <ROLLE>','type'=>'text'],
            ]]),
            new AssistantStep('result','8. Ergebnis','Instanz-, Peer-, Sync- und Recovery-Ergebnisse anzeigen.'),
        ];
    }

    public function run(AssistantContext $c, ?string $stepId=null): AssistantResult
    {
        $scope='global'; if($this->bool($c,'reset'))$this->store->clear($this->getId(),$scope); $s=$this->store->get($this->getId(),$scope); $stepId=$stepId?:'overview'; $method=strtoupper((string)$c->meta('requestMethod','GET')); $errors=[];$warnings=[];
        if($method==='POST'&&!$this->bool($c,'reset')){$s=$this->mergeSafe($s,$c,$stepId);$this->store->put($this->getId(),$s,$scope);} $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='overview';
        $data=['phase'=>28,'formValues'=>$this->formValues($s,$stepId),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            if($method==='POST'&&$stepId==='setup'){$r=$this->service->initializeLocal((string)($s['instanceName']??''),(string)($s['instanceRole']??'SECONDARY'),(string)($s['setupConfirmation']??''));$s['setupResult']=$r;$data['setupResult']=$r;$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='sync-export'&&!empty($s['syncExport'])){$r=$this->service->exportSyncBundle();$s['syncExportResult']=$this->stripPath($r);$data['syncExportResult']=$r;$data['syncDownloadUrl']='trust-federation-download.php?type=sync&file='.rawurlencode((string)$r['file']);$data['syncShaDownloadUrl']='trust-federation-download.php?type=sync&file='.rawurlencode((string)$r['file'].'.sha256');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='sync-import'){$upload=$this->upload($c,'sync_bundle');$r=($s['syncOperation']??'inspect')==='apply'?$this->service->applySyncBundle($upload,(string)($s['syncConfirmation']??'')):$this->service->inspectSyncBundle($upload);$s['syncImportResult']=$r;$data['syncImportResult']=$r;if(empty($r['ok']))$errors=array_merge($errors,(array)($r['errors']??['Sync-Prüfung fehlgeschlagen.']));$warnings=array_merge($warnings,(array)($r['warnings']??[]));$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='disaster-export'){$pass=(string)$c->input('disaster_passphrase','');$confirm=(string)$c->input('disaster_confirmation','');$r=$this->service->createDisasterBundle($pass,$confirm);$s['disasterExportResult']=$this->stripPath($r);$data['disasterExportResult']=$r;$data['disasterDownloadUrl']='trust-federation-download.php?type=disaster&file='.rawurlencode((string)$r['file']);$data['disasterShaDownloadUrl']='trust-federation-download.php?type=disaster&file='.rawurlencode((string)$r['file'].'.sha256');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='disaster-restore'){$upload=$this->upload($c,'disaster_bundle');$r=$this->service->restoreDisasterBundle($upload,(string)$c->input('restore_passphrase',''),(string)($s['restoreInstanceName']??''),(string)($s['restoreConfirmation']??''));$s['disasterRestoreResult']=$r;$data['disasterRestoreResult']=$r;$warnings[]=(string)($r['warning']??'Recovery-Instanz startet als SECONDARY.');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='role'){$r=$this->service->changeRole((string)($s['targetRole']??'SECONDARY'),(string)($s['roleReason']??''),(string)($s['roleConfirmation']??''));$s['roleResult']=$r;$data['roleResult']=$r;$this->store->put($this->getId(),$s,$scope);}
            foreach(['setupResult','syncExportResult','syncImportResult','disasterExportResult','disasterRestoreResult','roleResult'] as $k)if(!isset($data[$k])&&!empty($s[$k]))$data[$k]=$s[$k];
            $data['federationStatus']=$this->service->status();$data['configurationReady']=!empty($data['federationStatus']['initialized'])&&!empty($data['federationStatus']['trustChain']['ok'])&&!empty($data['federationStatus']['trustAudit']['ok'])&&!empty($data['federationStatus']['federationAudit']['ok']);
        }catch(\Throwable $e){$errors[]=$e->getMessage();$data['federationStatus']=$this->service->status();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING)); if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data); return AssistantResult::success($this->getId(),$stepId,$stateful,$data,array_values(array_unique($warnings)));
    }

    private function mergeSafe(array $s,AssistantContext $c,string $step):array
    {
        if($step==='setup'){$s['instanceName']=trim((string)$c->input('instance_name',''));$s['instanceRole']=strtoupper(trim((string)$c->input('instance_role','SECONDARY')));$s['setupConfirmation']=trim((string)$c->input('setup_confirmation',''));}
        elseif($step==='sync-export'){$s['syncExport']=$this->bool($c,'sync_export');}
        elseif($step==='sync-import'){$s['syncOperation']=(string)$c->input('sync_operation','inspect');$s['syncConfirmation']=trim((string)$c->input('sync_confirmation',''));}
        elseif($step==='disaster-export'){/* passphrase + confirmation intentionally NOT persisted */}
        elseif($step==='disaster-restore'){$s['restoreInstanceName']=trim((string)$c->input('restore_instance_name',''));$s['restoreConfirmation']=trim((string)$c->input('restore_confirmation',''));/* passphrase intentionally NOT persisted */}
        elseif($step==='role'){$s['targetRole']=strtoupper(trim((string)$c->input('target_role','SECONDARY')));$s['roleReason']=trim((string)$c->input('role_reason',''));$s['roleConfirmation']=trim((string)$c->input('role_confirmation',''));}
        return $s;
    }
    private function formValues(array $s,string $step):array{return match($step){'setup'=>['instance_name'=>$s['instanceName']??'','instance_role'=>$s['instanceRole']??'SECONDARY','setup_confirmation'=>$s['setupConfirmation']??''],'sync-export'=>['sync_export'=>!empty($s['syncExport'])],'sync-import'=>['sync_operation'=>$s['syncOperation']??'inspect','sync_confirmation'=>$s['syncConfirmation']??''],'disaster-export'=>['disaster_passphrase'=>'','disaster_confirmation'=>''],'disaster-restore'=>['restore_passphrase'=>'','restore_instance_name'=>$s['restoreInstanceName']??'','restore_confirmation'=>$s['restoreConfirmation']??''],'role'=>['target_role'=>$s['targetRole']??'SECONDARY','role_reason'=>$s['roleReason']??'','role_confirmation'=>$s['roleConfirmation']??''],default=>[]};}
    private function upload(AssistantContext $c,string $name):string{$files=(array)$c->meta('files',[]);$u=is_array($files[$name]??null)?$files[$name]:[];$err=(int)($u['error']??UPLOAD_ERR_NO_FILE);if($err!==UPLOAD_ERR_OK)throw new \RuntimeException('Upload fehlt oder ist fehlgeschlagen: '.$name);$tmp=(string)($u['tmp_name']??'');if($tmp===''||!is_file($tmp))throw new \RuntimeException('Upload-Datei ist nicht verfügbar: '.$name);return $tmp;}
    private function stripPath(array $r):array{unset($r['path']);return $r;} private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);} private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;} private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
