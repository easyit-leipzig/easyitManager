<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;
use EasyIT\LicenseServer\Security\KeyCodec;
use EasyIT\LicenseServer\Support\Id;
use PDO;
final class ActivationService
{
 public function __construct(private PDO $pdo,private LicenseService $licenses,private AuditService $audit){}
 public function activate(array $p,string $requestId):array{
   foreach(['license_key','installation_id','installation_public_key','product'] as $k)if(!isset($p[$k]))throw new \RuntimeException('REQUEST_INVALID');
   $lic=$this->licenses->byActivationKey((string)$p['license_key']);$iid=(string)$p['installation_id'];$isBootstrap=BootstrapActivationService::isBootstrapLicense($lic);
   $pub=KeyCodec::decode((string)$p['installation_public_key']);if(strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new \RuntimeException('INSTALLATION_KEY_INVALID');
   $this->pdo->beginTransaction();
   try{
     // Serialize activation slots for this license. MySQL/MariaDB and PostgreSQL both support FOR UPDATE.
     $lock=$this->pdo->prepare('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE');$lock->execute([$lic['license_id']]);if(!$lock->fetch())throw new \RuntimeException('LICENSE_INVALID');
     $st=$this->pdo->prepare('SELECT * FROM installations WHERE installation_id=? LIMIT 1');$st->execute([$iid]);$existing=$st->fetch();
     if($existing){
       if((string)$existing['license_id']!==(string)$lic['license_id'])throw new \RuntimeException('INSTALLATION_LICENSE_MISMATCH');
       if((string)$existing['status']!=='active')throw new \RuntimeException('INSTALLATION_BLOCKED');
       // Idempotent re-activation is allowed only with the already registered active key.
       $ek=$this->pdo->prepare("SELECT public_key FROM installation_keys WHERE installation_id=? AND status='active' ORDER BY created_at DESC LIMIT 1");$ek->execute([$iid]);$known=$ek->fetch();
       if($known&& !hash_equals((string)$known['public_key'],KeyCodec::encode($pub)))throw new \RuntimeException('INSTALLATION_KEY_MISMATCH');
     }
     else{
       $cnt=$this->pdo->prepare("SELECT COUNT(*) c FROM installations WHERE license_id=? AND status IN ('active','blocked')");$cnt->execute([$lic['license_id']]);$n=(int)($cnt->fetch()['c']??0);if($n>=(int)$lic['max_installations'])throw new \RuntimeException('INSTALLATION_LIMIT_REACHED');
       $now=time();$prod=is_array($p['product'])?$p['product']:[];
       $ins=$this->pdo->prepare('INSERT INTO installations (installation_id,license_id,status,generation,product_code,product_version,environment_hash,activated_at,last_seen_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
       $ins->execute([$iid,$lic['license_id'],'active',1,(string)($prod['code']??'easyIT-enterprise'),(string)($prod['version']??''),(string)($p['environment']['hash']??''),$now,$now,$now,$now]);
     }
     $keyId='IK-'.substr(hash('sha256',$pub),0,24);$ks=$this->pdo->prepare('SELECT key_id FROM installation_keys WHERE key_id=?');$ks->execute([$keyId]);if(!$ks->fetch()){
       $ki=$this->pdo->prepare('INSERT INTO installation_keys (key_id,installation_id,algorithm,public_key,status,valid_from,valid_until,created_at) VALUES (?,?,?,?,?,?,?,?)');$ki->execute([$keyId,$iid,'Ed25519',KeyCodec::encode($pub),'active',time(),null,time()]);
     }
     $ae=$this->pdo->prepare('INSERT INTO activation_events (activation_event_id,installation_id,license_id,event_type,request_id,created_at) VALUES (?,?,?,?,?,?)');$ae->execute([Id::make('AE'),$iid,$lic['license_id'],'activated',$requestId,time()]);
     $this->audit->write('INSTALLATION_ACTIVATED','installation',$iid,'installation',$iid,$requestId,[],['license_number'=>$lic['license_number'],'activation_mode'=>$isBootstrap?'bootstrap':'license']);
     if($isBootstrap)(new BootstrapActivationService($this->pdo,$this->audit))->consume($iid);
     $this->pdo->commit();
   }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
   $st=$this->pdo->prepare('SELECT * FROM installations WHERE installation_id=?');$st->execute([$iid]);$inst=$st->fetch();
   return ['activation_mode'=>$isBootstrap?'bootstrap':'license','installation_id'=>$iid,'installation_key_id'=>$keyId,'installation_status'=>$inst['status'],'license'=>['number'=>$lic['license_number'],'status'=>$lic['status'],'generation'=>(int)$lic['generation']],'installation_generation'=>(int)$inst['generation'],'modules'=>$isBootstrap?[]:$this->licenses->modules((string)$lic['license_id']),'license_policy'=>$isBootstrap?['max_dataforms'=>0,'used_dataforms'=>0,'available_dataforms'=>0,'capability_policy'=>'explicit','capabilities'=>[],'disabled_capabilities'=>[]]:$this->licenses->policySummary((string)$lic['license_id'])];
 }
}
