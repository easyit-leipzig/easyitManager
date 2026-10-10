<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use PDO;

/**
 * HF85 Phase 1-4: zentrale DataForm-Registrierung, manuelle Freigabe, Token-Ausgabe und kontrollierte Freigabe beim Loeschen.
 * Registrierung startet als "pending". Erst die ausdrueckliche Superadmin-
 * Freigabe belegt einen DataForm-Lizenzplatz und erlaubt die Ausgabe eines signierten DF-Tokens.
 */
final class DataFormRegistryService
{
    public function __construct(
        private PDO $pdo,
        private LicenseService $licenses,
        private AuditService $audit,
        private SurfacePolicyService $surfacePolicy,
        private DataFormTokenService $tokens
    ) {}

    public function register(array $installation, array $payload, string $requestId): array
    {
        $license=$this->licenses->byId((string)$installation['license_id']);
        $licenseId=(string)$license['license_id'];
        $this->licenses->requireModule($licenseId,'dataform');
        $this->surfacePolicy->assertForLicense($licenseId,$payload);

        $uuid=strtolower(trim((string)($payload['dataform_uuid']??'')));
        $projectId=trim((string)($payload['project_id']??''));
        $localId=trim((string)($payload['local_dataform_id']??''));
        $name=trim((string)($payload['name']??''));
        $slug=trim((string)($payload['slug']??''));
        $name=trim((string)($payload['name']??''));
        $slug=trim((string)($payload['slug']??''));
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$uuid)) {
            throw new \RuntimeException('DATAFORM_UUID_INVALID');
        }
        if($projectId===''||$localId===''||$name===''||$slug==='') throw new \RuntimeException('REQUEST_INVALID');
        if(strlen($projectId)>100||strlen($localId)>100||strlen($name)>255||strlen($slug)>255) throw new \RuntimeException('REQUEST_INVALID');

        $customerId=(string)($license['customer_id']??'');
        $installationId=(string)($installation['installation_id']??'');
        $now=time();

        $this->pdo->beginTransaction();
        try {
            $byUuid=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE dataform_uuid=? LIMIT 1');
            $byUuid->execute([$uuid]);
            $existing=$byUuid->fetch();
            if($existing && (string)$existing['license_id']!==$licenseId) {
                throw new \RuntimeException('DATAFORM_IDENTITY_CONFLICT');
            }

            $byLocal=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE license_id=? AND project_id=? AND local_dataform_id=? LIMIT 1');
            $byLocal->execute([$licenseId,$projectId,$localId]);
            $localExisting=$byLocal->fetch();
            if($localExisting && (string)$localExisting['dataform_uuid']!==$uuid) {
                throw new \RuntimeException('DATAFORM_IDENTITY_CONFLICT');
            }

            if($existing) {
                $status=(string)$existing['status'];
                if(in_array($status,['revoked','released'],true)) {
                    throw new \RuntimeException('DATAFORM_REGISTRATION_REVOKED');
                }
                // Ab HF85 Phase 3 ist die bei der Erstregistrierung verwendete Installation
                // Bestandteil der unveraenderlichen DataForm-Lizenzidentitaet.
                if((string)$existing['installation_id']!==$installationId) {
                    throw new \RuntimeException('DATAFORM_INSTALLATION_MISMATCH');
                }
                $up=$this->pdo->prepare('UPDATE license_dataforms SET customer_id=?,name=?,slug=?,last_seen_at=? WHERE dataform_uuid=?');
                $up->execute([$customerId,$name,$slug,$now,$uuid]);
                $created=false;
            } else {
                $status='pending';
                $ins=$this->pdo->prepare("INSERT INTO license_dataforms (dataform_uuid,license_id,customer_id,installation_id,project_id,local_dataform_id,name,slug,status,registered_at,last_seen_at,approved_at,revoked_at) VALUES (?,?,?,?,?,?,?,?, 'pending', ?, ?, NULL, NULL)");
                $ins->execute([$uuid,$licenseId,$customerId,$installationId,$projectId,$localId,$name,$slug,$now,$now]);
                $created=true;
                $this->audit->write('DATAFORM_REGISTERED','dataform',$uuid,'installation',$installationId,$requestId,[],[
                    'license_id'=>$licenseId,'project_id'=>$projectId,'local_dataform_id'=>$localId,'status'=>'pending'
                ]);
            }
            $this->pdo->commit();
        } catch(\Throwable $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        $out=[
            'dataform_uuid'=>$uuid,
            'status'=>$status,
            'registered'=>$created,
            'consumes_license_slot'=>$status==='approved',
            'license_policy'=>$this->licenses->policySummary($licenseId),
        ];
        if($status==='approved') {
            $st=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE dataform_uuid=? LIMIT 1');
            $st->execute([$uuid]);
            $approved=$st->fetch();
            if(!$approved) throw new \RuntimeException('DATAFORM_REGISTRATION_NOT_FOUND');
            $token=$this->tokens->issueForRow($approved);
            $out['df_license_token']=$token['token'];
            $out['df_license_token_key_id']=$token['key_id'];
            $out['df_license_token_issued_at']=$token['issued_at'];
            $out['df_license_token_hash']=$token['token_hash'];
        }
        return $out;
    }
    /**
     * HF85 Phase 2: gibt genau ein registriertes DataForm manuell frei.
     * claimDataform() ist idempotent; ein aus Phase 1 bereits vorhandener
     * Legacy-Claim wird dadurch nicht doppelt gezaehlt.
     */
    public function approve(string $dataformUuid, string $adminId): array
    {
        $uuid=strtolower(trim($dataformUuid));
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$uuid)) {
            throw new \RuntimeException('DATAFORM_UUID_INVALID');
        }
        $this->pdo->beginTransaction();
        try {
            $st=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE dataform_uuid=? LIMIT 1 FOR UPDATE');
            $st->execute([$uuid]);
            $row=$st->fetch();
            if(!$row) throw new \RuntimeException('DATAFORM_REGISTRATION_NOT_FOUND');
            $status=(string)$row['status'];
            if($status==='approved') {
                $usage=$this->licenses->claimDataform((string)$row['license_id'],(string)$row['project_id'],(string)$row['local_dataform_id']);
                $this->pdo->commit();
                return ['dataform_uuid'=>$uuid,'status'=>'approved','already_approved'=>true,'license_policy'=>$usage];
            }
            if($status!=='pending') throw new \RuntimeException('DATAFORM_STATUS_NOT_PENDING');

            // Die Lizenz muss im Moment der manuellen Freigabe verwendbar sein.
            $this->licenses->byId((string)$row['license_id']);
            $this->licenses->requireModule((string)$row['license_id'],'dataform');
            $usage=$this->licenses->claimDataform((string)$row['license_id'],(string)$row['project_id'],(string)$row['local_dataform_id']);
            $now=time();
            $up=$this->pdo->prepare("UPDATE license_dataforms SET status='approved',approved_at=?,revoked_at=NULL,last_seen_at=? WHERE dataform_uuid=? AND status='pending'");
            $up->execute([$now,$now,$uuid]);
            if($up->rowCount()!==1) throw new \RuntimeException('DATAFORM_STATUS_CONFLICT');
            $this->pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,(string)$row['license_id']]);
            $this->audit->write('DATAFORM_LICENSE_APPROVED','dataform',$uuid,'admin',$adminId,null,['status'=>'pending'],[
                'status'=>'approved','license_id'=>(string)$row['license_id'],'project_id'=>(string)$row['project_id'],
                'local_dataform_id'=>(string)$row['local_dataform_id'],'slot_claimed'=>(bool)($usage['claimed']??false)
            ]);
            $this->pdo->commit();
            return ['dataform_uuid'=>$uuid,'status'=>'approved','already_approved'=>false,'license_policy'=>$usage];
        } catch(\Throwable $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** HF85 Phase 2: lehnt eine offene Registrierung ab und gibt einen evtl. Legacy-Claim frei. */
    public function reject(string $dataformUuid, string $adminId, string $note=''): array
    {
        $uuid=strtolower(trim($dataformUuid));
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$uuid)) {
            throw new \RuntimeException('DATAFORM_UUID_INVALID');
        }
        $this->pdo->beginTransaction();
        try {
            $st=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE dataform_uuid=? LIMIT 1 FOR UPDATE');
            $st->execute([$uuid]);
            $row=$st->fetch();
            if(!$row) throw new \RuntimeException('DATAFORM_REGISTRATION_NOT_FOUND');
            $status=(string)$row['status'];
            if($status==='rejected') {
                $this->pdo->commit();
                return ['dataform_uuid'=>$uuid,'status'=>'rejected','already_rejected'=>true,'license_policy'=>$this->licenses->policySummary((string)$row['license_id'])];
            }
            if($status!=='pending') throw new \RuntimeException('DATAFORM_STATUS_NOT_PENDING');

            // Phase-1-Uebergang: ein bereits durch die Legacy-Runtime belegter Platz
            // muss bei Ablehnung wieder frei werden.
            $released=$this->licenses->releaseDataform((string)$row['license_id'],(string)$row['project_id'],(string)$row['local_dataform_id']);
            $now=time();
            $up=$this->pdo->prepare("UPDATE license_dataforms SET status='rejected',last_seen_at=? WHERE dataform_uuid=? AND status='pending'");
            $up->execute([$now,$uuid]);
            if($up->rowCount()!==1) throw new \RuntimeException('DATAFORM_STATUS_CONFLICT');
            $this->pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,(string)$row['license_id']]);
            $new=['status'=>'rejected','license_id'=>(string)$row['license_id'],'project_id'=>(string)$row['project_id'],
                'local_dataform_id'=>(string)$row['local_dataform_id'],'legacy_slot_released'=>$released];
            if(trim($note)!=='') $new['decision_note']=substr(trim($note),0,1000);
            $this->audit->write('DATAFORM_LICENSE_REJECTED','dataform',$uuid,'admin',$adminId,null,['status'=>'pending'],$new);
            $this->pdo->commit();
            return ['dataform_uuid'=>$uuid,'status'=>'rejected','already_rejected'=>false,'license_policy'=>$this->licenses->policySummary((string)$row['license_id'])];
        } catch(\Throwable $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }


    /** HF86 Phase 2: liefert die aktuell wirksame Capability-Menge fuer ein freigegebenes DataForm. */
    public function policy(array $installation, array $payload): array
    {
        $licenseId=(string)($installation['license_id']??'');
        $projectId=trim((string)($payload['project_id']??''));
        $localId=trim((string)($payload['dataform_id']??$payload['local_dataform_id']??''));
        if($licenseId===''||$projectId===''||$localId==='') throw new \RuntimeException('REQUEST_INVALID');
        $license=$this->licenses->byId($licenseId);
        $this->licenses->requireModule($licenseId,'dataform');
        $this->surfacePolicy->assertForLicense($licenseId,$payload);
        $tokenAuth=$this->tokens->assertApproved($installation,$payload,$projectId,$localId);
        $row=(array)$tokenAuth['row'];
        $policy=$this->licenses->resolvedDataformPolicy($licenseId,$projectId,$localId);
        return $policy+[
            'dataform_uuid'=>(string)$row['dataform_uuid'],
            'status'=>(string)$row['status'],
            'project_id'=>$projectId,
            'dataform_id'=>$localId,
            'license_generation'=>(int)($license['generation']??0),
        ];
    }

    /**
     * HF85 Phase 4: loest eine lokale DataForm-Identitaet kontrolliert aus der Lizenz.
     * Die Operation ist idempotent: ein bereits released DataForm kann erneut bestaetigt
     * werden, ohne einen zweiten Platz freizugeben oder die Identitaet neu zu verwenden.
     */
    public function release(array $installation, array $payload, string $requestId): array
    {
        $licenseId=(string)($installation['license_id']??'');
        $installationId=(string)($installation['installation_id']??'');
        $uuid=strtolower(trim((string)($payload['dataform_uuid']??'')));
        $projectId=trim((string)($payload['project_id']??''));
        $localId=trim((string)($payload['local_dataform_id']??''));
        $name=trim((string)($payload['name']??''));
        $slug=trim((string)($payload['slug']??''));
        if(!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',$uuid)) {
            throw new \RuntimeException('DATAFORM_UUID_INVALID');
        }
        if($licenseId===''||$installationId===''||$projectId===''||$localId==='') throw new \RuntimeException('REQUEST_INVALID');

        // Release is a cleanup operation. It remains idempotently available even if
        // the commercial license becomes suspended/expired between retries; the
        // signed active installation identity and exact registry binding still apply.
        $this->surfacePolicy->assertForLicense($licenseId,$payload);
        $now=time();
        $this->pdo->beginTransaction();
        try {
            $st=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE dataform_uuid=? LIMIT 1 FOR UPDATE');
            $st->execute([$uuid]);
            $row=$st->fetch();
            if(!$row) {
                $local=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE license_id=? AND project_id=? AND local_dataform_id=? LIMIT 1 FOR UPDATE');
                $local->execute([$licenseId,$projectId,$localId]);
                $localRow=$local->fetch();
                if($localRow) throw new \RuntimeException('DATAFORM_IDENTITY_CONFLICT');
                if($name===''||$slug===''||strlen($name)>255||strlen($slug)>255) throw new \RuntimeException('REQUEST_INVALID');
                $lic=$this->pdo->prepare('SELECT customer_id FROM licenses WHERE license_id=? LIMIT 1');
                $lic->execute([$licenseId]);
                $licenseRow=$lic->fetch();
                if(!$licenseRow) throw new \RuntimeException('LICENSE_INVALID');
                $slotReleased=$this->licenses->releaseDataform($licenseId,$projectId,$localId);
                $sessions=$this->pdo->prepare("UPDATE runtime_sessions SET status='revoked',last_seen_at=? WHERE license_id=? AND installation_id=? AND project_id=? AND dataform_id=? AND status='active'");
                $sessions->execute([$now,$licenseId,$installationId,$projectId,$localId]);
                $ins=$this->pdo->prepare("INSERT INTO license_dataforms (dataform_uuid,license_id,customer_id,installation_id,project_id,local_dataform_id,name,slug,status,registered_at,last_seen_at,approved_at,revoked_at,released_at,release_request_id) VALUES (?,?,?,?,?,?,?,?, 'released', ?, ?, NULL, NULL, ?, ?)");
                $ins->execute([$uuid,$licenseId,(string)$licenseRow['customer_id'],$installationId,$projectId,$localId,$name,$slug,$now,$now,$now,$requestId!==''?$requestId:null]);
                $this->audit->write('DATAFORM_RELEASED','dataform',$uuid,'installation',$installationId,$requestId,['status'=>'unregistered'],[
                    'status'=>'released','license_id'=>$licenseId,'project_id'=>$projectId,'local_dataform_id'=>$localId,
                    'slot_released'=>$slotReleased,'release_tombstone_created'=>true,'runtime_sessions_revoked'=>$sessions->rowCount()
                ]);
                $this->pdo->commit();
                return [
                    'dataform_uuid'=>$uuid,'status'=>'released','already_released'=>false,'slot_released'=>$slotReleased,
                    'released_at'=>$now,'release_tombstone_created'=>true,'license_policy'=>$this->licenses->policySummary($licenseId),
                ];
            }
            if((string)$row['license_id']!==$licenseId) throw new \RuntimeException('DATAFORM_TOKEN_BINDING_INVALID');
            if((string)$row['installation_id']!==$installationId) throw new \RuntimeException('DATAFORM_INSTALLATION_MISMATCH');
            if((string)$row['project_id']!==$projectId||(string)$row['local_dataform_id']!==$localId) {
                throw new \RuntimeException('DATAFORM_TOKEN_BINDING_INVALID');
            }

            $status=(string)$row['status'];
            if($status==='released') {
                // Repair any inconsistent residue without consuming or releasing twice.
                $slotReleased=$this->licenses->releaseDataform($licenseId,$projectId,$localId);
                $sessions=$this->pdo->prepare("UPDATE runtime_sessions SET status='revoked',last_seen_at=? WHERE license_id=? AND installation_id=? AND project_id=? AND dataform_id=? AND status='active'");
                $sessions->execute([$now,$licenseId,$installationId,$projectId,$localId]);
                $releasedAt=(int)($row['released_at']??0);
                if($releasedAt<=0) {
                    $releasedAt=$now;
                    $this->pdo->prepare('UPDATE license_dataforms SET released_at=?,release_request_id=?,last_seen_at=? WHERE dataform_uuid=?')->execute([$now,$requestId!==''?$requestId:null,$now,$uuid]);
                }
                $this->pdo->commit();
                return [
                    'dataform_uuid'=>$uuid,
                    'status'=>'released',
                    'already_released'=>true,
                    'slot_released'=>$slotReleased,
                    'released_at'=>$releasedAt,
                    'license_policy'=>$this->licenses->policySummary($licenseId),
                ];
            }

            $slotReleased=$this->licenses->releaseDataform($licenseId,$projectId,$localId);
            $up=$this->pdo->prepare("UPDATE license_dataforms SET status='released',released_at=?,release_request_id=?,last_seen_at=? WHERE dataform_uuid=? AND status<>'released'");
            $up->execute([$now,$requestId!==''?$requestId:null,$now,$uuid]);
            if($up->rowCount()!==1) throw new \RuntimeException('DATAFORM_STATUS_CONFLICT');

            // Existing online sessions for exactly this DataForm must no longer be renewable/actionable.
            $sessions=$this->pdo->prepare("UPDATE runtime_sessions SET status='revoked',last_seen_at=? WHERE license_id=? AND installation_id=? AND project_id=? AND dataform_id=? AND status='active'");
            $sessions->execute([$now,$licenseId,$installationId,$projectId,$localId]);

            $this->audit->write('DATAFORM_RELEASED','dataform',$uuid,'installation',$installationId,$requestId,[
                'status'=>$status,
            ],[
                'status'=>'released',
                'license_id'=>$licenseId,
                'project_id'=>$projectId,
                'local_dataform_id'=>$localId,
                'slot_released'=>$slotReleased,
                'runtime_sessions_revoked'=>$sessions->rowCount(),
            ]);
            $this->pdo->commit();
            return [
                'dataform_uuid'=>$uuid,
                'status'=>'released',
                'already_released'=>false,
                'slot_released'=>$slotReleased,
                'released_at'=>$now,
                'license_policy'=>$this->licenses->policySummary($licenseId),
            ];
        } catch(\Throwable $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

}
