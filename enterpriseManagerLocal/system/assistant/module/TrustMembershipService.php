<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

/**
 * Phase 30: safe failover-cluster membership reconfiguration.
 *
 * Reconfiguration uses a two-step joint-consensus protocol:
 *  1. PREPARE must be acknowledged by a majority of OLD voters and a
 *     majority of NEW voters.
 *  2. COMMIT repeats the dual-majority proof over the activated joint state.
 *
 * While joint consensus is active, Phase 29 release signing and leadership
 * changes are fenced. The final cluster document is signed before entering
 * joint mode, so finalization never needs to bypass that fence.
 */
final class TrustMembershipService
{
    private string $root;
    private string $base;
    private string $clusterPath;
    private string $jointPath;
    private string $historyDir;
    private string $outbox;
    private string $localPath;
    private string $identityPath;
    private string $privateDir;
    private string $leasePath;
    private string $observedLeasePath;
    private string $auditPath;

    public function __construct(private ReleaseTrustStore $trust, string $root)
    {
        $this->root = rtrim($root, '/\\');
        $failover = $this->root . '/storage/assistant/trust/instances/failover';
        $this->base = $failover . '/membership';
        $this->clusterPath = $failover . '/cluster.json';
        $this->jointPath = $this->base . '/joint.json';
        $this->historyDir = $this->base . '/history';
        $this->outbox = $this->base . '/outbox';
        $this->localPath = $this->root . '/storage/assistant/trust/instances/local.json';
        $this->identityPath = $failover . '/identity.json';
        $this->privateDir = $failover . '/private';
        $this->leasePath = $failover . '/leadership-lease.json';
        $this->observedLeasePath = $failover . '/observed-leadership-lease.json';
        $this->auditPath = $this->base . '/membership-audit.jsonl';
    }

