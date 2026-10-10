<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use PDO;

final class InstallationLifecycleService
{
    private const TERMINAL=['retired','replaced'];
    private const KNOWN=['active','blocked','retired','replaced'];

    public function __construct(private PDO $pdo,private AuditService $audit){}

    public function transition(
        string $installationId,
        string $targetStatus,
        string $actorType,
        string $actorId,
        ?string $requestId=null,
        ?string $expectedLicenseId=null
    ): array {
        $installationId=trim($installationId);$targetStatus=trim($targetStatus);
        if($installationId==='')throw new \RuntimeException('INSTALLATION_REQUIRED');
        if(!in_array($targetStatus,self::KNOWN,true))throw new \RuntimeException('INSTALLATION_STATUS_INVALID');
        $now=time();$this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('SELECT * FROM installations WHERE installation_id=? FOR UPDATE');$st->execute([$installationId]);$row=$st->fetch();
            if(!$row)throw new \RuntimeException('INSTALLATION_NOT_FOUND');
            $licenseId=(string)$row['license_id'];
            if($expectedLicenseId!==null&&$expectedLicenseId!==''&&!hash_equals($expectedLicenseId,$licenseId))throw new \RuntimeException('INSTALLATION_LICENSE_MISMATCH');
            $current=(string)$row['status'];
            if(!in_array($current,self::KNOWN,true))throw new \RuntimeException('INSTALLATION_STATUS_INVALID');
            if(in_array($current,self::TERMINAL,true)){
                if($current!==$targetStatus)throw new \RuntimeException('INSTALLATION_TERMINAL');
                $this->pdo->commit();
                return ['installation_id'=>$installationId,'license_id'=>$licenseId,'old_status'=>$current,'status'=>$current,'changed'=>false,'terminal'=>true];
            }
            if($current===$targetStatus){
                $this->pdo->commit();
                return ['installation_id'=>$installationId,'license_id'=>$licenseId,'old_status'=>$current,'status'=>$current,'changed'=>false,'terminal'=>false];
            }
            $allowed=($current==='active'&&in_array($targetStatus,['blocked','retired','replaced'],true))||($current==='blocked'&&in_array($targetStatus,['active','retired','replaced'],true));
            if(!$allowed)throw new \RuntimeException('INSTALLATION_TRANSITION_INVALID');

            $this->pdo->prepare('UPDATE installations SET status=?,generation=generation+1,updated_at=? WHERE installation_id=?')->execute([$targetStatus,$now,$installationId]);
            $revokedSessions=0;$revokedKeys=0;
            if($targetStatus==='blocked'||in_array($targetStatus,self::TERMINAL,true)){
                $rs=$this->pdo->prepare("UPDATE runtime_sessions SET status='revoked',last_seen_at=? WHERE installation_id=? AND status='active'");$rs->execute([$now,$installationId]);$revokedSessions=$rs->rowCount();
            }
            if(in_array($targetStatus,self::TERMINAL,true)){
                $rk=$this->pdo->prepare("UPDATE installation_keys SET status='revoked',valid_until=COALESCE(valid_until,?) WHERE installation_id=? AND status='active'");$rk->execute([$now,$installationId]);$revokedKeys=$rk->rowCount();
            }
            $this->audit->write('INSTALLATION_STATUS_CHANGED','installation',$installationId,$actorType,$actorId,$requestId,['status'=>$current],['status'=>$targetStatus,'revoked_runtime_sessions'=>$revokedSessions,'revoked_installation_keys'=>$revokedKeys]);
            $this->pdo->commit();
            return ['installation_id'=>$installationId,'license_id'=>$licenseId,'old_status'=>$current,'status'=>$targetStatus,'changed'=>true,'terminal'=>in_array($targetStatus,self::TERMINAL,true),'revoked_runtime_sessions'=>$revokedSessions,'revoked_installation_keys'=>$revokedKeys];
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public static function consumesSlot(string $status): bool
    {
        return in_array($status,['active','blocked'],true);
    }
}
