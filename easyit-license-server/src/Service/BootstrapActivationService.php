<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use EasyIT\LicenseServer\Support\Id;
use PDO;

/**
 * HF116: one-time bootstrap activation for a freshly installed Enterprise Manager.
 *
 * The bootstrap activation is deliberately NOT a customer license. It only binds
 * an installation key to the license server. As soon as a customer with an active
 * license logs in from that installation, the installation is rebound to the
 * customer's real license.
 */
final class BootstrapActivationService
{
    public const LICENSE_ID='LICID_BOOTSTRAP_MANAGER';
    public const LICENSE_NUMBER='BOOTSTRAP-MANAGER';
    public const CUSTOMER_ID='__SYSTEM_BOOTSTRAP__';

    public function __construct(private PDO $pdo, private AuditService $audit) {}

    public static function isBootstrapLicense(array|string $license): bool
    {
        if(is_array($license)){
            return (string)($license['license_id']??'')===self::LICENSE_ID
                || (string)($license['customer_id']??'')===self::CUSTOMER_ID
                || (string)($license['license_number']??'')===self::LICENSE_NUMBER;
        }
        return $license===self::LICENSE_ID;
    }

    /** Issue a one-time bootstrap activation key. */
    public function issue(string $adminId, ?string $currentInstallationId=null): string
    {
        $secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $hash=password_hash($secret,PASSWORD_DEFAULT);$now=time();
        $this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('SELECT license_id FROM licenses WHERE license_id=? OR license_number=? LIMIT 1');
            $st->execute([self::LICENSE_ID,self::LICENSE_NUMBER]);$exists=$st->fetch();
            if($exists){
                $this->pdo->prepare('UPDATE licenses SET license_number=?,activation_secret_hash=?,customer_id=?,status=?,valid_from=?,valid_until=NULL,max_installations=1,lease_seconds=300,grace_seconds=300,generation=generation+1,updated_at=? WHERE license_id=?')
                    ->execute([self::LICENSE_NUMBER,$hash,self::CUSTOMER_ID,'active',$now-60,$now,self::LICENSE_ID]);
            }else{
                $this->pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([self::LICENSE_ID,self::LICENSE_NUMBER,$hash,self::CUSTOMER_ID,'active',$now-60,null,1,300,300,1,$now,$now]);
            }
            // HF143: A bootstrap slot is only a temporary bridge until a manager is bound
            // to a real customer license. A previous, still-unbound local bootstrap must
            // therefore not permanently consume the single bootstrap slot after a local
            // reinstall. Only other BOOTSTRAP-MANAGER installations are replaced here;
            // customer-license installations are never touched.
            $currentInstallationId=trim((string)$currentInstallationId);
            if($currentInstallationId!==''){
                $old=$this->pdo->prepare("SELECT installation_id FROM installations WHERE license_id=? AND installation_id<>? AND status IN ('active','blocked')");
                $old->execute([self::LICENSE_ID,$currentInstallationId]);
                $oldIds=array_map(static fn(array $r):string=>(string)$r['installation_id'],$old->fetchAll());
                foreach($oldIds as $oldId){
                    $this->pdo->prepare("UPDATE runtime_sessions SET status='revoked',last_seen_at=? WHERE installation_id=? AND status='active'")->execute([$now,$oldId]);
                    $this->pdo->prepare("UPDATE installation_keys SET status='revoked',valid_until=COALESCE(valid_until,?) WHERE installation_id=? AND status='active'")->execute([$now,$oldId]);
                    $this->pdo->prepare("UPDATE installations SET status='replaced',generation=generation+1,updated_at=? WHERE installation_id=? AND license_id=? AND status IN ('active','blocked')")->execute([$now,$oldId,self::LICENSE_ID]);
                    $this->audit->write('BOOTSTRAP_INSTALLATION_REPLACED','installation',$oldId,'admin',$adminId,null,['license_id'=>self::LICENSE_ID],['replacement_installation_id'=>$currentInstallationId]);
                }
            }

            // Bootstrap license intentionally has no product/module rights.
            $this->pdo->prepare('DELETE FROM license_modules WHERE license_id=?')->execute([self::LICENSE_ID]);
            $this->pdo->prepare('DELETE FROM license_capabilities WHERE license_id=?')->execute([self::LICENSE_ID]);
            $pol=$this->pdo->prepare('SELECT license_id FROM license_policies WHERE license_id=?');$pol->execute([self::LICENSE_ID]);
            if($pol->fetch())$this->pdo->prepare('UPDATE license_policies SET max_dataforms=0,updated_at=? WHERE license_id=?')->execute([$now,self::LICENSE_ID]);
            else $this->pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)')->execute([self::LICENSE_ID,0,$now,$now]);
            $this->audit->write('BOOTSTRAP_ACTIVATION_KEY_ISSUED','license',self::LICENSE_ID,'admin',$adminId,null,[],['one_time'=>true]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return self::LICENSE_NUMBER.'.'.$secret;
    }

