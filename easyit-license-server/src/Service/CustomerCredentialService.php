<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use PDO;

final class CustomerCredentialService
{
    public function __construct(private PDO $pdo, private AuditService $audit) {}

    public function changePassword(string $customerId,string $userId,string $currentPassword,string $newPassword,string $confirmation,string $actorId): int
    {
        if($newPassword!==$confirmation) throw new \RuntimeException('ACCOUNT_PASSWORD_MISMATCH');
        $this->assertPassword($newPassword);
        $st=$this->pdo->prepare('SELECT password_hash FROM customer_users WHERE customer_user_id=? AND customer_id=? LIMIT 1');
        $st->execute([$userId,$customerId]);$row=$st->fetch();
        if(!$row||!password_verify($currentPassword,(string)$row['password_hash'])) throw new \RuntimeException('ACCOUNT_PASSWORD_CURRENT_INVALID');
        if(password_verify($newPassword,(string)$row['password_hash'])) throw new \RuntimeException('ACCOUNT_PASSWORD_UNCHANGED');
        return $this->replacePassword($customerId,$userId,$newPassword,'CUSTOMER_PASSWORD_CHANGED','customer_user',$actorId);
    }

    public function resetPassword(string $customerId,string $userId,string $newPassword,string $confirmation,string $actorType,string $actorId): int
    {
        if($newPassword!==$confirmation) throw new \RuntimeException('ACCOUNT_PASSWORD_MISMATCH');
        $this->assertPassword($newPassword);
        $st=$this->pdo->prepare('SELECT customer_user_id FROM customer_users WHERE customer_user_id=? AND customer_id=? LIMIT 1');
        $st->execute([$userId,$customerId]);
        if(!$st->fetch()) throw new \RuntimeException('ACCOUNT_USER_NOT_FOUND');
        return $this->replacePassword($customerId,$userId,$newPassword,'CUSTOMER_PASSWORD_RESET',$actorType,$actorId);
    }

    public function revokeSessions(string $userId,string $status='revoked'): void
    {
        $this->pdo->prepare("UPDATE customer_account_sessions SET status=?,last_seen_at=? WHERE customer_user_id=? AND status='active'")->execute([$status,time(),$userId]);
    }

    private function replacePassword(string $customerId,string $userId,string $newPassword,string $eventType,string $actorType,string $actorId): int
    {
        $now=time();$ownTx=!$this->pdo->inTransaction();if($ownTx)$this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('UPDATE customer_users SET password_hash=?,credential_version=COALESCE(credential_version,1)+1,failed_attempts=0,locked_until=NULL,updated_at=? WHERE customer_user_id=? AND customer_id=?');
            $st->execute([password_hash($newPassword,PASSWORD_DEFAULT),$now,$userId,$customerId]);
            if($st->rowCount()!==1) throw new \RuntimeException('ACCOUNT_USER_NOT_FOUND');
            $this->revokeSessions($userId);
            $q=$this->pdo->prepare('SELECT credential_version FROM customer_users WHERE customer_user_id=? AND customer_id=?');$q->execute([$userId,$customerId]);$version=(int)$q->fetchColumn();
            $this->audit->write($eventType,'customer_user',$userId,$actorType,$actorId,null,[],['credential_version'=>$version,'sessions_revoked'=>true]);
            if($ownTx)$this->pdo->commit();
            return $version;
        }catch(\Throwable $e){if($ownTx&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function assertPassword(string $password): void
    {
        if(strlen($password)<10) throw new \RuntimeException('ACCOUNT_PASSWORD_WEAK');
        if(!preg_match('/[a-z]/',$password)||!preg_match('/[A-Z]/',$password)||!preg_match('/\d/',$password)) throw new \RuntimeException('ACCOUNT_PASSWORD_WEAK');
    }
}
