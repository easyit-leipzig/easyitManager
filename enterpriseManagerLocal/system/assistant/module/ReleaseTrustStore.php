<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class ReleaseTrustStore
{
    private string $base;
    private string $privateDir;
    private string $registryPath;
    private string $policyPath;
    private string $auditPath;

    public function __construct(private string $root)
    {
        $this->root=rtrim($root,'/\\');
        $this->base=$this->root.'/storage/assistant/trust';
        $this->privateDir=$this->base.'/private';
        $this->registryPath=$this->base.'/trusted-keys.json';
        $this->policyPath=$this->base.'/policy.json';
        $this->auditPath=$this->base.'/audit.jsonl';
    }

    /** @return array<string,mixed> */
    public function status():array
    {
        $r=$this->registry();$keys=[];
        foreach((array)($r['keys']??[]) as $id=>$key){if(!is_array($key))continue;$x=$key;$x['effective']=$this->evaluateKey((string)$id,'install');$keys[]=$x;}
        return ['schema'=>'easyit.assistant.release-trust-status.v2','activeKeyId'=>$r['activeKeyId']??null,'keys'=>$keys,'rotations'=>array_values((array)($r['rotations']??[])),'policy'=>$this->policy(),'auditSummary'=>$this->auditSummary(),'sodiumAvailable'=>function_exists('sodium_crypto_sign_keypair')];
    }

    /** @return array<string,mixed> */
    public function ensureActiveKey():array
    {
        $this->assertSodium();$this->assertPrimaryInstanceForSigning();$r=$this->registry();$active=(string)($r['activeKeyId']??'');
        if($active!==''&&isset($r['keys'][$active])&&$this->canSign($active,$r)&&is_file($this->privatePath($active)))return (array)$r['keys'][$active];
        if(!empty($r['keys'])){
            $reason=$active!==''&&!is_file($this->privatePath($active))?'Der aktive private Signaturschlüssel fehlt.':'Es existiert kein verwendbarer aktiver Signaturschlüssel.';
            throw new \RuntimeException($reason.' Verwende Phase 27 Trust-Recovery (Private-Key-Restore oder Lost-Key-Rollover), statt automatisch eine neue unverknüpfte Vertrauenswurzel zu erzeugen.');
        }
        return $this->createKey(null,$r);
    }

    /** @return array<string,mixed> */
    public function rotate(string $confirmation):array
    {
        $this->assertSodium();$this->assertPrimaryInstanceForSigning();$old=$this->ensureActiveKey();$oldId=(string)$old['keyId'];
        if(trim($confirmation)!=='ROTATE '.$oldId)throw new \RuntimeException('Schlüsselrotation abgebrochen. Bestätigung muss exakt "ROTATE '.$oldId.'" lauten.');
        $r=$this->registry();$new=$this->createKey($oldId,$r);$this->audit('ROTATE',$new['keyId'],['previousKeyId'=>$oldId]);return $new;
    }

    /** @return array{keyId:string,signature:string,algorithm:string} */
    public function sign(string $message):array
    {
        $this->assertSodium();$this->assertPrimaryInstanceForSigning();$key=$this->ensureActiveKey();$id=(string)$key['keyId'];$eval=$this->evaluateKey($id,'sign');if(empty($eval['ok']))throw new \RuntimeException('Aktiver Signaturschlüssel ist nach Trust-Policy nicht signierfähig: '.implode(' ',(array)$eval['errors']));$secret=$this->readSecret($id);$sig=sodium_crypto_sign_detached($message,$secret);return ['keyId'=>$id,'signature'=>base64_encode($sig),'algorithm'=>'Ed25519'];
    }

    public function verify(string $message,string $signature,string $keyId):bool
    {
        $v=$this->verifyForPurpose($message,$signature,$keyId,'install');return !empty($v['ok']);
    }

    /** @return array<string,mixed> */
    public function verifyForPurpose(string $message,string $signature,string $keyId,string $purpose='install'):array
    {
        if(!function_exists('sodium_crypto_sign_verify_detached'))return $this->verification(false,['ext-sodium ist nicht verfügbar.'],$keyId,$purpose);
        $r=$this->registry();$evaluation=$this->evaluateKey($keyId,$purpose,$r);if(empty($evaluation['ok']))return $this->verification(false,(array)$evaluation['errors'],$keyId,$purpose,['keyPolicy'=>$evaluation]);
        if(!$this->verifyChainFor($keyId,$r))return $this->verification(false,['Kryptographische Schlüssel-Vertrauenskette ist ungültig.'],$keyId,$purpose,['keyPolicy'=>$evaluation]);
        $key=$r['keys'][$keyId]??null;if(!is_array($key))return $this->verification(false,['Signaturschlüssel wurde nicht gefunden.'],$keyId,$purpose);
        $pub=base64_decode((string)($key['publicKey']??''),true);$sig=base64_decode($signature,true);if($pub===false||$sig===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)return $this->verification(false,['Signatur oder Public Key ist formal ungültig.'],$keyId,$purpose,['keyPolicy'=>$evaluation]);
        if(!sodium_crypto_sign_verify_detached($sig,$message,$pub))return $this->verification(false,['Ed25519-Signatur ist kryptographisch ungültig.'],$keyId,$purpose,['keyPolicy'=>$evaluation]);
        return $this->verification(true,[],$keyId,$purpose,['keyPolicy'=>$evaluation]);
    }

    /** @return array<string,mixed> */
    public function evaluateKey(string $keyId,string $purpose='install',?array $registry=null):array
    {
        $r=$registry??$this->registry();$key=$r['keys'][$keyId]??null;$errors=[];$warnings=[];
        if(!is_array($key))return ['schema'=>'easyit.assistant.release-key-policy-evaluation.v1','ok'=>false,'verdict'=>'FAIL','keyId'=>$keyId,'purpose'=>$purpose,'errors'=>['Schlüssel ist unbekannt.'],'warnings'=>[],'effectiveStatus'=>'UNKNOWN'];
        $status=(string)($key['status']??'TRUSTED');$level=(string)($key['trustLevel']??'RELEASE');$now=time();$from=$this->timestamp($key['validFrom']??$key['createdAt']??null);$until=$this->timestamp($key['validUntil']??null);
        if($status==='REVOKED')$errors[]='Schlüssel wurde widerrufen.';elseif($status==='SUSPENDED')$errors[]='Schlüssel ist gesperrt.';elseif($status!=='TRUSTED')$errors[]='Schlüsselstatus ist nicht vertrauenswürdig: '.$status.'.';
        if($from!==null&&$now<$from)$errors[]='Schlüssel ist noch nicht gültig.';if($until!==null&&$now>$until)$errors[]='Schlüssel ist abgelaufen.';
        $required=$purpose==='historical'?'VERIFY_ONLY':'RELEASE';if($this->levelRank($level)<$this->levelRank($required))$errors[]='Vertrauensstufe '.$level.' reicht für '.$purpose.' nicht aus; benötigt '.$required.'.';
        $effective=$errors===[]?'TRUSTED':($status==='REVOKED'?'REVOKED':($status==='SUSPENDED'?'SUSPENDED':(($until!==null&&$now>$until)?'EXPIRED':'BLOCKED')));
        return ['schema'=>'easyit.assistant.release-key-policy-evaluation.v1','ok'=>$errors===[],'verdict'=>$errors===[]?'PASS':'FAIL','keyId'=>$keyId,'purpose'=>$purpose,'status'=>$status,'trustLevel'=>$level,'requiredTrustLevel'=>$required,'validFrom'=>$key['validFrom']??$key['createdAt']??null,'validUntil'=>$key['validUntil']??null,'effectiveStatus'=>$effective,'errors'=>$errors,'warnings'=>$warnings,'checkedAt'=>gmdate('c')];
    }

    /** @return array<string,mixed> */
    public function suspend(string $keyId,string $reason,string $confirmation):array
    {
        if(trim($confirmation)!=='SUSPEND '.$keyId)throw new \RuntimeException('Sperrung abgebrochen. Bestätigung muss exakt "SUSPEND '.$keyId.'" lauten.');$reason=trim($reason);if($reason==='')throw new \InvalidArgumentException('Für eine Sperrung ist ein Grund erforderlich.');$r=$this->registry();$key=$this->requireKey($keyId,$r);if(($key['status']??'TRUSTED')==='REVOKED')throw new \RuntimeException('Ein widerrufener Schlüssel kann nicht nur gesperrt werden.');$key['status']='SUSPENDED';$key['suspendedAt']=gmdate('c');$key['suspensionReason']=$reason;$r['keys'][$keyId]=$key;if(($r['activeKeyId']??null)===$keyId)$r['activeKeyId']=null;$r['updatedAt']=gmdate('c');$this->writeRegistry($r);$this->audit('SUSPEND',$keyId,['reason'=>$reason]);return $key;
    }

    /** @return array<string,mixed> */
    public function reactivate(string $keyId,string $reason,string $confirmation):array
    {
        if(trim($confirmation)!=='REACTIVATE '.$keyId)throw new \RuntimeException('Reaktivierung abgebrochen. Bestätigung muss exakt "REACTIVATE '.$keyId.'" lauten.');$r=$this->registry();$key=$this->requireKey($keyId,$r);if(($key['status']??'TRUSTED')==='REVOKED')throw new \RuntimeException('Ein widerrufener Schlüssel kann nicht reaktiviert werden.');if(($key['status']??'TRUSTED')!=='SUSPENDED')throw new \RuntimeException('Nur ein gesperrter Schlüssel kann reaktiviert werden.');$key['status']='TRUSTED';$key['reactivatedAt']=gmdate('c');$key['reactivationReason']=trim($reason);$r['keys'][$keyId]=$key;if(empty($r['activeKeyId'])&&$this->canSign($keyId,$r)&&is_file($this->privatePath($keyId)))$r['activeKeyId']=$keyId;$r['updatedAt']=gmdate('c');$this->writeRegistry($r);$this->audit('REACTIVATE',$keyId,['reason'=>trim($reason)]);return $key;
    }

    /** @return array<string,mixed> */
    public function revoke(string $keyId,string $reason,string $confirmation):array
    {
        if(trim($confirmation)!=='REVOKE '.$keyId)throw new \RuntimeException('Widerruf abgebrochen. Bestätigung muss exakt "REVOKE '.$keyId.'" lauten.');$reason=trim($reason);if($reason==='')throw new \InvalidArgumentException('Für einen Widerruf ist ein Grund erforderlich.');$r=$this->registry();$key=$this->requireKey($keyId,$r);if(($key['status']??'TRUSTED')==='REVOKED')return $key;$key['status']='REVOKED';$key['revokedAt']=gmdate('c');$key['revocationReason']=$reason;$key['trustLevel']='NONE';$r['keys'][$keyId]=$key;if(($r['activeKeyId']??null)===$keyId)$r['activeKeyId']=null;$r['updatedAt']=gmdate('c');$this->writeRegistry($r);$this->audit('REVOKE',$keyId,['reason'=>$reason]);return $key;
    }

    /** @return array<string,mixed> */
    public function setTrustLevel(string $keyId,string $level,string $reason,string $confirmation):array
    {
        $level=strtoupper(trim($level));if(!in_array($level,['RELEASE','VERIFY_ONLY','NONE'],true))throw new \InvalidArgumentException('Ungültige Vertrauensstufe.');if(trim($confirmation)!=='TRUST '.$keyId.' '.$level)throw new \RuntimeException('Trust-Level-Änderung abgebrochen. Bestätigung muss exakt "TRUST '.$keyId.' '.$level.'" lauten.');$r=$this->registry();$key=$this->requireKey($keyId,$r);if(($key['status']??'TRUSTED')==='REVOKED')throw new \RuntimeException('Vertrauensstufe eines widerrufenen Schlüssels kann nicht erhöht werden.');$old=(string)($key['trustLevel']??'RELEASE');$key['trustLevel']=$level;$key['trustLevelChangedAt']=gmdate('c');$key['trustLevelReason']=trim($reason);$r['keys'][$keyId]=$key;if(($r['activeKeyId']??null)===$keyId&&$level!=='RELEASE')$r['activeKeyId']=null;elseif(empty($r['activeKeyId'])&&$this->canSign($keyId,$r)&&is_file($this->privatePath($keyId)))$r['activeKeyId']=$keyId;$r['updatedAt']=gmdate('c');$this->writeRegistry($r);$this->audit('TRUST_LEVEL',$keyId,['from'=>$old,'to'=>$level,'reason'=>trim($reason)]);return $key;
    }

    /** @return array<string,mixed> */
    public function setValidity(string $keyId,?string $validUntil,string $reason,string $confirmation):array
    {
        if(trim($confirmation)!=='VALIDITY '.$keyId)throw new \RuntimeException('Gültigkeitsänderung abgebrochen. Bestätigung muss exakt "VALIDITY '.$keyId.'" lauten.');$until=null;if($validUntil!==null&&trim($validUntil)!==''){$ts=strtotime(trim($validUntil));if($ts===false)throw new \InvalidArgumentException('Ungültiges Ablaufdatum.');$until=gmdate('c',$ts);}$r=$this->registry();$key=$this->requireKey($keyId,$r);if(($key['status']??'TRUSTED')==='REVOKED')throw new \RuntimeException('Gültigkeit eines widerrufenen Schlüssels kann nicht geändert werden.');$key['validUntil']=$until;$key['validityChangedAt']=gmdate('c');$key['validityReason']=trim($reason);$r['keys'][$keyId]=$key;if(($r['activeKeyId']??null)===$keyId&&!$this->canSign($keyId,$r))$r['activeKeyId']=null;elseif(empty($r['activeKeyId'])&&$this->canSign($keyId,$r)&&is_file($this->privatePath($keyId)))$r['activeKeyId']=$keyId;$r['updatedAt']=gmdate('c');$this->writeRegistry($r);$this->audit('VALIDITY',$keyId,['validUntil'=>$until,'reason'=>trim($reason)]);return $key;
    }

    /** @return list<array<string,mixed>> */
    public function auditEvents(int $limit=200):array
    {
        if(!is_file($this->auditPath))return [];$lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$out=[];foreach(array_slice($lines,-max(1,min(1000,$limit))) as $line){$x=json_decode($line,true);if(is_array($x))$out[]=$x;}return array_reverse($out);
    }

    /** @return array<string,mixed> */
    public function verifyAuditChain():array
    {
        if(!is_file($this->auditPath))return ['schema'=>'easyit.assistant.release-trust-audit-verification.v1','ok'=>true,'verdict'=>'PASS','events'=>0,'errors'=>[]];$lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$prev='';$errors=[];$count=0;foreach($lines as $line){$x=json_decode($line,true);if(!is_array($x)){$errors[]='Ungültige Audit-JSON-Zeile.';continue;}$eventHash=(string)($x['eventHash']??'');$storedPrev=(string)($x['previousHash']??'');$payload=$x;unset($payload['eventHash']);$calc=hash('sha256',$this->canonical($payload));if(!hash_equals($storedPrev,$prev))$errors[]='Audit-Vorgängerhash stimmt bei Event '.(string)($x['eventId']??'?').' nicht.';if($eventHash===''||!hash_equals($eventHash,$calc))$errors[]='Audit-Eventhash stimmt bei Event '.(string)($x['eventId']??'?').' nicht.';$prev=$eventHash;$count++;}return ['schema'=>'easyit.assistant.release-trust-audit-verification.v1','ok'=>$errors===[],'verdict'=>$errors===[]?'PASS':'FAIL','events'=>$count,'lastHash'=>$prev?:null,'errors'=>$errors,'checkedAt'=>gmdate('c')];
    }

    /** @return array<string,mixed> */
    public function verifyChain():array
    {
        $r=$this->registry();$errors=[];foreach((array)($r['keys']??[]) as $id=>$key)if(is_array($key)&&($key['status']??'')!=='REVOKED'&&!$this->verifyChainFor((string)$id,$r))$errors[]='Vertrauenskette ist für '.(string)$id.' ungültig.';return ['schema'=>'easyit.assistant.release-trust-chain-verification.v2','ok'=>$errors===[],'verdict'=>$errors===[]?'PASS':'FAIL','errors'=>$errors,'checkedAt'=>gmdate('c')];
    }

    /** @return array<string,mixed> */
    public function key(string $keyId):array{$r=$this->registry();return is_array($r['keys'][$keyId]??null)?(array)$r['keys'][$keyId]:[];}

    /** @return array<string,mixed> */
    private function createKey(?string $previousKeyId,array $r):array
    {
        $this->ensureDirs();$kp=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($kp);$pub=sodium_crypto_sign_publickey($kp);$keyId='ed25519-'.substr(hash('sha256',$pub),0,24);$now=gmdate('c');
        $entry=['schema'=>'easyit.assistant.release-trust-key.v2','keyId'=>$keyId,'algorithm'=>'Ed25519','publicKey'=>base64_encode($pub),'status'=>'TRUSTED','trustLevel'=>'RELEASE','createdAt'=>$now,'validFrom'=>$now,'validUntil'=>null,'previousKeyId'=>$previousKeyId,'transition'=>null,'suspendedAt'=>null,'revokedAt'=>null];
        if($previousKeyId!==null){$payload=$this->canonical(['schema'=>'easyit.assistant.release-key-transition.v1','previousKeyId'=>$previousKeyId,'newKeyId'=>$keyId,'newPublicKey'=>base64_encode($pub),'rotatedAt'=>$now]);$oldSecret=$this->readSecret($previousKeyId);$entry['transition']=['payloadSha256'=>hash('sha256',$payload),'previousSignature'=>base64_encode(sodium_crypto_sign_detached($payload,$oldSecret)),'newSignature'=>base64_encode(sodium_crypto_sign_detached($payload,$secret))];$r['rotations'][]=['previousKeyId'=>$previousKeyId,'newKeyId'=>$keyId,'rotatedAt'=>$now,'payloadSha256'=>hash('sha256',$payload),'previousSignature'=>$entry['transition']['previousSignature'],'newSignature'=>$entry['transition']['newSignature']];}
        if(@file_put_contents($this->privatePath($keyId),base64_encode($secret)."\n",LOCK_EX)===false)throw new \RuntimeException('Privater Release-Signaturschlüssel konnte nicht gespeichert werden.');@chmod($this->privatePath($keyId),0600);
        $r['schema']='easyit.assistant.release-trust-store.v2';$r['activeKeyId']=$keyId;$r['keys'][$keyId]=$entry;$r['updatedAt']=$now;$this->writeRegistry($r);$this->audit('CREATE',$keyId,['previousKeyId'=>$previousKeyId]);return $entry;
    }

    /** @param array<string,mixed> $r */
    private function verifyChainFor(string $keyId,array $r):bool
    {
        $seen=[];$current=$keyId;while($current!==''){
            if(isset($seen[$current]))return false;$seen[$current]=true;$key=$r['keys'][$current]??null;if(!is_array($key)||($key['status']??'')==='REVOKED')return false;$prev=(string)($key['previousKeyId']??'');if($prev==='')return true;$rotation=null;foreach((array)($r['rotations']??[]) as $x)if(is_array($x)&&($x['newKeyId']??'')===$current&&($x['previousKeyId']??'')===$prev){$rotation=$x;break;}if(!is_array($rotation))return false;$old=$r['keys'][$prev]??null;if(!is_array($old)||($old['status']??'')==='REVOKED')return false;$payload=$this->canonical(['schema'=>'easyit.assistant.release-key-transition.v1','previousKeyId'=>$prev,'newKeyId'=>$current,'newPublicKey'=>(string)($key['publicKey']??''),'rotatedAt'=>(string)($rotation['rotatedAt']??'')]);if(!hash_equals((string)($rotation['payloadSha256']??''),hash('sha256',$payload)))return false;$oldPub=base64_decode((string)($old['publicKey']??''),true);$newPub=base64_decode((string)($key['publicKey']??''),true);$oldSig=base64_decode((string)($rotation['previousSignature']??''),true);$newSig=base64_decode((string)($rotation['newSignature']??''),true);if($oldPub===false||$newPub===false||$oldSig===false||$newSig===false)return false;if(!sodium_crypto_sign_verify_detached($oldSig,$payload,$oldPub)||!sodium_crypto_sign_verify_detached($newSig,$payload,$newPub))return false;$current=$prev;
        }return false;
    }

    private function canSign(string $keyId,array $registry):bool{$e=$this->evaluateKey($keyId,'sign',$registry);return !empty($e['ok']);}
    private function readSecret(string $keyId):string{$p=$this->privatePath($keyId);$raw=@file_get_contents($p);$secret=$raw===false?false:base64_decode(trim($raw),true);if($secret===false||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new \RuntimeException('Privater Signaturschlüssel fehlt oder ist beschädigt: '.$keyId);return $secret;}
    private function privatePath(string $id):string{if(!preg_match('/^ed25519-[a-f0-9]{24}$/',$id))throw new \InvalidArgumentException('Ungültige Key-ID.');return $this->privateDir.'/'.$id.'.key';}
    /** @return array<string,mixed> */private function registry():array{if(!is_file($this->registryPath))return ['schema'=>'easyit.assistant.release-trust-store.v2','activeKeyId'=>null,'keys'=>[],'rotations'=>[]];$d=json_decode((string)@file_get_contents($this->registryPath),true);if(!is_array($d))$d=[];$d=array_merge(['schema'=>'easyit.assistant.release-trust-store.v2','activeKeyId'=>null,'keys'=>[],'rotations'=>[]],$d);foreach((array)$d['keys'] as $id=>$key)if(is_array($key))$d['keys'][$id]=array_merge(['schema'=>'easyit.assistant.release-trust-key.v2','status'=>'TRUSTED','trustLevel'=>'RELEASE','validFrom'=>$key['createdAt']??null,'validUntil'=>null,'suspendedAt'=>null,'revokedAt'=>null],$key);return $d;}
    private function writeRegistry(array $r):void{$this->ensureDirs();$j=json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false)throw new \RuntimeException('Trust-Store kann nicht serialisiert werden.');$tmp=$this->registryPath.'.tmp.'.bin2hex(random_bytes(4));if(@file_put_contents($tmp,$j."\n",LOCK_EX)===false||!@rename($tmp,$this->registryPath)){@unlink($tmp);throw new \RuntimeException('Trust-Store kann nicht atomar gespeichert werden.');}@chmod($this->registryPath,0640);}
    private function ensureDirs():void{foreach([$this->base,$this->privateDir] as $d)if(!is_dir($d)&&!@mkdir($d,0700,true)&&!is_dir($d))throw new \RuntimeException('Trust-Verzeichnis kann nicht erstellt werden: '.$d);@chmod($this->privateDir,0700);}
    private function assertSodium():void{if(!function_exists('sodium_crypto_sign_keypair'))throw new \RuntimeException('Für Phase 25/26 wird die PHP-Erweiterung ext-sodium (Ed25519) benötigt.');}
    private function assertPrimaryInstanceForSigning():void{
        $path=$this->base.'/instances/local.json';
        if(!is_file($path))return; // Legacy/Pre-Phase-28 installations remain compatible until initialized.
        $d=json_decode((string)@file_get_contents($path),true);
        if(!is_array($d))throw new \RuntimeException('Lokale Trust-Instanzdatei ist beschädigt.');
        $role=(string)($d['role']??'');$status=(string)($d['status']??'ACTIVE');
        if($role!=='PRIMARY'||$status!=='ACTIVE')throw new \RuntimeException('Release-Signierung ist auf dieser Instanz gesperrt. Nur eine aktive PRIMARY-Instanz darf signieren. Aktuelle Rolle: '.($role?:'UNBEKANNT').'.');
        // Phase 29: once quorum failover is configured, PRIMARY is additionally
        // fenced by a cryptographically verified, non-expired quorum lease.
        TrustFailoverLeaseGuard::assertCanSign($this->root);
    }
    private function levelRank(string $level):int{return match(strtoupper($level)){'RELEASE'=>20,'VERIFY_ONLY'=>10,default=>0};}
    private function timestamp(mixed $v):?int{if(!is_string($v)||trim($v)==='')return null;$t=strtotime($v);return $t===false?null:$t;}
    /** @return array<string,mixed> */private function requireKey(string $id,array $r):array{$key=$r['keys'][$id]??null;if(!is_array($key))throw new \RuntimeException('Schlüssel wurde nicht gefunden: '.$id);return $key;}
    /** @return array<string,mixed> */private function policy():array{if(!is_file($this->policyPath))return ['schema'=>'easyit.assistant.release-trust-policy.v1','installMinimumTrustLevel'=>'RELEASE','historicalMinimumTrustLevel'=>'VERIFY_ONLY','blockSuspended'=>true,'blockRevoked'=>true,'blockExpired'=>true];$d=json_decode((string)@file_get_contents($this->policyPath),true);return is_array($d)?array_merge(['schema'=>'easyit.assistant.release-trust-policy.v1','installMinimumTrustLevel'=>'RELEASE','historicalMinimumTrustLevel'=>'VERIFY_ONLY','blockSuspended'=>true,'blockRevoked'=>true,'blockExpired'=>true],$d):[];}
    private function audit(string $action,string $keyId,array $details=[]):void{$this->ensureDirs();$previous='';if(is_file($this->auditPath)){$lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$last=end($lines);if(is_string($last)){$x=json_decode($last,true);if(is_array($x))$previous=(string)($x['eventHash']??'');}}$event=['schema'=>'easyit.assistant.release-trust-audit-event.v1','eventId'=>'trust-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)),'action'=>$action,'keyId'=>$keyId,'occurredAt'=>gmdate('c'),'previousHash'=>$previous,'details'=>$details];$event['eventHash']=hash('sha256',$this->canonical($event));$j=json_encode($event,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false||@file_put_contents($this->auditPath,$j."\n",FILE_APPEND|LOCK_EX)===false)throw new \RuntimeException('Trust-Audit konnte nicht geschrieben werden.');@chmod($this->auditPath,0640);}
    /** @return array<string,mixed> */private function auditSummary():array{$v=$this->verifyAuditChain();return ['events'=>$v['events']??0,'lastHash'=>$v['lastHash']??null,'chainOk'=>$v['ok']??false];}
    /** @return array<string,mixed> */private function verification(bool $ok,array $errors,string $keyId,string $purpose,array $extra=[]):array{return array_merge(['schema'=>'easyit.assistant.release-signature-verification.v2','ok'=>$ok,'verdict'=>$ok?'PASS':'FAIL','keyId'=>$keyId,'purpose'=>$purpose,'errors'=>$errors,'checkedAt'=>gmdate('c')],$extra);}
    /** @param mixed $v */private function normalize($v){if(!is_array($v))return $v;if(array_is_list($v))return array_map(fn($x)=>$this->normalize($x),$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$this->normalize($x);return $v;}
    private function canonical(array $payload):string{$j=json_encode($this->normalize($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false)throw new \RuntimeException('Signaturpayload kann nicht serialisiert werden.');return $j;}
}
