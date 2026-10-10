<?php
declare(strict_types=1);
namespace EasyIT\Assistant\Assistants;
use EasyIT\Assistant\AssistantContext;use EasyIT\Assistant\AssistantInterface;use EasyIT\Assistant\AssistantResult;use EasyIT\Assistant\AssistantStep;use EasyIT\Assistant\Module\TrustMembershipService;use EasyIT\Assistant\State\AssistantStateStore;
final class TrustMembershipAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store,private TrustMembershipService $service){}
    public function getId():string{return 'dataform.trust-membership';}
    public function getTitle():string{return 'Cluster-Mitgliedschaft und Quorum-Reconfiguration';}
    public function getDescription():string{return 'Nimmt Instanzen sicher auf/entfernt sie, verwaltet Voting/Non-Voting und schützt Mitgliedschaftsänderungen per Joint Consensus.';}
    public function getSteps(AssistantContext $c):array{return[
        new AssistantStep('overview','1. Mitgliedschaftsstatus','Aktuelle Voting-Menge, Quorum, Membership-Epoche und aktiven Joint-Consensus prüfen.'),
        new AssistantStep('propose','2. Zielmitgliedschaft vorschlagen','PRIMARY definiert neue Identitäten, Entfernungen und Voting-Änderungen.',['fields'=>[
            ['name'=>'new_identities_json','label'=>'Neue Identity-Bundles als JSON-Array','type'=>'textarea','rows'=>10,'placeholder'=>'[{"payload":...,"selfSignature":"..."}]'],
            ['name'=>'remove_instance_ids','label'=>'Zu entfernende Instanz-IDs (kommagetrennt)','type'=>'text'],
            ['name'=>'voting_overrides_json','label'=>'Voting-Overrides als JSON-Objekt','type'=>'textarea','rows'=>6,'placeholder'=>'{"instance-a":true,"instance-b":false}'],
            ['name'=>'proposal_confirmation','label'=>'Exakt: PROPOSE MEMBERSHIP <cluster-id> EPOCH <n>','type'=>'text'],
        ]]),
        new AssistantStep('prepare-ack','3. PREPARE bestätigen','Altes oder neues Voting-Mitglied signiert den Membership-Vorschlag.',['fields'=>[['name'=>'proposal_file','label'=>'Membership-Proposal (.json)','type'=>'file','accept'=>'.json,application/json']]]),
        new AssistantStep('joint','4. Joint Consensus aktivieren','PRIMARY sammelt PREPARE-ACKs. Altes UND neues Quorum müssen erreicht werden.',['fields'=>[
            ['name'=>'joint_proposal_file','label'=>'Membership-Proposal (.json)','type'=>'file','accept'=>'.json,application/json'],
            ['name'=>'prepare_acks_json','label'=>'PREPARE-ACKs als JSON-Array','type'=>'textarea','rows'=>10],
            ['name'=>'joint_confirmation','label'=>'Exakt: ACTIVATE JOINT <transition-id>','type'=>'text'],
        ]]),
        new AssistantStep('joint-import','5. Joint-Stand verteilen','Peers importieren den Joint-Consensus und werden dadurch ebenfalls für Leadership-/Release-Aktionen gefenced.',['fields'=>[['name'=>'joint_file','label'=>'Joint-Consensus (.json)','type'=>'file','accept'=>'.json,application/json']]]),
        new AssistantStep('commit-ack','6. COMMIT bestätigen','Voting-Mitglieder bestätigen nach aktivem Joint-Consensus den finalen Übergang.',['fields'=>[['name'=>'commit_joint_file','label'=>'Joint-Consensus (.json)','type'=>'file','accept'=>'.json,application/json']]]),
        new AssistantStep('finalize','7. Mitgliedschaft finalisieren','PRIMARY benötigt erneut altes UND neues Quorum. Danach wird die neue Clusterkonfiguration aktiv.',['fields'=>[
            ['name'=>'final_joint_file','label'=>'Joint-Consensus (.json)','type'=>'file','accept'=>'.json,application/json'],
            ['name'=>'commit_acks_json','label'=>'COMMIT-ACKs als JSON-Array','type'=>'textarea','rows'=>10],
            ['name'=>'final_confirmation','label'=>'Exakt: FINALIZE MEMBERSHIP <transition-id>','type'=>'text'],
        ]]),
        new AssistantStep('import-final','8. Finale Konfiguration verteilen','Neue/bleibende Mitglieder übernehmen die finale Konfiguration; entfernte Instanzen werden RETIRED/VERIFY_ONLY.',['fields'=>[['name'=>'final_cluster_file','label'=>'Finale Clusterkonfiguration (.json)','type'=>'file','accept'=>'.json,application/json']]]),
        new AssistantStep('result','9. Ergebnis','Mitgliedschaft, Voting-Menge, Quorum und Audit zusammenfassen.'),
    ];}
    public function run(AssistantContext $c,?string $stepId=null):AssistantResult
    {
        $scope='global';$s=$this->store->get($this->getId(),$scope);$stepId=$stepId?:'overview';$method=strtoupper((string)$c->meta('requestMethod','GET'));$errors=[];$warnings=[];
        if($method==='POST'){$s=$this->merge($s,$c,$stepId);$this->store->put($this->getId(),$s,$scope);} $steps=$this->getSteps($c);$ids=array_map(fn($x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='overview';$data=['phase'=>30,'formValues'=>$this->form($s,$stepId),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            if($method==='POST'&&$stepId==='propose'){$r=$this->service->createProposal($this->jsonList((string)($s['newIdentitiesJson']??''),'Neue Identity-Bundles'),$this->csv((string)($s['removeInstanceIds']??'')),$this->boolMap((string)($s['votingOverridesJson']??'')),(string)($s['proposalConfirmation']??''));$this->result($data,$s,'proposal',$r);}
            if($method==='POST'&&$stepId==='prepare-ack'){$r=$this->service->acknowledgePrepare($this->service->readArtifact($this->upload($c,'proposal_file')));$this->result($data,$s,'prepareAck',$r);}
            if($method==='POST'&&$stepId==='joint'){$proposal=$this->service->readArtifact($this->upload($c,'joint_proposal_file'));$acks=$this->jsonList((string)($s['prepareAcksJson']??''),'PREPARE-ACKs');$r=$this->service->activateJoint($proposal,$acks,(string)($s['jointConfirmation']??''));$this->result($data,$s,'joint',$r);}
            if($method==='POST'&&$stepId==='joint-import'){$r=$this->service->importJoint($this->service->readArtifact($this->upload($c,'joint_file')));$data['jointImportResult']=$r;}
            if($method==='POST'&&$stepId==='commit-ack'){$r=$this->service->acknowledgeCommit($this->service->readArtifact($this->upload($c,'commit_joint_file')));$this->result($data,$s,'commitAck',$r);}
            if($method==='POST'&&$stepId==='finalize'){$joint=$this->service->readArtifact($this->upload($c,'final_joint_file'));$acks=$this->jsonList((string)($s['commitAcksJson']??''),'COMMIT-ACKs');$r=$this->service->finalize($joint,$acks,(string)($s['finalConfirmation']??''));$this->result($data,$s,'final',$r);}
            if($method==='POST'&&$stepId==='import-final'){$r=$this->service->importFinalCluster($this->service->readArtifact($this->upload($c,'final_cluster_file')));$data['finalImportResult']=$r;}
            foreach(['proposal','prepareAck','joint','commitAck','final'] as $k)if(!isset($data[$k.'Result'])&&!empty($s[$k.'Result']))$data[$k.'Result']=$s[$k.'Result'];
            $data['membershipStatus']=$this->service->status();$data['configurationReady']=!empty($data['membershipStatus']['configured'])&&!empty($data['membershipStatus']['audit']['ok']);
        }catch(\Throwable $e){$errors[]=$e->getMessage();$data['membershipStatus']=$this->service->status();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));return $errors?AssistantResult::failure($this->getId(),$errors,$stepId,$stateful,$data):AssistantResult::success($this->getId(),$stepId,$stateful,$data,$warnings);
    }
    private function result(array &$data,array &$s,string $key,array $r):void{$data[$key.'Result']=$r;$copy=$r;unset($copy['path']);$s[$key.'Result']=$copy;if(!empty($r['file']))$data[$key.'DownloadUrl']='trust-membership-download.php?file='.rawurlencode((string)$r['file']);$this->store->put($this->getId(),$s,'global');}
    private function merge(array $s,AssistantContext $c,string $step):array{if($step==='propose'){$s['newIdentitiesJson']=(string)$c->input('new_identities_json','');$s['removeInstanceIds']=(string)$c->input('remove_instance_ids','');$s['votingOverridesJson']=(string)$c->input('voting_overrides_json','');$s['proposalConfirmation']=trim((string)$c->input('proposal_confirmation',''));}elseif($step==='joint'){$s['prepareAcksJson']=(string)$c->input('prepare_acks_json','');$s['jointConfirmation']=trim((string)$c->input('joint_confirmation',''));}elseif($step==='finalize'){$s['commitAcksJson']=(string)$c->input('commit_acks_json','');$s['finalConfirmation']=trim((string)$c->input('final_confirmation',''));}return $s;}
    private function form(array $s,string $step):array{return match($step){'propose'=>['new_identities_json'=>$s['newIdentitiesJson']??'','remove_instance_ids'=>$s['removeInstanceIds']??'','voting_overrides_json'=>$s['votingOverridesJson']??'','proposal_confirmation'=>$s['proposalConfirmation']??''],'joint'=>['prepare_acks_json'=>$s['prepareAcksJson']??'','joint_confirmation'=>$s['jointConfirmation']??''],'finalize'=>['commit_acks_json'=>$s['commitAcksJson']??'','final_confirmation'=>$s['finalConfirmation']??''],default=>[]};}
    /** @return list<array<string,mixed>> */private function jsonList(string $raw,string $name):array{$raw=trim($raw);if($raw==='')return[];$d=json_decode($raw,true);if(!is_array($d)||!array_is_list($d))throw new \RuntimeException($name.' muss ein JSON-Array sein.');return array_values(array_filter($d,'is_array'));}
    /** @return array<string,bool> */private function boolMap(string $raw):array{$raw=trim($raw);if($raw==='')return[];$d=json_decode($raw,true);if(!is_array($d)||array_is_list($d))throw new \RuntimeException('Voting-Overrides müssen ein JSON-Objekt sein.');$r=[];foreach($d as $k=>$v)$r[(string)$k]=in_array($v,[true,1,'1','true','yes','on'],true);return $r;}
    /** @return list<string> */private function csv(string $v):array{return array_values(array_filter(array_map('trim',explode(',',$v)),fn($x)=>$x!==''));}
    private function upload(AssistantContext $c,string $name):string{$files=(array)$c->meta('files',[]);$u=is_array($files[$name]??null)?$files[$name]:[];if((int)($u['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_file((string)($u['tmp_name']??'')))throw new \RuntimeException('Upload fehlt oder ist fehlgeschlagen: '.$name);return (string)$u['tmp_name'];}
    private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