    /** Invalidate the bootstrap secret immediately after first successful use. */
    public function consume(string $installationId): void
    {
        $now=time();$dead=password_hash(rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'='),PASSWORD_DEFAULT);
        $this->pdo->prepare('UPDATE licenses SET activation_secret_hash=?,generation=generation+1,updated_at=? WHERE license_id=?')
            ->execute([$dead,$now,self::LICENSE_ID]);
        $this->audit->write('BOOTSTRAP_ACTIVATION_KEY_CONSUMED','installation',$installationId,'installation',$installationId,null,[],['license_id'=>self::LICENSE_ID]);
    }

    /**
     * Bind a bootstrap-activated installation to the real customer license.
     * Returns a status object; a different non-bootstrap license is never silently replaced.
     */
    public function bindInstallation(string $installationId,string $targetLicenseId,string $customerId,string $actorId): array
    {
        if($installationId==='')return ['status'=>'installation_missing'];
        $st=$this->pdo->prepare('SELECT * FROM installations WHERE installation_id=? LIMIT 1');$st->execute([$installationId]);$inst=$st->fetch();
        if(!$inst)return ['status'=>'installation_unknown'];
        $current=(string)$inst['license_id'];
        if($current===$targetLicenseId)return ['status'=>'already_bound','installation_id'=>$installationId,'license_id'=>$targetLicenseId];
        if($current!==self::LICENSE_ID)return ['status'=>'different_license','installation_id'=>$installationId,'license_id'=>$current];

        $lic=$this->pdo->prepare('SELECT * FROM licenses WHERE license_id=? AND customer_id=? LIMIT 1');$lic->execute([$targetLicenseId,$customerId]);$target=$lic->fetch();
        if(!$target)return ['status'=>'target_license_invalid'];
        $count=$this->pdo->prepare("SELECT COUNT(*) c FROM installations WHERE license_id=? AND installation_id<>? AND status IN ('active','blocked')");
        $count->execute([$targetLicenseId,$installationId]);$used=(int)($count->fetch()['c']??0);
        if($used>=(int)$target['max_installations'])return ['status'=>'installation_limit_reached','used'=>$used,'max'=>(int)$target['max_installations']];

        $now=time();$this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('UPDATE installations SET license_id=?,generation=generation+1,last_seen_at=?,updated_at=? WHERE installation_id=? AND license_id=?')
                ->execute([$targetLicenseId,$now,$now,$installationId,self::LICENSE_ID]);
            $ae=$this->pdo->prepare('INSERT INTO activation_events (activation_event_id,installation_id,license_id,event_type,request_id,created_at) VALUES (?,?,?,?,?,?)');
            $ae->execute([Id::make('AE'),$installationId,$targetLicenseId,'customer_license_bound',null,$now]);
            $this->audit->write('BOOTSTRAP_INSTALLATION_BOUND','installation',$installationId,'customer_user',$actorId,null,['license_id'=>self::LICENSE_ID],['license_id'=>$targetLicenseId,'customer_id'=>$customerId]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['status'=>'bound','installation_id'=>$installationId,'license_id'=>$targetLicenseId];
    }
}