    /** @return array<string,mixed> */
    public function status(): array
    {
        $cluster = $this->readJson($this->clusterPath, []);
        $joint = $this->readJson($this->jointPath, []);
        $voters = $this->voterIds((array)($cluster['members'] ?? []));
        return [
            'schema' => 'easyit.assistant.trust-membership-status.v1',
            'configured' => $cluster !== [],
            'clusterId' => $cluster['clusterId'] ?? null,
            'membershipEpoch' => (int)($cluster['membershipEpoch'] ?? 1),
            'membershipDigest' => $cluster['membershipDigest'] ?? null,
            'members' => array_values((array)($cluster['members'] ?? [])),
            'voters' => $voters,
            'quorum' => $cluster === [] ? 0 : $this->quorum(count($voters)),
            'jointActive' => !empty($joint['active']),
            'joint' => $joint,
            'audit' => $this->verifyAudit(),
            'checkedAt' => gmdate('c'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $newIdentityBundles
     * @param list<string> $removeInstanceIds
     * @param array<string,bool> $votingOverrides
     * @return array<string,mixed>
     */
    public function createProposal(array $newIdentityBundles, array $removeInstanceIds, array $votingOverrides, string $confirmation): array
    {
        $this->assertSodium();
        if ($this->jointActive()) throw new \RuntimeException('Eine Mitgliedschaftsänderung befindet sich bereits im Joint-Consensus. Zuerst abschließen.');
        $local = $this->requireLocal();
        if (($local['role'] ?? '') !== 'PRIMARY' || ($local['status'] ?? 'ACTIVE') !== 'ACTIVE') throw new \RuntimeException('Nur das aktive PRIMARY darf eine Mitgliedschaftsänderung vorschlagen.');
        TrustFailoverLeaseGuard::assertCanSign($this->root);
        $old = $this->requireCluster();
        $clusterId = (string)$old['clusterId'];
        $nextEpoch = max(2, (int)($old['membershipEpoch'] ?? 1) + 1);
        $expected = 'PROPOSE MEMBERSHIP ' . $clusterId . ' EPOCH ' . $nextEpoch;
        if (trim($confirmation) !== $expected) throw new \RuntimeException('Mitgliedschaftsänderung abgebrochen. Bestätigung muss exakt "' . $expected . '" lauten.');

        $members = (array)$old['members'];
        foreach ($newIdentityBundles as $bundle) {
            if (!is_array($bundle)) continue;
            $identity = $this->verifyIdentityBundle($bundle);
            $p = (array)$bundle['payload'];
            if (isset($members[$identity])) {
                if (!hash_equals((string)($members[$identity]['publicKey'] ?? ''), (string)($p['publicKey'] ?? ''))) throw new \RuntimeException('Identity-Key eines vorhandenen Mitglieds darf nicht durch Reconfiguration ersetzt werden: ' . $identity);
                continue;
            }
            $members[$identity] = ['instanceId'=>$identity,'name'=>(string)($p['instanceName'] ?? $identity),'publicKey'=>(string)$p['publicKey'],'voting'=>false];
        }
        foreach ($removeInstanceIds as $id) {
            $id = trim((string)$id); if ($id === '') continue;
            unset($members[$id]);
        }
        foreach ($votingOverrides as $id => $value) {
            if (!isset($members[$id])) throw new \RuntimeException('Voting-Override verweist auf unbekanntes Zielmitglied: ' . $id);
            $members[$id]['voting'] = (bool)$value;
        }
        ksort($members, SORT_STRING);
        $localId = (string)$local['instanceId'];
        if (!isset($members[$localId]) || empty($members[$localId]['voting'])) throw new \RuntimeException('Das aktive PRIMARY darf nicht in derselben Reconfiguration entfernt oder auf non-voting gesetzt werden. Zuerst Leadership übertragen.');
        $newVoters = $this->voterIds($members);
        if (count($newVoters) < 3) throw new \RuntimeException('Die Zielkonfiguration benötigt mindestens drei stimmberechtigte Mitglieder.');
        if ($members === (array)$old['members']) throw new \RuntimeException('Die vorgeschlagene Mitgliedschaft enthält keine Änderung.');

        $newQuorum = $this->quorum(count($newVoters));
        $newMembershipPayload = [
            'schema'=>'easyit.assistant.trust-failover-membership.v1','clusterId'=>$clusterId,'members'=>$members,'quorum'=>$newQuorum,
            'leaseSeconds'=>(int)$old['leaseSeconds'],'heartbeatTimeoutSeconds'=>(int)$old['heartbeatTimeoutSeconds'],'leaseGraceSeconds'=>(int)$old['leaseGraceSeconds'],
        ];
        $newDigest = hash('sha256', $this->canonical($newMembershipPayload));
        $finalPayload = $newMembershipPayload + ['membershipDigest'=>$newDigest,'membershipEpoch'=>$nextEpoch,'createdByInstanceId'=>$localId,'createdAt'=>gmdate('c'),'reconfiguredFromDigest'=>(string)$old['membershipDigest']];
        $finalSignature = $this->trust->sign($this->canonical($finalPayload));
        $finalCluster = [
            'schema'=>'easyit.assistant.trust-failover-cluster.v1','enabled'=>true,'payload'=>$finalPayload,'signature'=>$finalSignature,
            'clusterId'=>$clusterId,'members'=>$members,'quorum'=>$newQuorum,'leaseSeconds'=>(int)$old['leaseSeconds'],
            'heartbeatTimeoutSeconds'=>(int)$old['heartbeatTimeoutSeconds'],'leaseGraceSeconds'=>(int)$old['leaseGraceSeconds'],
            'membershipDigest'=>$newDigest,'membershipEpoch'=>$nextEpoch,'configuredAt'=>gmdate('c'),
        ];
        $this->verifyCluster($finalCluster);

        $oldVoters = $this->voterIds((array)$old['members']);
        $payload = [
            'schema'=>'easyit.assistant.trust-membership-proposal.v1','clusterId'=>$clusterId,'membershipEpoch'=>$nextEpoch,
            'proposerInstanceId'=>$localId,'oldDigest'=>(string)$old['membershipDigest'],'newDigest'=>$newDigest,
            'oldMembers'=>(array)$old['members'],'newMembers'=>$members,'oldVoters'=>$oldVoters,'newVoters'=>$newVoters,
            'oldQuorum'=>$this->quorum(count($oldVoters)),'newQuorum'=>$newQuorum,
            'finalClusterSha256'=>hash('sha256',$this->canonical($finalCluster)),'createdAt'=>gmdate('c'),
        ];
        $proposal = $this->signIdentity($payload);
        $proposal['oldCluster'] = $old;
        $proposal['finalCluster'] = $finalCluster;
        $proposal['transitionId'] = 'membership-' . $nextEpoch . '-' . substr(hash('sha256',$this->canonical($payload)),0,16);
        $this->audit('MEMBERSHIP_PROPOSE',$localId,['transitionId'=>$proposal['transitionId'],'epoch'=>$nextEpoch,'oldVoters'=>$oldVoters,'newVoters'=>$newVoters]);
        return $this->writeArtifact('membership-proposal','membership-proposal.json',$proposal);
    }

    /** @return array<string,mixed> */
    public function acknowledgePrepare(array $proposal): array
    {
        $this->verifyProposal($proposal);
        $local = $this->requireLocal(); $id = (string)$local['instanceId'];
        $payload = (array)$proposal['payload'];
        $union = array_unique(array_merge((array)$payload['oldVoters'], (array)$payload['newVoters']));
        if (!in_array($id,$union,true)) throw new \RuntimeException('Nur ein altes oder neues Voting-Mitglied darf PREPARE bestätigen.');
        $ackPayload = ['schema'=>'easyit.assistant.trust-membership-ack.v1','phase'=>'PREPARE','transitionId'=>(string)$proposal['transitionId'],'clusterId'=>(string)$payload['clusterId'],'membershipEpoch'=>(int)$payload['membershipEpoch'],'proposalDigest'=>$this->documentDigest($proposal),'voterInstanceId'=>$id,'ackedAt'=>gmdate('c')];
        $ack = $this->signIdentity($ackPayload);
        $this->audit('MEMBERSHIP_PREPARE_ACK',$id,['transitionId'=>$proposal['transitionId'],'epoch'=>$payload['membershipEpoch']]);
        return $this->writeArtifact('membership-prepare-ack','membership-prepare-ack.json',$ack);
    }

    /** @param list<array<string,mixed>> $acks @return array<string,mixed> */
    public function activateJoint(array $proposal, array $acks, string $confirmation): array
    {
        $this->verifyProposal($proposal); if ($this->jointActive()) throw new \RuntimeException('Joint-Consensus ist bereits aktiv.');
        $local=$this->requireLocal();$p=(array)$proposal['payload'];
        if (($local['role']??'')!=='PRIMARY'||(string)$local['instanceId']!==(string)$p['proposerInstanceId']) throw new \RuntimeException('Nur das vorschlagende PRIMARY darf Joint-Consensus aktivieren.');
        $expected='ACTIVATE JOINT '.$proposal['transitionId']; if(trim($confirmation)!==$expected)throw new \RuntimeException('Joint-Aktivierung abgebrochen. Bestätigung muss exakt "'.$expected.'" lauten.');
        $proof=$this->verifyMembershipAcks($proposal,$acks,'PREPARE');
        $jointPayload=['schema'=>'easyit.assistant.trust-membership-joint.v1','active'=>true,'transitionId'=>$proposal['transitionId'],'clusterId'=>$p['clusterId'],'membershipEpoch'=>$p['membershipEpoch'],'oldDigest'=>$p['oldDigest'],'newDigest'=>$p['newDigest'],'oldMembers'=>$p['oldMembers'],'newMembers'=>$p['newMembers'],'oldVoters'=>$p['oldVoters'],'newVoters'=>$p['newVoters'],'oldQuorum'=>$p['oldQuorum'],'newQuorum'=>$p['newQuorum'],'proposalDigest'=>$this->documentDigest($proposal),'prepareProofs'=>$proof,'activatedAt'=>gmdate('c'),'finalCluster'=>$proposal['finalCluster']];
        $joint=$this->signIdentity($jointPayload);$joint['active']=true;$joint['transitionId']=$proposal['transitionId'];
        $this->atomicJson($this->jointPath,$joint,0640);
        $this->audit('JOINT_ACTIVATE',(string)$local['instanceId'],['transitionId'=>$proposal['transitionId'],'oldProofs'=>$proof['oldCount'],'newProofs'=>$proof['newCount']]);
        return $this->writeArtifact('membership-joint','membership-joint.json',$joint);
    }

    /** @return array<string,mixed> */
    public function importJoint(array $joint): array
    {
        $this->verifyJoint($joint);$local=$this->requireLocal();$id=(string)$local['instanceId'];$p=(array)$joint['payload'];$all=array_unique(array_merge(array_keys((array)$p['oldMembers']),array_keys((array)$p['newMembers'])));
        if(!in_array($id,$all,true))throw new \RuntimeException('Lokale Instanz gehört weder zur alten noch zur neuen Mitgliedschaft.');
        $this->atomicJson($this->jointPath,$joint,0640);$this->audit('JOINT_IMPORT',$id,['transitionId'=>$joint['transitionId']??null]);
        return ['schema'=>'easyit.assistant.trust-membership-joint-import.v1','ok'=>true,'verdict'=>'PASS','joint'=>$joint];
    }

    /** @return array<string,mixed> */
    public function acknowledgeCommit(array $joint): array
    {
        $this->verifyJoint($joint);$local=$this->requireLocal();$id=(string)$local['instanceId'];$p=(array)$joint['payload'];$union=array_unique(array_merge((array)$p['oldVoters'],(array)$p['newVoters']));
        if(!in_array($id,$union,true))throw new \RuntimeException('Nur ein altes oder neues Voting-Mitglied darf COMMIT bestätigen.');
        $payload=['schema'=>'easyit.assistant.trust-membership-ack.v1','phase'=>'COMMIT','transitionId'=>(string)$joint['transitionId'],'clusterId'=>(string)$p['clusterId'],'membershipEpoch'=>(int)$p['membershipEpoch'],'proposalDigest'=>(string)$p['proposalDigest'],'jointDigest'=>$this->documentDigest($joint),'voterInstanceId'=>$id,'ackedAt'=>gmdate('c')];
        $ack=$this->signIdentity($payload);$this->audit('MEMBERSHIP_COMMIT_ACK',$id,['transitionId'=>$joint['transitionId'],'epoch'=>$p['membershipEpoch']]);
        return $this->writeArtifact('membership-commit-ack','membership-commit-ack.json',$ack);
    }

    /** @param list<array<string,mixed>> $acks @return array<string,mixed> */
    public function finalize(array $joint,array $acks,string $confirmation): array
    {
        $this->verifyJoint($joint);$local=$this->requireLocal();$p=(array)$joint['payload'];$id=(string)$local['instanceId'];
        if(($local['role']??'')!=='PRIMARY'||!isset(((array)$p['newMembers'])[$id]))throw new \RuntimeException('Nur das aktive, in der Zielmitgliedschaft verbleibende PRIMARY darf finalisieren.');
        $expected='FINALIZE MEMBERSHIP '.$joint['transitionId'];if(trim($confirmation)!==$expected)throw new \RuntimeException('Finalisierung abgebrochen. Bestätigung muss exakt "'.$expected.'" lauten.');
        $proof=$this->verifyCommitAcks($joint,$acks);$final=(array)$p['finalCluster'];$this->verifyCluster($final);
        if(!hash_equals((string)$p['newDigest'],(string)$final['membershipDigest']))throw new \RuntimeException('Finale Clusterkonfiguration stimmt nicht mit dem Joint-Ziel überein.');
        $this->archiveCurrent('before-finalize-'.$joint['transitionId']);
        $this->atomicJson($this->clusterPath,$final,0640);@unlink($this->jointPath);
        // Old leases are tied to the previous membership digest and must never survive membership finalization.
        @unlink($this->leasePath);@unlink($this->observedLeasePath);
        $record=['schema'=>'easyit.assistant.trust-membership-finalized.v1','transitionId'=>$joint['transitionId'],'finalizedAt'=>gmdate('c'),'proof'=>$proof,'cluster'=>$final];
        $this->ensureDir($this->historyDir);$this->atomicJson($this->historyDir.'/'.$this->safe((string)$joint['transitionId']).'.json',$record,0640);
        $this->audit('MEMBERSHIP_FINALIZE',$id,['transitionId'=>$joint['transitionId'],'membershipDigest'=>$final['membershipDigest'],'oldProofs'=>$proof['oldCount'],'newProofs'=>$proof['newCount']]);
        return $this->writeArtifact('membership-final','membership-final.json',$final);
    }

    /** @return array<string,mixed> */
    public function importFinalCluster(array $cluster): array
    {
        $this->verifyCluster($cluster);$local=$this->requireLocal();$id=(string)$local['instanceId'];$old=$this->readJson($this->clusterPath,[]);
        if($old!==[]&&($old['clusterId']??'')!==($cluster['clusterId']??''))throw new \RuntimeException('Finale Konfiguration gehört zu einem anderen Cluster.');
        $this->archiveCurrent('before-import-final');$this->atomicJson($this->clusterPath,$cluster,0640);@unlink($this->jointPath);@unlink($this->leasePath);@unlink($this->observedLeasePath);
        $removed=!isset(((array)$cluster['members'])[$id]);
        if($removed){$local['role']='VERIFY_ONLY';$local['status']='RETIRED';$local['retiredAt']=gmdate('c');$local['retiredReason']='REMOVED_FROM_FAILOVER_MEMBERSHIP';$local['updatedAt']=gmdate('c');$this->atomicJson($this->localPath,$local,0640);}
        else{$own=$this->identityPublic();if(!hash_equals((string)($cluster['members'][$id]['publicKey']??''),(string)($own['publicKey']??'')))throw new \RuntimeException('Finale Mitgliedschaft enthält für die lokale Instanz einen anderen Identity-Key.');}
        $this->audit('MEMBERSHIP_FINAL_IMPORT',$id,['membershipDigest'=>$cluster['membershipDigest']??null,'removed'=>$removed]);
        return ['schema'=>'easyit.assistant.trust-membership-final-import.v1','ok'=>true,'verdict'=>'PASS','removed'=>$removed,'cluster'=>$cluster];
    }

    /** @return array<string,mixed> */
    public function verifyAudit(): array
    {
        if(!is_file($this->auditPath))return ['ok'=>true,'count'=>0,'lastHash'=>null,'errors'=>[]];$prev='';$count=0;$errors=[];
        foreach(file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$e=json_decode($line,true);if(!is_array($e)){ $errors[]='Ungültige JSON-Zeile im Membership-Audit.';continue;}$hash=(string)($e['eventHash']??'');$copy=$e;unset($copy['eventHash']);$calc=hash('sha256',$this->canonical($copy));if(!hash_equals($prev,(string)($e['previousHash']??'')))$errors[]='Membership-Audit previousHash ist inkonsistent.';if(!hash_equals($calc,$hash))$errors[]='Membership-Audit eventHash ist ungültig.';$prev=$hash;$count++;}
        return ['ok'=>$errors===[],'count'=>$count,'lastHash'=>$prev?:null,'errors'=>$errors];
    }

    /** @return array<string,mixed> */ public function readArtifact(string $path): array { $d=$this->readJson($path,[]);if($d===[])throw new \RuntimeException('JSON-Artefakt ist leer oder ungültig.');return $d; }
    public function artifactPath(string $file): string { $file=basename($file);if($file===''||!preg_match('/^[A-Za-z0-9._-]+\.json$/',$file))throw new \RuntimeException('Ungültiger Membership-Dateiname.');$path=$this->outbox.'/'.$file;if(!is_file($path))throw new \RuntimeException('Membership-Artefakt wurde nicht gefunden.');return $path; }

    /** @return array{oldCount:int,newCount:int,uniqueCount:int,acks:list<array<string,mixed>>} */
    private function verifyMembershipAcks(array $proposal,array $acks,string $phase): array
    {
        $p=(array)$proposal['payload'];$digest=$this->documentDigest($proposal);$old=array_fill_keys((array)$p['oldVoters'],true);$new=array_fill_keys((array)$p['newVoters'],true);$members=(array)$p['oldMembers']+(array)$p['newMembers'];$seen=[];$valid=[];$oc=0;$nc=0;
        foreach($acks as $ack){if(!is_array($ack))continue;$ap=(array)($ack['payload']??[]);$v=(string)($ap['voterInstanceId']??'');if($v===''||isset($seen[$v]))continue;$this->verifyIdentitySigned($ack,$members,$v,'easyit.assistant.trust-membership-ack.v1');if(($ap['phase']??'')!==$phase||($ap['transitionId']??'')!==($proposal['transitionId']??'')||!hash_equals((string)($ap['proposalDigest']??''),$digest))continue;$seen[$v]=true;$valid[]=$ack;if(isset($old[$v]))$oc++;if(isset($new[$v]))$nc++;}
        if($oc<(int)$p['oldQuorum']||$nc<(int)$p['newQuorum'])throw new \RuntimeException('Joint-Consensus blockiert: PREPARE benötigt altes Quorum '.(int)$p['oldQuorum'].' und neues Quorum '.(int)$p['newQuorum'].'; vorhanden old='.$oc.', new='.$nc.'.');
        return ['oldCount'=>$oc,'newCount'=>$nc,'uniqueCount'=>count($valid),'acks'=>$valid];
    }

    /** @return array{oldCount:int,newCount:int,uniqueCount:int,acks:list<array<string,mixed>>} */
    private function verifyCommitAcks(array $joint,array $acks): array
    {
        $p=(array)$joint['payload'];$old=array_fill_keys((array)$p['oldVoters'],true);$new=array_fill_keys((array)$p['newVoters'],true);$members=(array)$p['oldMembers']+(array)$p['newMembers'];$seen=[];$valid=[];$oc=0;$nc=0;$jointDigest=$this->documentDigest($joint);
        foreach($acks as $ack){if(!is_array($ack))continue;$ap=(array)($ack['payload']??[]);$v=(string)($ap['voterInstanceId']??'');if($v===''||isset($seen[$v]))continue;$this->verifyIdentitySigned($ack,$members,$v,'easyit.assistant.trust-membership-ack.v1');if(($ap['phase']??'')!=='COMMIT'||($ap['transitionId']??'')!==($joint['transitionId']??'')||!hash_equals((string)($ap['jointDigest']??''),$jointDigest))continue;$seen[$v]=true;$valid[]=$ack;if(isset($old[$v]))$oc++;if(isset($new[$v]))$nc++;}
        if($oc<(int)$p['oldQuorum']||$nc<(int)$p['newQuorum'])throw new \RuntimeException('Finalisierung blockiert: COMMIT benötigt altes Quorum '.(int)$p['oldQuorum'].' und neues Quorum '.(int)$p['newQuorum'].'; vorhanden old='.$oc.', new='.$nc.'.');
        return ['oldCount'=>$oc,'newCount'=>$nc,'uniqueCount'=>count($valid),'acks'=>$valid];
    }

    private function verifyProposal(array $proposal): void
    {
        $p=(array)($proposal['payload']??[]);if(($p['schema']??'')!=='easyit.assistant.trust-membership-proposal.v1')throw new \RuntimeException('Membership-Proposal-Schema ist ungültig.');$expectedTransition='membership-'.(int)($p['membershipEpoch']??0).'-'.substr(hash('sha256',$this->canonical($p)),0,16);if(!hash_equals($expectedTransition,(string)($proposal['transitionId']??'')))throw new \RuntimeException('Membership-Proposal-Transition-ID ist ungültig.');$old=(array)($proposal['oldCluster']??[]);$this->verifyCluster($old);if(($p['clusterId']??'')!==($old['clusterId']??'')||!hash_equals((string)($p['oldDigest']??''),(string)($old['membershipDigest']??'')))throw new \RuntimeException('Membership-Proposal enthält eine inkonsistente alte Mitgliedschaft.');$current=$this->readJson($this->clusterPath,[]);if($current!==[]&&!hash_equals((string)($current['membershipDigest']??''),(string)$p['oldDigest']))throw new \RuntimeException('Membership-Proposal basiert nicht auf der lokal aktuellen Mitgliedschaft.');$proposer=(string)($p['proposerInstanceId']??'');$this->verifyIdentitySigned($proposal,(array)$p['oldMembers'],$proposer,'easyit.assistant.trust-membership-proposal.v1');$final=(array)($proposal['finalCluster']??[]);$this->verifyCluster($final);if(!hash_equals((string)($p['newDigest']??''),(string)($final['membershipDigest']??''))||!hash_equals((string)($p['finalClusterSha256']??''),hash('sha256',$this->canonical($final))))throw new \RuntimeException('Membership-Proposal enthält eine inkonsistente finale Clusterkonfiguration.');
    }

    private function verifyJoint(array $joint): void
    {
        $p=(array)($joint['payload']??[]);if(($p['schema']??'')!=='easyit.assistant.trust-membership-joint.v1'||empty($joint['active']))throw new \RuntimeException('Joint-Consensus-Dokument ist nicht aktiv oder besitzt ein falsches Schema.');if(!hash_equals((string)($p['transitionId']??''),(string)($joint['transitionId']??'')))throw new \RuntimeException('Joint-Consensus-Transition-ID ist inkonsistent.');$proposer='';$current=$this->readJson($this->clusterPath,[]);foreach((array)$p['oldMembers'] as $mid=>$m){if(is_array($m)&&isset($current['members'][$mid])&&($current['members'][$mid]['instanceId']??'')===($m['instanceId']??'')){/* no-op */}}$sig=(array)($joint['signature']??[]);$proposer=(string)($sig['instanceId']??'');$members=(array)$p['oldMembers']+(array)$p['newMembers'];$this->verifyIdentitySigned($joint,$members,$proposer,'easyit.assistant.trust-membership-joint.v1');$final=(array)($p['finalCluster']??[]);$this->verifyCluster($final);if(!hash_equals((string)($p['newDigest']??''),(string)($final['membershipDigest']??'')))throw new \RuntimeException('Joint-Zieldigest stimmt nicht mit finaler Clusterkonfiguration überein.');
    }

    /** @return array<string,mixed> */ private function requireCluster(): array { $c=$this->readJson($this->clusterPath,[]);if($c===[]||empty($c['enabled']))throw new \RuntimeException('Phase-29-Failover-Cluster ist noch nicht konfiguriert.');$this->verifyCluster($c);return $c; }
    private function verifyCluster(array $cluster): void
    {
        if(($cluster['schema']??'')!=='easyit.assistant.trust-failover-cluster.v1')throw new \RuntimeException('Failover-Cluster-Schema ist ungültig.');$members=(array)($cluster['members']??[]);$voters=$this->voterIds($members);if(count($voters)<3)throw new \RuntimeException('Failover-Cluster benötigt mindestens drei Voting-Mitglieder.');$expected=$this->quorum(count($voters));if((int)($cluster['quorum']??0)!==$expected)throw new \RuntimeException('Cluster-Quorum ist inkonsistent.');$mp=['schema'=>'easyit.assistant.trust-failover-membership.v1','clusterId'=>$cluster['clusterId'],'members'=>$members,'quorum'=>$expected,'leaseSeconds'=>(int)$cluster['leaseSeconds'],'heartbeatTimeoutSeconds'=>(int)$cluster['heartbeatTimeoutSeconds'],'leaseGraceSeconds'=>(int)$cluster['leaseGraceSeconds']];$digest=hash('sha256',$this->canonical($mp));if(!hash_equals($digest,(string)($cluster['membershipDigest']??'')))throw new \RuntimeException('Cluster-Mitgliedschafts-Digest ist ungültig.');$payload=(array)($cluster['payload']??[]);if(!hash_equals($digest,(string)($payload['membershipDigest']??'')))throw new \RuntimeException('Signierter Cluster-Payload enthält falschen Membership-Digest.');$sig=(array)($cluster['signature']??[]);$v=$this->trust->verifyForPurpose($this->canonical($payload),(string)($sig['signature']??''),(string)($sig['keyId']??''),'install');if(empty($v['ok']))throw new \RuntimeException('Cluster-Signatur ist nicht vertrauenswürdig: '.implode(' ',(array)($v['errors']??[])));
    }

    /** @return string */ private function verifyIdentityBundle(array $bundle): string { $p=(array)($bundle['payload']??[]);if(($p['schema']??'')!=='easyit.assistant.trust-failover-identity.v1')throw new \RuntimeException('Identity-Bundle-Schema ist ungültig.');$id=(string)($p['instanceId']??'');$this->verifyIdentitySigned($bundle,[$id=>['publicKey'=>(string)($p['publicKey']??'')]],$id,'easyit.assistant.trust-failover-identity.v1',true);return $id; }
    /** @return array<string,mixed> */ private function signIdentity(array $payload): array { $this->assertSodium();$identity=$this->readJson($this->identityPath,[]);$ip=(array)($identity['payload']??[]);$id=(string)($ip['instanceId']??'');if($id==='')throw new \RuntimeException('Lokale Phase-29-Failover-Identity fehlt.');$path=$this->privateDir.'/'.$this->safe($id).'.key';$sec=base64_decode(trim((string)@file_get_contents($path)),true);if($sec===false||strlen($sec)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new \RuntimeException('Privater Failover-Identity-Key fehlt oder ist ungültig.');$sig=sodium_crypto_sign_detached($this->canonical($payload),$sec);return ['schema'=>'easyit.assistant.trust-membership-signed-document.v1','payload'=>$payload,'signature'=>['instanceId'=>$id,'algorithm'=>'Ed25519','signature'=>base64_encode($sig)]]; }
    private function verifyIdentitySigned(array $doc,array $members,string $subject,string $schema,bool $self=false): void { $this->assertSodium();$p=(array)($doc['payload']??[]);if(($p['schema']??'')!==$schema)throw new \RuntimeException('Signiertes Membership-Dokument besitzt ein falsches Schema.');$m=$members[$subject]??null;if(!is_array($m))throw new \RuntimeException('Signatur-Subjekt ist kein bekanntes Mitglied: '.$subject);$s=(array)($doc['signature']??[]);if($self){$s=['instanceId'=>$subject,'signature'=>$doc['selfSignature']??''];}if(($s['instanceId']??'')!==$subject)throw new \RuntimeException('Signatur-Instanz stimmt nicht mit dem Subjekt überein.');$pub=base64_decode((string)($m['publicKey']??''),true);$sig=base64_decode((string)($s['signature']??''),true);if($pub===false||$sig===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($sig,$this->canonical($p),$pub))throw new \RuntimeException('Membership-Identity-Signatur ist ungültig.'); }
    /** @return list<string> */ private function voterIds(array $members): array { $r=[];foreach($members as $id=>$m)if(is_array($m)&&!empty($m['voting']))$r[]=(string)$id;sort($r,SORT_STRING);return $r; }
    private function quorum(int $n): int { return intdiv($n,2)+1; }
    /** @return array<string,mixed> */ private function requireLocal(): array { $l=$this->readJson($this->localPath,[]);if($l===[])throw new \RuntimeException('Phase-28-Trust-Instanz ist noch nicht initialisiert.');return $l; }
    /** @return array<string,mixed> */ private function identityPublic(): array { $i=$this->readJson($this->identityPath,[]);return is_array($i['payload']??null)?(array)$i['payload']:[]; }
    private function jointActive(): bool { $j=$this->readJson($this->jointPath,[]);return !empty($j['active']); }
    private function documentDigest(array $d): string { $copy=$d;unset($copy['path']);return hash('sha256',$this->canonical($copy)); }
    private function archiveCurrent(string $name): void { $c=$this->readJson($this->clusterPath,[]);if($c===[])return;$this->ensureDir($this->historyDir);$this->atomicJson($this->historyDir.'/'.$this->safe($name).'-'.gmdate('Ymd-His').'.cluster.json',$c,0640); }
    private function writeArtifact(string $kind,string $suffix,array $data): array { $this->ensureDir($this->outbox);$file='easyit-'.$kind.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'-'.$suffix;$path=$this->outbox.'/'.$file;$this->atomicJson($path,$data,0640);return ['schema'=>'easyit.assistant.trust-membership-artifact.v1','ok'=>true,'verdict'=>'PASS','kind'=>$kind,'file'=>$file,'path'=>$path,'sha256'=>hash_file('sha256',$path),'data'=>$data]; }
    private function audit(string $action,string $instanceId,array $details=[]): void { $this->ensureDir($this->base);$prev='';if(is_file($this->auditPath)){$lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$last=end($lines);if(is_string($last)){$e=json_decode($last,true);if(is_array($e))$prev=(string)($e['eventHash']??'');}}$e=['schema'=>'easyit.assistant.trust-membership-audit-event.v1','eventId'=>'membership-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)),'action'=>$action,'instanceId'=>$instanceId,'occurredAt'=>gmdate('c'),'previousHash'=>$prev,'details'=>$details];$e['eventHash']=hash('sha256',$this->canonical($e));file_put_contents($this->auditPath,json_encode($e,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);@chmod($this->auditPath,0640); }
    /** @return array<string,mixed> */ private function readJson(string $path,array $default): array { if(!is_file($path))return $default;$d=json_decode((string)@file_get_contents($path),true);return is_array($d)?$d:$default; }
    private function atomicJson(string $path,array $data,int $mode): void { $this->ensureDir(dirname($path));$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Datei kann nicht atomar gespeichert werden: '.$path);}@chmod($path,$mode); }
    private function ensureDir(string $dir): void { if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis kann nicht erstellt werden: '.$dir); }
    private function safe(string $s): string { return trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',trim($s)),'-')?:'item'; }
    private function assertSodium(): void { if(!function_exists('sodium_crypto_sign_verify_detached'))throw new \RuntimeException('Phase 30 benötigt PHP ext-sodium.'); }
    /** @param mixed $v */ private function normalize($v){if(!is_array($v))return $v;if(array_is_list($v))return array_map(fn($x)=>$this->normalize($x),$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$this->normalize($x);return $v;}
    private function canonical(array $d): string { return json_encode($this->normalize($d),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
}
