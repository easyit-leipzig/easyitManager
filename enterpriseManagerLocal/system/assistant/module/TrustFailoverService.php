<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

/**
 * Phase 29: quorum-backed leader leases and safe PRIMARY failover.
 *
 * Transport is deliberately file/API friendly: lease proposals, ACKs,
 * heartbeats, election requests and votes are signed JSON documents. This
 * keeps the protocol usable across separated servers without requiring a
 * hidden network channel inside the assistant layer.
 */
final class TrustFailoverService
{
    private string $root;
    private string $instancesDir;
    private string $localPath;
    private string $base;
    private string $privateDir;
    private string $identityPath;
    private string $clusterPath;
    private string $leasePath;
    private string $observedLeasePath;
    private string $heartbeatPath;
    private string $voteStatePath;
    private string $auditPath;
    private string $outbox;

    public function __construct(
        private ReleaseTrustStore $trust,
        private TrustFederationService $federation,
        string $root
    ) {
        $this->root = rtrim($root, '/\\');
        $this->instancesDir = $this->root . '/storage/assistant/trust/instances';
        $this->localPath = $this->instancesDir . '/local.json';
        $this->base = $this->instancesDir . '/failover';
        $this->privateDir = $this->base . '/private';
        $this->identityPath = $this->base . '/identity.json';
        $this->clusterPath = $this->base . '/cluster.json';
        $this->leasePath = $this->base . '/leadership-lease.json';
        $this->observedLeasePath = $this->base . '/observed-leadership-lease.json';
        $this->heartbeatPath = $this->base . '/last-heartbeat.json';
        $this->voteStatePath = $this->base . '/votes-cast.json';
        $this->auditPath = $this->base . '/failover-audit.jsonl';
        $this->outbox = $this->base . '/outbox';
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $local = $this->local();
        $cluster = $this->cluster();
        $lease = $this->effectiveLease();
        $now = time();
        $expires = $this->ts($lease['expiresAt'] ?? null);
        $heartbeat = $this->readJson($this->heartbeatPath, []);
        $lastBeat = $this->ts($heartbeat['emittedAt'] ?? null);
        $heartbeatTimeout = (int)($cluster['heartbeatTimeoutSeconds'] ?? 30);
        $grace = (int)($cluster['leaseGraceSeconds'] ?? 5);
        $leaseState = $lease === [] ? 'NO_LEASE' : (($expires !== null && $now < $expires) ? 'ACTIVE' : 'EXPIRED');
        $heartbeatState = $lastBeat === null ? 'UNKNOWN' : (($now - $lastBeat) <= $heartbeatTimeout ? 'HEALTHY' : 'STALE');
        $primaryState = $leaseState === 'ACTIVE' && $heartbeatState === 'STALE' ? 'SUSPECTED' : ($leaseState === 'EXPIRED' ? 'FAILOVER_ELIGIBLE' : $leaseState);
        return [
            'schema'=>'easyit.assistant.trust-failover-status.v1',
            'initialized'=>$local!==[],
            'localInstance'=>$local,
            'identity'=>$this->identityPublic(),
            'cluster'=>$cluster,
            'quorum'=>(int)($cluster['quorum']??0),
            'lease'=>$lease,
            'leaseState'=>$leaseState,
            'heartbeat'=>$heartbeat,
            'heartbeatState'=>$heartbeatState,
            'primaryState'=>$primaryState,
            'failoverEligible'=>$expires !== null && $now >= ($expires + $grace),
            'failoverEligibleAt'=>$expires !== null ? gmdate('c', $expires + $grace) : null,
            'voteState'=>$this->readJson($this->voteStatePath,['schema'=>'easyit.assistant.trust-failover-votes.v1','epochs'=>[]]),
            'audit'=>$this->verifyAudit(),
            'signingFenceEnabled'=>!empty($cluster['enabled']),
            'checkedAt'=>gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    public function ensureIdentity(): array
    {
        $this->assertSodium();
        $local = $this->requireLocal();
        $current = $this->readJson($this->identityPath, []);
        $private = $this->identityPrivatePath();
        if ($current !== [] && is_file($private)) return $current;
        $pair = sodium_crypto_sign_keypair();
        $pub = sodium_crypto_sign_publickey($pair); $sec = sodium_crypto_sign_secretkey($pair);
        $payload = [
            'schema'=>'easyit.assistant.trust-failover-identity.v1',
            'instanceId'=>(string)$local['instanceId'],
            'instanceName'=>(string)($local['name']??$local['instanceId']),
            'createdAt'=>gmdate('c'),
            'publicKey'=>base64_encode($pub),
        ];
        $sig = sodium_crypto_sign_detached($this->canonical($payload), $sec);
        $identity = ['payload'=>$payload,'selfSignature'=>base64_encode($sig),'algorithm'=>'Ed25519'];
        $this->atomicJson($this->identityPath,$identity,0640);
        $this->ensureDir($this->privateDir);
        $this->atomicRaw($private,base64_encode($sec)."\n",0600);
        $this->audit('IDENTITY_CREATE',(string)$local['instanceId'],['publicKeySha256'=>hash('sha256',$pub)]);
        return $identity;
    }

    /** @return array<string,mixed> */
    public function exportIdentityBundle(): array
    {
        $identity = $this->ensureIdentity();
        return $this->writeArtifact('identity', 'identity.json', $identity);
    }

    /** @param list<array<string,mixed>> $identityBundles @return array<string,mixed> */
    public function createCluster(string $clusterId, array $identityBundles, int $leaseSeconds, int $heartbeatTimeoutSeconds, int $leaseGraceSeconds, string $confirmation): array
    {
        $local = $this->requireLocal();
        if (($local['role']??'') !== 'PRIMARY') throw new \RuntimeException('Clusterkonfiguration darf nur auf PRIMARY autoritativ erzeugt werden.');
        $clusterId = trim($clusterId); if ($clusterId==='') throw new \InvalidArgumentException('Cluster-ID fehlt.');
        if (trim($confirmation)!=='CONFIGURE FAILOVER '.$clusterId) throw new \RuntimeException('Clusterkonfiguration abgebrochen. Bestätigung muss exakt "CONFIGURE FAILOVER '.$clusterId.'" lauten.');
        $leaseSeconds = max(15,min(3600,$leaseSeconds));
        $heartbeatTimeoutSeconds = max(5,min($leaseSeconds-1,$heartbeatTimeoutSeconds));
        $leaseGraceSeconds = max(0,min(300,$leaseGraceSeconds));
        $own=$this->ensureIdentity(); $identityBundles[]=$own;
        $members=[];
        foreach($identityBundles as $bundle){
            $id=$this->verifyIdentityBundle($bundle);$p=(array)$bundle['payload'];
            $members[$id]=['instanceId'=>$id,'name'=>(string)($p['instanceName']??$id),'publicKey'=>(string)$p['publicKey'],'voting'=>true];
        }
        ksort($members,SORT_STRING);
        if(count($members)<3) throw new \RuntimeException('Quorum-Failover benötigt mindestens drei stimmberechtigte Instanzen.');
        $quorum=intdiv(count($members),2)+1;
        $membershipPayload=['schema'=>'easyit.assistant.trust-failover-membership.v1','clusterId'=>$clusterId,'members'=>$members,'quorum'=>$quorum,'leaseSeconds'=>$leaseSeconds,'heartbeatTimeoutSeconds'=>$heartbeatTimeoutSeconds,'leaseGraceSeconds'=>$leaseGraceSeconds];
        $digest=hash('sha256',$this->canonical($membershipPayload));
        $payload=$membershipPayload+['membershipDigest'=>$digest,'createdByInstanceId'=>(string)$local['instanceId'],'createdAt'=>gmdate('c')];
        $signature=$this->trust->sign($this->canonical($payload));
        $cluster=['schema'=>'easyit.assistant.trust-failover-cluster.v1','enabled'=>true,'payload'=>$payload,'signature'=>$signature,'clusterId'=>$clusterId,'members'=>$members,'quorum'=>$quorum,'leaseSeconds'=>$leaseSeconds,'heartbeatTimeoutSeconds'=>$heartbeatTimeoutSeconds,'leaseGraceSeconds'=>$leaseGraceSeconds,'membershipDigest'=>$digest,'configuredAt'=>gmdate('c')];
        $this->atomicJson($this->clusterPath,$cluster,0640);
        $this->audit('CLUSTER_CONFIG',(string)$local['instanceId'],['clusterId'=>$clusterId,'membershipDigest'=>$digest,'members'=>array_keys($members),'quorum'=>$quorum]);
        return $this->writeArtifact('cluster','cluster.json',$cluster);
    }

    /** @return array<string,mixed> */
    public function importCluster(array $cluster): array
    {
        $local=$this->requireLocal();$this->ensureIdentity();
        $this->verifyCluster($cluster);
        $members=(array)($cluster['members']??[]);$id=(string)$local['instanceId'];
        if(!isset($members[$id]))throw new \RuntimeException('Lokale Instanz ist nicht Mitglied dieses Failover-Clusters.');
        $own=$this->identityPublic();
        if(!hash_equals((string)($members[$id]['publicKey']??''),(string)($own['publicKey']??'')))throw new \RuntimeException('Cluster-Mitgliedschaft enthält für die lokale Instanz einen anderen Identity-Key.');
        $this->atomicJson($this->clusterPath,$cluster,0640);
        $this->audit('CLUSTER_IMPORT',$id,['clusterId'=>$cluster['clusterId']??null,'membershipDigest'=>$cluster['membershipDigest']??null]);
        return ['schema'=>'easyit.assistant.trust-failover-cluster-import.v1','ok'=>true,'verdict'=>'PASS','cluster'=>$cluster];
    }

    /** @return array<string,mixed> */
    public function createLeaseProposal(): array
    {
        $local=$this->requireLocal();if(($local['role']??'')!=='PRIMARY')throw new \RuntimeException('Nur PRIMARY darf ein Lease vorschlagen.');
        $cluster=$this->requireCluster();$id=(string)$local['instanceId'];$this->requireVotingMember($id,$cluster);
        $current=$this->effectiveLease();$epoch=max(1,(int)($current['epoch']??1));
        if($current!==[] && ($current['holderInstanceId']??'')!==$id && !$this->leaseExpiredWithGrace($current,$cluster))throw new \RuntimeException('Ein anderes PRIMARY besitzt noch ein gültiges Lease.');
        if($current!==[] && ($current['holderInstanceId']??'')!==$id) $epoch=(int)($current['epoch']??0)+1;
        $sequence=(int)($current['sequence']??0)+1;
        $payload=['schema'=>'easyit.assistant.trust-lease-proposal.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'holderInstanceId'=>$id,'epoch'=>$epoch,'sequence'=>$sequence,'durationSeconds'=>(int)$cluster['leaseSeconds'],'previousLeaseDigest'=>$current!==[]?$this->leaseDigest($current):null,'createdAt'=>gmdate('c')];
        $doc=$this->signIdentityDocument($payload);
        $this->audit('LEASE_PROPOSAL',$id,['epoch'=>$epoch,'sequence'=>$sequence]);
        return $this->writeArtifact('lease-proposal','lease-proposal.json',$doc);
    }

    /** @return array<string,mixed> */
    public function acknowledgeLease(array $proposal): array
    {
        $cluster=$this->requireCluster();$local=$this->requireLocal();$id=(string)$local['instanceId'];$this->requireVotingMember($id,$cluster);$this->verifySignedDocument($proposal,$cluster,'holderInstanceId','easyit.assistant.trust-lease-proposal.v1');
        $pp=(array)$proposal['payload'];
        $current=$this->effectiveLease();
        if($current!==[] && !$this->leaseExpired($current) && (($current['holderInstanceId']??'')!==($pp['holderInstanceId']??'') || (int)($current['epoch']??0)!==(int)($pp['epoch']??0)))throw new \RuntimeException('Lease-ACK blockiert: ein anderes gültiges Leadership-Lease ist aktiv.');
        $votes=$this->readJson($this->voteStatePath,['schema'=>'easyit.assistant.trust-failover-votes.v1','epochs'=>[]]);$maxVoted=$this->maxVotedEpoch($votes);
        if((int)$pp['epoch']<$maxVoted)throw new \RuntimeException('Lease-ACK blockiert: Instanz hat bereits für eine höhere Election-Epoche abgestimmt.');
        $payload=['schema'=>'easyit.assistant.trust-lease-ack.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'holderInstanceId'=>(string)$pp['holderInstanceId'],'candidateInstanceId'=>(string)$pp['holderInstanceId'],'epoch'=>(int)$pp['epoch'],'sequence'=>(int)$pp['sequence'],'proposalDigest'=>$this->documentDigest($proposal),'voterInstanceId'=>$id,'ackedAt'=>gmdate('c')];
        $ack=$this->signIdentityDocument($payload);$this->audit('LEASE_ACK',$id,['holder'=>$pp['holderInstanceId'],'epoch'=>$pp['epoch'],'sequence'=>$pp['sequence']]);
        return $this->writeArtifact('lease-ack','lease-ack.json',$ack);
    }

    /** @param list<array<string,mixed>> $acks @return array<string,mixed> */
    public function activateLease(array $proposal,array $acks,string $confirmation): array
    {
        $cluster=$this->requireCluster();$local=$this->requireLocal();$id=(string)$local['instanceId'];if(($local['role']??'')!=='PRIMARY')throw new \RuntimeException('Nur PRIMARY darf ein Lease aktivieren.');
        $this->verifySignedDocument($proposal,$cluster,'holderInstanceId','easyit.assistant.trust-lease-proposal.v1');$pp=(array)$proposal['payload'];if(($pp['holderInstanceId']??'')!==$id)throw new \RuntimeException('Lease-Proposal gehört nicht zur lokalen PRIMARY-Instanz.');
        if(trim($confirmation)!=='ACTIVATE LEASE '.$id.' EPOCH '.(int)$pp['epoch'])throw new \RuntimeException('Lease-Aktivierung abgebrochen. Bestätigung muss exakt "ACTIVATE LEASE '.$id.' EPOCH '.(int)$pp['epoch'].'" lauten.');
        $proofs=$this->verifyAcks($proposal,$acks,$cluster);$now=time();$expires=$now+(int)$pp['durationSeconds'];
        $payload=['schema'=>'easyit.assistant.trust-leadership-lease-payload.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'holderInstanceId'=>$id,'epoch'=>(int)$pp['epoch'],'sequence'=>(int)$pp['sequence'],'source'=>'QUORUM_LEASE','proposalDigest'=>$this->documentDigest($proposal),'activatedAt'=>gmdate('c',$now),'expiresAt'=>gmdate('c',$expires)];
        $signed=$this->signIdentityDocument($payload);$lease=['schema'=>'easyit.assistant.trust-leadership-lease.v1','holderInstanceId'=>$id,'epoch'=>(int)$pp['epoch'],'sequence'=>(int)$pp['sequence'],'clusterDigest'=>$cluster['membershipDigest'],'activatedAt'=>$payload['activatedAt'],'expiresAt'=>$payload['expiresAt'],'quorumVerified'=>true,'quorum'=>(int)$cluster['quorum'],'payload'=>$payload,'proofs'=>$proofs,'signature'=>$signed['signature']];
        $this->verifyLeadershipLease($lease,$cluster);$this->atomicJson($this->leasePath,$lease,0640);$this->atomicJson($this->observedLeasePath,$lease,0640);
        $this->audit('LEASE_ACTIVATE',$id,['epoch'=>$lease['epoch'],'sequence'=>$lease['sequence'],'expiresAt'=>$lease['expiresAt'],'proofCount'=>count($proofs)]);
        return $this->writeArtifact('leadership-lease','leadership-lease.json',$lease);
    }

    /** @return array<string,mixed> */
    public function observeLease(array $lease): array
    {
        $cluster=$this->requireCluster();$this->verifyLeadershipLease($lease,$cluster);$existing=$this->effectiveLease();
        if($existing!==[] && (int)($lease['epoch']??0)<(int)($existing['epoch']??0))throw new \RuntimeException('Älteres Leadership-Lease wird nicht übernommen.');
        $this->atomicJson($this->observedLeasePath,$lease,0640);$this->audit('LEASE_OBSERVE',(string)($lease['holderInstanceId']??''),['epoch'=>$lease['epoch']??null,'expiresAt'=>$lease['expiresAt']??null]);
        return ['schema'=>'easyit.assistant.trust-failover-observe-lease.v1','ok'=>true,'verdict'=>'PASS','lease'=>$lease];
    }

    /** @return array<string,mixed> */
    public function createHeartbeat(): array
    {
        $cluster=$this->requireCluster();$local=$this->requireLocal();$id=(string)$local['instanceId'];if(($local['role']??'')!=='PRIMARY')throw new \RuntimeException('Nur PRIMARY darf Heartbeats erzeugen.');
        $lease=$this->readJson($this->leasePath,[]);$this->verifyLeadershipLease($lease,$cluster);if($this->leaseExpired($lease))throw new \RuntimeException('Heartbeat blockiert: PRIMARY-Lease ist bereits abgelaufen.');
        $payload=['schema'=>'easyit.assistant.trust-primary-heartbeat.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'primaryInstanceId'=>$id,'epoch'=>(int)$lease['epoch'],'leaseDigest'=>$this->leaseDigest($lease),'leaseExpiresAt'=>$lease['expiresAt'],'emittedAt'=>gmdate('c')];
        $doc=$this->signIdentityDocument($payload);$this->audit('HEARTBEAT_EXPORT',$id,['epoch'=>$lease['epoch'],'leaseExpiresAt'=>$lease['expiresAt']]);return $this->writeArtifact('heartbeat','heartbeat.json',$doc);
    }

    /** @return array<string,mixed> */
    public function observeHeartbeat(array $heartbeat): array
    {
        $cluster=$this->requireCluster();$this->verifySignedDocument($heartbeat,$cluster,'primaryInstanceId','easyit.assistant.trust-primary-heartbeat.v1');$p=(array)$heartbeat['payload'];$lease=$this->effectiveLease();
        if($lease!==[] && !hash_equals((string)$p['leaseDigest'],$this->leaseDigest($lease)))throw new \RuntimeException('Heartbeat verweist nicht auf das lokal bekannte Leadership-Lease.');
        $this->atomicJson($this->heartbeatPath,$p,0640);$this->audit('HEARTBEAT_OBSERVE',(string)$p['primaryInstanceId'],['epoch'=>$p['epoch'],'emittedAt'=>$p['emittedAt']]);
        return ['schema'=>'easyit.assistant.trust-failover-heartbeat-observe.v1','ok'=>true,'verdict'=>'PASS','heartbeat'=>$p];
    }

    /** @return array<string,mixed> */
    public function createElectionRequest(string $reason): array
    {
        $cluster=$this->requireCluster();$local=$this->requireLocal();$id=(string)$local['instanceId'];if(($local['role']??'')!=='SECONDARY')throw new \RuntimeException('Nur SECONDARY darf eine Failover-Wahl starten.');$this->requireVotingMember($id,$cluster);
        $lease=$this->effectiveLease();if($lease===[]||!$this->leaseExpiredWithGrace($lease,$cluster))throw new \RuntimeException('Election blockiert: bisheriges PRIMARY-Lease ist noch nicht sicher abgelaufen.');
        $reason=trim($reason);if($reason==='')throw new \InvalidArgumentException('Failover-Grund fehlt.');
        $epoch=(int)($lease['epoch']??0)+1;$payload=['schema'=>'easyit.assistant.trust-election-request.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'candidateInstanceId'=>$id,'epoch'=>$epoch,'previousLeaseDigest'=>$this->leaseDigest($lease),'durationSeconds'=>(int)$cluster['leaseSeconds'],'reason'=>$reason,'createdAt'=>gmdate('c')];
        $doc=$this->signIdentityDocument($payload);$this->audit('ELECTION_REQUEST',$id,['epoch'=>$epoch,'reason'=>$reason]);return $this->writeArtifact('election-request','election-request.json',$doc);
    }

    /** @return array<string,mixed> */
    public function voteElection(array $request): array
    {
        $cluster=$this->requireCluster();$local=$this->requireLocal();$id=(string)$local['instanceId'];$this->requireVotingMember($id,$cluster);$this->verifySignedDocument($request,$cluster,'candidateInstanceId','easyit.assistant.trust-election-request.v1');$p=(array)$request['payload'];
        $lease=$this->effectiveLease();if($lease===[]||!$this->leaseExpiredWithGrace($lease,$cluster))throw new \RuntimeException('Wahlstimme blockiert: bisheriges PRIMARY-Lease ist noch nicht sicher abgelaufen.');
        if(!hash_equals((string)($p['previousLeaseDigest']??''),$this->leaseDigest($lease)))throw new \RuntimeException('Wahlstimme blockiert: Kandidat bezieht sich nicht auf das bekannte letzte Lease.');
        $epoch=(int)$p['epoch'];$candidate=(string)$p['candidateInstanceId'];$state=$this->readJson($this->voteStatePath,['schema'=>'easyit.assistant.trust-failover-votes.v1','epochs'=>[]]);
        $existing=$state['epochs'][(string)$epoch]??null;if(is_array($existing)&&($existing['candidateInstanceId']??'')!==$candidate)throw new \RuntimeException('Split-Brain-Schutz: Diese Instanz hat in dieser Epoche bereits für einen anderen Kandidaten gestimmt.');
        $payload=['schema'=>'easyit.assistant.trust-election-vote.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'candidateInstanceId'=>$candidate,'epoch'=>$epoch,'requestDigest'=>$this->documentDigest($request),'previousLeaseDigest'=>$p['previousLeaseDigest'],'voterInstanceId'=>$id,'votedAt'=>gmdate('c')];
        $vote=$this->signIdentityDocument($payload);$state['epochs'][(string)$epoch]=['candidateInstanceId'=>$candidate,'requestDigest'=>$payload['requestDigest'],'votedAt'=>$payload['votedAt']];$state['updatedAt']=gmdate('c');$this->atomicJson($this->voteStatePath,$state,0640);$this->audit('ELECTION_VOTE',$id,['candidate'=>$candidate,'epoch'=>$epoch]);return $this->writeArtifact('election-vote','election-vote.json',$vote);
    }

    /** @param list<array<string,mixed>> $votes @return array<string,mixed> */
    public function promoteWithQuorum(array $request,array $votes,string $confirmation): array
    {
        $cluster=$this->requireCluster();$local=$this->requireLocal();$id=(string)$local['instanceId'];if(($local['role']??'')!=='SECONDARY')throw new \RuntimeException('Nur SECONDARY kann per Failover-Quorum promotet werden.');
        $this->verifySignedDocument($request,$cluster,'candidateInstanceId','easyit.assistant.trust-election-request.v1');$p=(array)$request['payload'];if(($p['candidateInstanceId']??'')!==$id)throw new \RuntimeException('Election-Request gehört nicht zur lokalen Instanz.');
        $lease=$this->effectiveLease();if($lease===[]||!$this->leaseExpiredWithGrace($lease,$cluster))throw new \RuntimeException('Promotion blockiert: altes PRIMARY-Lease ist noch nicht sicher abgelaufen.');
        $epoch=(int)$p['epoch'];if(trim($confirmation)!=='PROMOTE '.$id.' WITH QUORUM EPOCH '.$epoch)throw new \RuntimeException('Promotion abgebrochen. Bestätigung muss exakt "PROMOTE '.$id.' WITH QUORUM EPOCH '.$epoch.'" lauten.');
        $proofs=$this->verifyVotes($request,$votes,$cluster);
        $ts=$this->trust->status();$active=(string)($ts['activeKeyId']??'');if($active===''||!is_file($this->root.'/storage/assistant/trust/private/'.$active.'.key'))throw new \RuntimeException('Promotion blockiert: kein aktiver privater Release-Signing-Key vorhanden. Zuerst Phase 27/28 Recovery verwenden.');
        $now=time();$expires=$now+(int)$p['durationSeconds'];$payload=['schema'=>'easyit.assistant.trust-leadership-lease-payload.v1','clusterId'=>$cluster['clusterId'],'clusterDigest'=>$cluster['membershipDigest'],'holderInstanceId'=>$id,'epoch'=>$epoch,'sequence'=>1,'source'=>'ELECTION_QUORUM','proposalDigest'=>$this->documentDigest($request),'activatedAt'=>gmdate('c',$now),'expiresAt'=>gmdate('c',$expires)];$signed=$this->signIdentityDocument($payload);
        $newLease=['schema'=>'easyit.assistant.trust-leadership-lease.v1','holderInstanceId'=>$id,'epoch'=>$epoch,'sequence'=>1,'clusterDigest'=>$cluster['membershipDigest'],'activatedAt'=>$payload['activatedAt'],'expiresAt'=>$payload['expiresAt'],'quorumVerified'=>true,'quorum'=>(int)$cluster['quorum'],'payload'=>$payload,'proofs'=>$proofs,'signature'=>$signed['signature']];$this->verifyLeadershipLease($newLease,$cluster);
        // Atomic-enough local fencing order: write quorum certificate first, then role. ReleaseTrustStore requires both.
        $this->atomicJson($this->leasePath,$newLease,0640);$this->atomicJson($this->observedLeasePath,$newLease,0640);
        $local['role']='PRIMARY';$local['roleChangedAt']=gmdate('c');$local['roleChangeReason']='PHASE29_QUORUM_FAILOVER';$local['failoverEpoch']=$epoch;$local['updatedAt']=gmdate('c');$this->atomicJson($this->localPath,$local,0640);
        $this->audit('FAILOVER_PROMOTE',$id,['epoch'=>$epoch,'proofCount'=>count($proofs),'expiresAt'=>$newLease['expiresAt']]);
        return ['schema'=>'easyit.assistant.trust-failover-promotion.v1','ok'=>true,'verdict'=>'PASS','instance'=>$local,'lease'=>$newLease,'proofCount'=>count($proofs),'quorum'=>(int)$cluster['quorum']];
    }

    /** @return array<string,mixed> */
    public function verifyAudit(): array
    {
        if(!is_file($this->auditPath))return ['schema'=>'easyit.assistant.trust-failover-audit-verification.v1','ok'=>true,'verdict'=>'PASS','events'=>0,'lastHash'=>null,'errors'=>[]];
        $lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$prev='';$errors=[];$count=0;
        foreach($lines as $line){$e=json_decode($line,true);if(!is_array($e)){$errors[]='Ungültige Audit-Zeile '.($count+1);break;}$hash=(string)($e['eventHash']??'');$payload=$e;unset($payload['eventHash']);if((string)($payload['previousHash']??'')!==$prev)$errors[]='Audit-Kettenbruch bei '.($e['eventId']??$count);if(!hash_equals($hash,hash('sha256',$this->canonical($payload))))$errors[]='Audit-Hash ungültig bei '.($e['eventId']??$count);$prev=$hash;$count++;}
        return ['schema'=>'easyit.assistant.trust-failover-audit-verification.v1','ok'=>$errors===[],'verdict'=>$errors===[]?'PASS':'FAIL','events'=>$count,'lastHash'=>$prev?:null,'errors'=>$errors,'checkedAt'=>gmdate('c')];
    }

    /** @return array<string,mixed> */ public function readArtifact(string $path): array { $d=$this->readJson($path,[]); if($d===[])throw new \RuntimeException('JSON-Artefakt ist leer oder ungültig.'); return $d; }
    public function artifactPath(string $file): string { $file=basename($file); if($file===''||!preg_match('/^[A-Za-z0-9._-]+\.json$/',$file))throw new \RuntimeException('Ungültiger Failover-Dateiname.');$path=$this->outbox.'/'.$file;if(!is_file($path))throw new \RuntimeException('Failover-Artefakt wurde nicht gefunden.');return $path; }

    /** @return array<string,mixed> */
    private function cluster(): array { return $this->readJson($this->clusterPath,[]); }
    /** @return array<string,mixed> */
    private function requireCluster(): array { $c=$this->cluster();if($c===[]||empty($c['enabled']))throw new \RuntimeException('Failover-Cluster ist noch nicht konfiguriert.');$joint=$this->readJson($this->base . '/membership/joint.json',[]);if(!empty($joint['active']))throw new \RuntimeException('Phase-30-Reconfiguration aktiv: Leadership-Leases, Heartbeats und Elections sind bis zur Finalisierung des Joint-Consensus pausiert.');$this->verifyCluster($c);return $c; }
    /** @return array<string,mixed> */ private function local(): array { return $this->readJson($this->localPath,[]); }
    /** @return array<string,mixed> */ private function requireLocal(): array { $l=$this->local();if($l===[])throw new \RuntimeException('Phase 28 Trust-Instanz muss zuerst initialisiert werden.');return $l; }
    /** @return array<string,mixed> */ private function identityPublic(): array { $i=$this->readJson($this->identityPath,[]);return is_array($i['payload']??null)?(array)$i['payload']:[]; }
    private function identityPrivatePath(): string { $id=(string)($this->local()['instanceId']??'local');return $this->privateDir.'/'.$this->safe($id).'.key'; }

    /** @return string instance id */
    private function verifyIdentityBundle(array $bundle): string
    {
        $this->assertSodium();$p=(array)($bundle['payload']??[]);if(($p['schema']??'')!=='easyit.assistant.trust-failover-identity.v1')throw new \RuntimeException('Identity-Bundle besitzt ein falsches Schema.');$id=(string)($p['instanceId']??'');if($id==='')throw new \RuntimeException('Identity-Bundle enthält keine Instanz-ID.');$pub=base64_decode((string)($p['publicKey']??''),true);$sig=base64_decode((string)($bundle['selfSignature']??''),true);if($pub===false||$sig===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($sig,$this->canonical($p),$pub))throw new \RuntimeException('Identity-Bundle-Signatur ist ungültig: '.$id);return $id;
    }

    private function verifyCluster(array $cluster): void
    {
        if(($cluster['schema']??'')!=='easyit.assistant.trust-failover-cluster.v1')throw new \RuntimeException('Failover-Cluster-Schema ist ungültig.');$payload=(array)($cluster['payload']??[]);$members=(array)($cluster['members']??[]);if($members===[]||count($members)<3)throw new \RuntimeException('Failover-Cluster benötigt mindestens drei Mitglieder.');$mp=['schema'=>'easyit.assistant.trust-failover-membership.v1','clusterId'=>$cluster['clusterId'],'members'=>$members,'quorum'=>(int)$cluster['quorum'],'leaseSeconds'=>(int)$cluster['leaseSeconds'],'heartbeatTimeoutSeconds'=>(int)$cluster['heartbeatTimeoutSeconds'],'leaseGraceSeconds'=>(int)$cluster['leaseGraceSeconds']];$digest=hash('sha256',$this->canonical($mp));if(!hash_equals($digest,(string)($cluster['membershipDigest']??''))||!hash_equals($digest,(string)($payload['membershipDigest']??'')))throw new \RuntimeException('Failover-Mitgliedschafts-Digest ist ungültig.');$expected=intdiv(count(array_filter($members,fn($m)=>is_array($m)&&!empty($m['voting']))),2)+1;if((int)$cluster['quorum']!==$expected)throw new \RuntimeException('Failover-Quorum stimmt nicht mit der Mitgliedschaft überein.');$sig=(array)($cluster['signature']??[]);$v=$this->trust->verifyForPurpose($this->canonical($payload),(string)($sig['signature']??''),(string)($sig['keyId']??''),'install');if(empty($v['ok']))throw new \RuntimeException('Failover-Cluster ist nicht mit einem vertrauenswürdigen RELEASE-Key signiert: '.implode(' ',(array)($v['errors']??[])));
    }

    /** @return array<string,mixed> */
    private function signIdentityDocument(array $payload): array
    {
        $this->assertSodium();$identity=$this->ensureIdentity();$sec=base64_decode(trim((string)file_get_contents($this->identityPrivatePath())),true);if($sec===false||strlen($sec)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new \RuntimeException('Lokaler Failover-Identity-Key ist ungültig.');$sig=sodium_crypto_sign_detached($this->canonical($payload),$sec);return ['schema'=>'easyit.assistant.trust-failover-signed-document.v1','payload'=>$payload,'signature'=>['instanceId'=>(string)$identity['payload']['instanceId'],'algorithm'=>'Ed25519','signature'=>base64_encode($sig)]];
    }

    private function verifySignedDocument(array $doc,array $cluster,string $subjectField,string $schema): void
    {
        $this->assertSodium();$p=(array)($doc['payload']??[]);if(($p['schema']??'')!==$schema)throw new \RuntimeException('Signiertes Failover-Dokument besitzt ein unerwartetes Schema.');if(($p['clusterDigest']??'')!==($cluster['membershipDigest']??''))throw new \RuntimeException('Failover-Dokument gehört nicht zur aktuellen Cluster-Mitgliedschaft.');$subject=(string)($p[$subjectField]??'');$m=$cluster['members'][$subject]??null;if(!is_array($m))throw new \RuntimeException('Signatur-Subjekt ist kein Cluster-Mitglied: '.$subject);$s=(array)($doc['signature']??[]);if(($s['instanceId']??'')!==$subject)throw new \RuntimeException('Signatur-Instanz stimmt nicht mit dem Dokumentsubjekt überein.');$pub=base64_decode((string)($m['publicKey']??''),true);$sig=base64_decode((string)($s['signature']??''),true);if($pub===false||$sig===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($sig,$this->canonical($p),$pub))throw new \RuntimeException('Failover-Dokument-Signatur ist ungültig.');
    }

    /** @param list<array<string,mixed>> $acks @return list<array<string,mixed>> */
    private function verifyAcks(array $proposal,array $acks,array $cluster): array
    {
        $pp=(array)$proposal['payload'];$digest=$this->documentDigest($proposal);$seen=[];$valid=[];
        foreach($acks as $ack){if(!is_array($ack))continue;$p=(array)($ack['payload']??[]);$voter=(string)($p['voterInstanceId']??'');if($voter===''||isset($seen[$voter]))continue;$this->verifySignedDocument($ack,$cluster,'voterInstanceId','easyit.assistant.trust-lease-ack.v1');if(($p['holderInstanceId']??'')!==($pp['holderInstanceId']??'')||(int)($p['epoch']??-1)!==(int)$pp['epoch']||(int)($p['sequence']??-1)!==(int)$pp['sequence']||!hash_equals((string)($p['proposalDigest']??''),$digest))continue;if(empty($cluster['members'][$voter]['voting']))continue;$seen[$voter]=true;$valid[]=$ack;}
        if(count($valid)<(int)$cluster['quorum'])throw new \RuntimeException('Lease-Aktivierung blockiert: nur '.count($valid).' gültige ACKs, benötigt '.(int)$cluster['quorum'].'.');return $valid;
    }

    /** @param list<array<string,mixed>> $votes @return list<array<string,mixed>> */
    private function verifyVotes(array $request,array $votes,array $cluster): array
    {
        $rp=(array)$request['payload'];$digest=$this->documentDigest($request);$seen=[];$valid=[];
        foreach($votes as $vote){if(!is_array($vote))continue;$p=(array)($vote['payload']??[]);$voter=(string)($p['voterInstanceId']??'');if($voter===''||isset($seen[$voter]))continue;$this->verifySignedDocument($vote,$cluster,'voterInstanceId','easyit.assistant.trust-election-vote.v1');if(($p['candidateInstanceId']??'')!==($rp['candidateInstanceId']??'')||(int)($p['epoch']??-1)!==(int)$rp['epoch']||!hash_equals((string)($p['requestDigest']??''),$digest)||!hash_equals((string)($p['previousLeaseDigest']??''),(string)$rp['previousLeaseDigest']))continue;if(empty($cluster['members'][$voter]['voting']))continue;$seen[$voter]=true;$valid[]=$vote;}
        if(count($valid)<(int)$cluster['quorum'])throw new \RuntimeException('Promotion blockiert: nur '.count($valid).' gültige Stimmen, benötigt '.(int)$cluster['quorum'].'.');return $valid;
    }

    private function verifyLeadershipLease(array $lease,array $cluster): void
    {
        if(($lease['schema']??'')!=='easyit.assistant.trust-leadership-lease.v1')throw new \RuntimeException('Leadership-Lease-Schema ist ungültig.');if(($lease['clusterDigest']??'')!==($cluster['membershipDigest']??''))throw new \RuntimeException('Leadership-Lease gehört nicht zur aktuellen Cluster-Mitgliedschaft.');$holder=(string)($lease['holderInstanceId']??'');$p=(array)($lease['payload']??[]);$doc=['payload'=>$p,'signature'=>$lease['signature']??[]];$this->verifySignedDocument($doc,$cluster,'holderInstanceId','easyit.assistant.trust-leadership-lease-payload.v1');if((int)($p['epoch']??-1)!==(int)($lease['epoch']??-2))throw new \RuntimeException('Leadership-Lease-Epoche ist inkonsistent.');$proofs=(array)($lease['proofs']??[]);$seen=[];
        foreach($proofs as $proof){if(!is_array($proof))continue;$pp=(array)($proof['payload']??[]);$voter=(string)($pp['voterInstanceId']??'');if($voter===''||isset($seen[$voter]))continue;$schema=(string)($pp['schema']??'');if(!in_array($schema,['easyit.assistant.trust-lease-ack.v1','easyit.assistant.trust-election-vote.v1'],true))continue;$this->verifySignedDocument($proof,$cluster,'voterInstanceId',$schema);if(($pp['candidateInstanceId']??$pp['holderInstanceId']??'')!==$holder||(int)($pp['epoch']??-1)!==(int)$lease['epoch'])continue;if(empty($cluster['members'][$voter]['voting']))continue;$seen[$voter]=true;}
        if(count($seen)<(int)$cluster['quorum'])throw new \RuntimeException('Leadership-Lease enthält kein ausreichendes Quorum.');if(empty($lease['quorumVerified']))throw new \RuntimeException('Leadership-Lease ist nicht als quorumverifiziert markiert.');
    }

    /** @return array<string,mixed> */
    private function effectiveLease(): array
    {
        $a=$this->readJson($this->leasePath,[]);$b=$this->readJson($this->observedLeasePath,[]);if($a===[])return $b;if($b===[])return $a;$ae=(int)($a['epoch']??0);$be=(int)($b['epoch']??0);if($be>$ae)return $b;if($ae>$be)return $a;return (int)($b['sequence']??0)>(int)($a['sequence']??0)?$b:$a;
    }
    private function leaseExpired(array $lease): bool { $t=$this->ts($lease['expiresAt']??null);return $t===null||time()>=$t; }
    private function leaseExpiredWithGrace(array $lease,array $cluster): bool { $t=$this->ts($lease['expiresAt']??null);return $t===null||time()>=($t+(int)($cluster['leaseGraceSeconds']??0)); }
    private function leaseDigest(array $lease): string { return hash('sha256',$this->canonical($lease)); }
    private function documentDigest(array $doc): string { return hash('sha256',$this->canonical($doc)); }
    private function maxVotedEpoch(array $state): int { $max=0;foreach(array_keys((array)($state['epochs']??[])) as $e)$max=max($max,(int)$e);return $max; }
    private function requireVotingMember(string $id,array $cluster): void { if(empty($cluster['members'][$id]['voting']))throw new \RuntimeException('Lokale Instanz ist kein stimmberechtigtes Cluster-Mitglied.'); }

    /** @return array<string,mixed> */
    private function writeArtifact(string $kind,string $suffix,array $data): array
    {
        $this->ensureDir($this->outbox);$file='easyit-'.$kind.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'-'.$suffix;$path=$this->outbox.'/'.$file;$this->atomicJson($path,$data,0640);return ['schema'=>'easyit.assistant.trust-failover-artifact.v1','ok'=>true,'verdict'=>'PASS','kind'=>$kind,'file'=>$file,'path'=>$path,'sha256'=>hash_file('sha256',$path),'data'=>$data];
    }
    /** @return array<string,mixed> */ private function readJson(string $path,array $default): array { if(!is_file($path))return $default;$d=json_decode((string)@file_get_contents($path),true);return is_array($d)?$d:$default; }
    private function atomicJson(string $path,array $data,int $mode): void { $this->atomicRaw($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",$mode); }
    private function atomicRaw(string $path,string $content,int $mode): void { $this->ensureDir(dirname($path));$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));if(file_put_contents($tmp,$content,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Datei kann nicht atomar gespeichert werden: '.$path);}@chmod($path,$mode); }
    private function ensureDir(string $dir): void { if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis kann nicht erstellt werden: '.$dir); }
    private function safe(string $s): string { return trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',trim($s)),'-')?:'instance'; }
    private function ts(mixed $v): ?int { if(!is_string($v)||trim($v)==='')return null;$t=strtotime($v);return $t===false?null:$t; }
    private function assertSodium(): void { if(!function_exists('sodium_crypto_sign_keypair'))throw new \RuntimeException('Phase 29 benötigt PHP ext-sodium.'); }
    /** @param mixed $v */ private function normalize($v){if(!is_array($v))return $v;if(array_is_list($v))return array_map(fn($x)=>$this->normalize($x),$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$this->normalize($x);return $v;}
    private function canonical(array $d): string { return json_encode($this->normalize($d),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
    private function audit(string $action,string $instanceId,array $details=[]): void { $this->ensureDir($this->base);$prev='';if(is_file($this->auditPath)){$lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$last=end($lines);if(is_string($last)){$e=json_decode($last,true);if(is_array($e))$prev=(string)($e['eventHash']??'');}}$payload=['schema'=>'easyit.assistant.trust-failover-audit-event.v1','eventId'=>'failover-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)),'action'=>$action,'instanceId'=>$instanceId,'occurredAt'=>gmdate('c'),'previousHash'=>$prev,'details'=>$details];$payload['eventHash']=hash('sha256',$this->canonical($payload));file_put_contents($this->auditPath,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);@chmod($this->auditPath,0640); }
}
