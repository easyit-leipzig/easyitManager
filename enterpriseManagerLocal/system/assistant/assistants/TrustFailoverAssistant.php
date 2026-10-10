<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Module\TrustFailoverService;
use EasyIT\Assistant\State\AssistantStateStore;

final class TrustFailoverAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store, private TrustFailoverService $service) {}
    public function getId(): string { return 'dataform.trust-failover'; }
    public function getTitle(): string { return 'PRIMARY-Failover und Quorum-Schutz'; }
    public function getDescription(): string { return 'Verwaltet signierte Heartbeats, quorumgesicherte PRIMARY-Leases, Election-Epochen und Split-Brain-geschützte SECONDARY-Promotion.'; }

    public function getSteps(AssistantContext $c): array
    {
        return [
            new AssistantStep('overview','1. Failover-Status','Lease, Heartbeat, Quorum, Election-Bereitschaft und Failover-Audit prüfen.'),
            new AssistantStep('identity','2. Instanz-Identity','Separate Ed25519-Identity für Heartbeats, Lease-ACKs und Wahlstimmen erzeugen/exportieren.',['fields'=>[
                ['name'=>'identity_export','label'=>'Identity erzeugen / öffentliches Identity-Bundle exportieren','type'=>'checkbox'],
            ]]),
            new AssistantStep('cluster','3. Quorum-Cluster','PRIMARY erzeugt eine signierte Cluster-Mitgliedschaft; SECONDARY importiert dieselbe Konfiguration.',['fields'=>[
                ['name'=>'cluster_operation','label'=>'Vorgang','type'=>'select','options'=>['create'=>'Cluster auf PRIMARY erzeugen','import'=>'Signierte Clusterkonfiguration importieren']],
                ['name'=>'cluster_id','label'=>'Cluster-ID','type'=>'text'],
                ['name'=>'member_identities_json','label'=>'Peer-Identity-Bundles als JSON-Array','type'=>'textarea','rows'=>10,'placeholder'=>'[{"payload":...,"selfSignature":"..."}]'],
                ['name'=>'cluster_bundle','label'=>'Clusterkonfiguration (.json) für Import','type'=>'file','accept'=>'.json,application/json'],
                ['name'=>'lease_seconds','label'=>'Lease-Dauer in Sekunden','type'=>'number','min'=>15,'max'=>3600],
                ['name'=>'heartbeat_timeout_seconds','label'=>'Heartbeat-Warnschwelle in Sekunden','type'=>'number','min'=>5,'max'=>3599],
                ['name'=>'lease_grace_seconds','label'=>'Failover-Sicherheitsfrist nach Lease-Ablauf','type'=>'number','min'=>0,'max'=>300],
                ['name'=>'cluster_confirmation','label'=>'Exakt: CONFIGURE FAILOVER <cluster-id>','type'=>'text'],
            ]]),
            new AssistantStep('lease-proposal','4. PRIMARY-Lease vorschlagen','PRIMARY erzeugt einen signierten Lease-Vorschlag. Jede Verlängerung benötigt erneut Quorum-ACKs.',['fields'=>[
                ['name'=>'lease_proposal_create','label'=>'Neuen Lease-Vorschlag erzeugen','type'=>'checkbox'],
            ]]),
            new AssistantStep('lease-ack','5. Lease-ACK abstimmen','Voting-Peer prüft einen Lease-Vorschlag und signiert genau diesen Vorschlag.',['fields'=>[
                ['name'=>'lease_proposal','label'=>'Lease-Proposal (.json)','type'=>'file','accept'=>'.json,application/json'],
            ]]),
            new AssistantStep('lease-activate','6. Lease mit Quorum aktivieren','PRIMARY sammelt eindeutige ACKs. Der eigene ACK wird bei Bedarf automatisch ergänzt.',['fields'=>[
                ['name'=>'activation_proposal','label'=>'Lease-Proposal (.json)','type'=>'file','accept'=>'.json,application/json'],
                ['name'=>'lease_acks_json','label'=>'Peer-ACKs als JSON-Array','type'=>'textarea','rows'=>10],
                ['name'=>'lease_confirmation','label'=>'Exakt: ACTIVATE LEASE <primary-id> EPOCH <n>','type'=>'text'],
            ]]),
            new AssistantStep('heartbeat','7. Heartbeat','PRIMARY erzeugt Heartbeats; Peers beobachten sie. Ein stale Heartbeat macht PRIMARY verdächtig, erlaubt aber vor Lease-Ablauf noch keine Promotion.',['fields'=>[
                ['name'=>'heartbeat_operation','label'=>'Vorgang','type'=>'select','options'=>['create'=>'Heartbeat auf PRIMARY erzeugen','observe'=>'Heartbeat beobachten']],
                ['name'=>'heartbeat_bundle','label'=>'Heartbeat (.json) für Observe','type'=>'file','accept'=>'.json,application/json'],
            ]]),
            new AssistantStep('election','8. Failover-Wahl','Nach sicherem Lease-Ablauf startet ein SECONDARY eine neue Epoche; jedes Voting-Mitglied darf pro Epoche nur einen Kandidaten wählen.',['fields'=>[
                ['name'=>'election_operation','label'=>'Vorgang','type'=>'select','options'=>['request'=>'Election-Request als Kandidat erzeugen','vote'=>'Election-Request prüfen und Stimme abgeben']],
                ['name'=>'election_reason','label'=>'Failover-Grund','type'=>'text'],
                ['name'=>'election_request','label'=>'Election-Request (.json) für Vote','type'=>'file','accept'=>'.json,application/json'],
            ]]),
            new AssistantStep('promote','9. SECONDARY mit Quorum promoten','Kandidat benötigt Mehrheit, abgelaufenes Alt-Lease und einen wiederhergestellten Release-Signing-Key. Eigene Stimme wird automatisch ergänzt.',['fields'=>[
                ['name'=>'promotion_request','label'=>'Election-Request (.json)','type'=>'file','accept'=>'.json,application/json'],
                ['name'=>'election_votes_json','label'=>'Peer-Stimmen als JSON-Array','type'=>'textarea','rows'=>10],
                ['name'=>'promotion_confirmation','label'=>'Exakt: PROMOTE <id> WITH QUORUM EPOCH <n>','type'=>'text'],
            ]]),
            new AssistantStep('result','10. Ergebnis','Failover-, Quorum-, Lease- und Audit-Zustand zusammenfassen.'),
        ];
    }

    public function run(AssistantContext $c, ?string $stepId=null): AssistantResult
    {
        $scope='global'; if($this->bool($c,'reset'))$this->store->clear($this->getId(),$scope); $s=$this->store->get($this->getId(),$scope); $stepId=$stepId?:'overview'; $method=strtoupper((string)$c->meta('requestMethod','GET')); $errors=[];$warnings=[];
        if($method==='POST'&&!$this->bool($c,'reset')){$s=$this->mergeSafe($s,$c,$stepId);$this->store->put($this->getId(),$s,$scope);} $steps=$this->getSteps($c);$ids=array_map(static fn(AssistantStep $x)=>$x->getId(),$steps);if(!in_array($stepId,$ids,true))$stepId='overview';
        $data=['phase'=>29,'formValues'=>$this->formValues($s,$stepId),'nextStep'=>$this->next($ids,$stepId),'previousStep'=>$this->prev($ids,$stepId)];
        try{
            if($method==='POST'&&$stepId==='identity'&&!empty($s['identityExport'])){$r=$this->service->exportIdentityBundle();$data['identityResult']=$r;$s['identityResult']=$this->stripPath($r);$this->download($data,$r,'identity');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='cluster'){
                if(($s['clusterOperation']??'create')==='create'){$members=$this->jsonArray((string)($s['memberIdentitiesJson']??''),'Peer-Identity-Bundles');$r=$this->service->createCluster((string)($s['clusterId']??''),$members,(int)($s['leaseSeconds']??90),(int)($s['heartbeatTimeoutSeconds']??30),(int)($s['leaseGraceSeconds']??5),(string)($s['clusterConfirmation']??''));$this->download($data,$r,'cluster');}
                else{$r=$this->service->importCluster($this->service->readArtifact($this->upload($c,'cluster_bundle')));} $data['clusterResult']=$r;$s['clusterResult']=$this->stripPath($r);$this->store->put($this->getId(),$s,$scope);
            }
            if($method==='POST'&&$stepId==='lease-proposal'&&!empty($s['leaseProposalCreate'])){$r=$this->service->createLeaseProposal();$data['leaseProposalResult']=$r;$s['leaseProposalResult']=$this->stripPath($r);$this->download($data,$r,'leaseProposal');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='lease-ack'){$proposal=$this->service->readArtifact($this->upload($c,'lease_proposal'));$r=$this->service->acknowledgeLease($proposal);$data['leaseAckResult']=$r;$s['leaseAckResult']=$this->stripPath($r);$this->download($data,$r,'leaseAck');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='lease-activate'){$proposal=$this->service->readArtifact($this->upload($c,'activation_proposal'));$acks=$this->jsonArray((string)($s['leaseAcksJson']??''),'Lease-ACKs');$self=$this->service->acknowledgeLease($proposal);$acks[]=(array)$self['data'];$r=$this->service->activateLease($proposal,$acks,(string)($s['leaseConfirmation']??''));$data['leaseActivationResult']=$r;$s['leaseActivationResult']=$this->stripPath($r);$this->download($data,$r,'leadershipLease');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='heartbeat'){$op=(string)($s['heartbeatOperation']??'create');$r=$op==='create'?$this->service->createHeartbeat():$this->service->observeHeartbeat($this->service->readArtifact($this->upload($c,'heartbeat_bundle')));$data['heartbeatResult']=$r;$s['heartbeatResult']=$this->stripPath($r);if($op==='create')$this->download($data,$r,'heartbeat');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='election'){$op=(string)($s['electionOperation']??'request');$r=$op==='request'?$this->service->createElectionRequest((string)($s['electionReason']??'')):$this->service->voteElection($this->service->readArtifact($this->upload($c,'election_request')));$data['electionResult']=$r;$s['electionResult']=$this->stripPath($r);$this->download($data,$r,$op==='request'?'electionRequest':'electionVote');$this->store->put($this->getId(),$s,$scope);}
            if($method==='POST'&&$stepId==='promote'){$request=$this->service->readArtifact($this->upload($c,'promotion_request'));$votes=$this->jsonArray((string)($s['electionVotesJson']??''),'Election-Stimmen');$self=$this->service->voteElection($request);$votes[]=(array)$self['data'];$r=$this->service->promoteWithQuorum($request,$votes,(string)($s['promotionConfirmation']??''));$data['promotionResult']=$r;$s['promotionResult']=$r;$this->store->put($this->getId(),$s,$scope);}
            foreach(['identityResult','clusterResult','leaseProposalResult','leaseAckResult','leaseActivationResult','heartbeatResult','electionResult','promotionResult'] as $k)if(!isset($data[$k])&&!empty($s[$k]))$data[$k]=$s[$k];
            $data['failoverStatus']=$this->service->status();$data['configurationReady']=!empty($data['failoverStatus']['cluster']['enabled'])&&!empty($data['failoverStatus']['audit']['ok']);
        }catch(\Throwable $e){$errors[]=$e->getMessage();$data['failoverStatus']=$this->service->status();}
        $idx=array_search($stepId,$ids,true);$stateful=[];foreach($steps as $i=>$x)$stateful[]=$x->withState($i<$idx?AssistantStep::STATE_COMPLETE:($i===$idx?AssistantStep::STATE_CURRENT:AssistantStep::STATE_PENDING));if($errors!==[])return AssistantResult::failure($this->getId(),array_values(array_unique($errors)),$stepId,$stateful,$data);return AssistantResult::success($this->getId(),$stepId,$stateful,$data,array_values(array_unique($warnings)));
    }

    private function mergeSafe(array $s,AssistantContext $c,string $step):array
    {
        if($step==='identity')$s['identityExport']=$this->bool($c,'identity_export');
        elseif($step==='cluster'){$s['clusterOperation']=(string)$c->input('cluster_operation','create');$s['clusterId']=trim((string)$c->input('cluster_id',''));$s['memberIdentitiesJson']=(string)$c->input('member_identities_json','');$s['leaseSeconds']=(int)$c->input('lease_seconds',90);$s['heartbeatTimeoutSeconds']=(int)$c->input('heartbeat_timeout_seconds',30);$s['leaseGraceSeconds']=(int)$c->input('lease_grace_seconds',5);$s['clusterConfirmation']=trim((string)$c->input('cluster_confirmation',''));}
        elseif($step==='lease-proposal')$s['leaseProposalCreate']=$this->bool($c,'lease_proposal_create');
        elseif($step==='lease-activate'){$s['leaseAcksJson']=(string)$c->input('lease_acks_json','');$s['leaseConfirmation']=trim((string)$c->input('lease_confirmation',''));}
        elseif($step==='heartbeat')$s['heartbeatOperation']=(string)$c->input('heartbeat_operation','create');
        elseif($step==='election'){$s['electionOperation']=(string)$c->input('election_operation','request');$s['electionReason']=trim((string)$c->input('election_reason',''));}
        elseif($step==='promote'){$s['electionVotesJson']=(string)$c->input('election_votes_json','');$s['promotionConfirmation']=trim((string)$c->input('promotion_confirmation',''));}
        return $s;
    }
    private function formValues(array $s,string $step):array{return match($step){'identity'=>['identity_export'=>!empty($s['identityExport'])],'cluster'=>['cluster_operation'=>$s['clusterOperation']??'create','cluster_id'=>$s['clusterId']??'','member_identities_json'=>$s['memberIdentitiesJson']??'','lease_seconds'=>$s['leaseSeconds']??90,'heartbeat_timeout_seconds'=>$s['heartbeatTimeoutSeconds']??30,'lease_grace_seconds'=>$s['leaseGraceSeconds']??5,'cluster_confirmation'=>$s['clusterConfirmation']??''],'lease-proposal'=>['lease_proposal_create'=>!empty($s['leaseProposalCreate'])],'lease-activate'=>['lease_acks_json'=>$s['leaseAcksJson']??'','lease_confirmation'=>$s['leaseConfirmation']??''],'heartbeat'=>['heartbeat_operation'=>$s['heartbeatOperation']??'create'],'election'=>['election_operation'=>$s['electionOperation']??'request','election_reason'=>$s['electionReason']??''],'promote'=>['election_votes_json'=>$s['electionVotesJson']??'','promotion_confirmation'=>$s['promotionConfirmation']??''],default=>[]};}
    /** @return list<array<string,mixed>> */ private function jsonArray(string $raw,string $what):array{$raw=trim($raw);if($raw==='')return[];$d=json_decode($raw,true);if(!is_array($d)||!array_is_list($d))throw new \RuntimeException($what.' muss ein JSON-Array sein.');return array_values(array_filter($d,'is_array'));}
    private function upload(AssistantContext $c,string $name):string{$files=(array)$c->meta('files',[]);$u=is_array($files[$name]??null)?$files[$name]:[];$err=(int)($u['error']??UPLOAD_ERR_NO_FILE);if($err!==UPLOAD_ERR_OK)throw new \RuntimeException('Upload fehlt oder ist fehlgeschlagen: '.$name);$tmp=(string)($u['tmp_name']??'');if($tmp===''||!is_file($tmp))throw new \RuntimeException('Upload-Datei ist nicht verfügbar: '.$name);return $tmp;}
    private function download(array &$data,array $r,string $prefix):void{if(!empty($r['file']))$data[$prefix.'DownloadUrl']='trust-failover-download.php?file='.rawurlencode((string)$r['file']);}
    private function stripPath(array $r):array{unset($r['path']);return $r;}private function bool(AssistantContext $c,string $k):bool{return in_array($c->input($k,false),[true,1,'1','true','yes','on'],true);}private function next(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&isset($ids[$i+1])?$ids[$i+1]:null;}private function prev(array $ids,string $cur):?string{$i=array_search($cur,$ids,true);return $i!==false&&$i>0?$ids[$i-1]:null;}
}
