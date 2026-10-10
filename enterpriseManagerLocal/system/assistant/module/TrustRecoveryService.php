<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Module;

final class TrustRecoveryService
{
    private string $trustDir;
    private string $privateDir;
    private string $registryPath;
    private string $policyPath;
    private string $auditPath;
    private string $catalogPath;
    private string $recoveryDir;

    public function __construct(
        private ReleaseTrustStore $trust,
        private ReleaseCatalogService $catalog,
        private DataFormModuleLibraryStore $libraryStore,
        private string $root
    ) {
        $this->root=rtrim($root,'/\\');
        $this->trustDir=$this->root.'/storage/assistant/trust';
        $this->privateDir=$this->trustDir.'/private';
        $this->registryPath=$this->trustDir.'/trusted-keys.json';
        $this->policyPath=$this->trustDir.'/policy.json';
        $this->auditPath=$this->trustDir.'/audit.jsonl';
        $this->catalogPath=$this->root.'/storage/assistant/release-catalog/catalog.json';
        $this->recoveryDir=$this->trustDir.'/recovery';
    }

    /** @return array<string,mixed> */
    public function status():array
    {
        $private=[];
        foreach((array)($this->loadRegistry()['keys']??[]) as $id=>$key){if(!is_array($key))continue;$private[]=['keyId'=>(string)$id,'privateKeyAvailable'=>is_file($this->privatePath((string)$id)),'status'=>$key['status']??'TRUSTED','trustLevel'=>$key['trustLevel']??'RELEASE'];}
        return [
            'schema'=>'easyit.assistant.trust-recovery-status.v1',
            'trust'=>$this->trust->status(),
            'auditVerification'=>$this->trust->verifyAuditChain(),
            'chainVerification'=>$this->trust->verifyChain(),
            'privateKeys'=>$private,
            'publicBundleCount'=>$this->countFiles($this->recoveryDir.'/public','*.trust-public.zip'),
            'privateBackupCount'=>$this->countFiles($this->recoveryDir.'/private','*.trust-private.json'),
            'offlineBundleCount'=>$this->countFiles($this->recoveryDir.'/offline','*.trust-offline.zip'),
            'sodiumAvailable'=>function_exists('sodium_crypto_secretbox'),
        ];
    }

    /** @return array<string,mixed> */
    public function exportPublicBundle():array
    {
        $this->ensureDir($this->recoveryDir.'/public');
        $files=[];
        $files['trusted-keys.json']=$this->pretty($this->loadRegistry());
        $files['policy.json']=$this->pretty($this->loadPolicy());
        $files['audit.jsonl']=is_file($this->auditPath)?(string)file_get_contents($this->auditPath):'';
        $files['release-catalog.json']=$this->pretty($this->loadCatalog());
        $manifest=$this->manifest('easyit.assistant.trust-public-bundle.v1',$files,['kind'=>'public-trust','privateMaterial'=>false]);
        $files['trust-public-manifest.json']=$this->pretty($manifest);
        $name='easyit-trust-public-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.trust-public.zip';
        $path=$this->recoveryDir.'/public/'.$name;
        $this->writeZip($path,$files);
        $sha=(string)hash_file('sha256',$path);file_put_contents($path.'.sha256',$sha.'  '.$name."\n");
        $this->audit('PUBLIC_BUNDLE_EXPORT','', ['sha256'=>$sha,'file'=>$name]);
        return ['schema'=>'easyit.assistant.trust-public-export-result.v1','ok'=>true,'file'=>$name,'path'=>$path,'sha256'=>$sha,'manifest'=>$manifest,'privateMaterial'=>false];
    }

    /** @return array<string,mixed> */
    public function importPublicBundle(string $zipPath,bool $allowRelease=false,bool $applyPolicy=false,string $confirmation=''):array
    {
        if(!is_file($zipPath))throw new \RuntimeException('Public-Trust-Bundle wurde nicht gefunden.');
        if(filesize($zipPath)>20*1024*1024)throw new \RuntimeException('Public-Trust-Bundle ist zu groß.');
        $files=$this->readZipStrict($zipPath,['trusted-keys.json','policy.json','audit.jsonl','release-catalog.json','trust-public-manifest.json']);
        $manifest=$this->decodeJson($files['trust-public-manifest.json']??'','Public-Trust-Manifest');
        $this->verifyManifest($manifest,$files,'easyit.assistant.trust-public-bundle.v1');
        $incoming=$this->decodeJson($files['trusted-keys.json']??'','Public Trust Store');
        $policy=$this->decodeJson($files['policy.json']??'{}','Trust-Policy');
        $catalog=$this->decodeJson($files['release-catalog.json']??'{}','Release-Katalog');
        if($allowRelease&&trim($confirmation)!=='IMPORT PUBLIC ANCHORS AS RELEASE')throw new \RuntimeException('RELEASE-Trust-Import abgebrochen. Bestätigung muss exakt "IMPORT PUBLIC ANCHORS AS RELEASE" lauten.');
        $local=$this->loadRegistry();$added=[];$updated=[];
        foreach((array)($incoming['keys']??[]) as $id=>$key){
            if(!is_array($key)||!preg_match('/^ed25519-[a-f0-9]{24}$/',(string)$id))continue;
            $pub=base64_decode((string)($key['publicKey']??''),true);if($pub===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new \RuntimeException('Importierter Public Key ist formal ungültig: '.$id);
            if(isset($local['keys'][$id])&&is_array($local['keys'][$id])){
                if(!hash_equals((string)($local['keys'][$id]['publicKey']??''),(string)($key['publicKey']??'')))throw new \RuntimeException('Key-ID-Kollision mit abweichendem Public Key: '.$id);
                $updated[]=(string)$id;continue;
            }
            $x=$key;$x['importedAt']=gmdate('c');$x['imported']=true;$x['trustLevel']=$allowRelease?(string)($x['trustLevel']??'RELEASE'):'VERIFY_ONLY';if(($x['status']??'TRUSTED')==='REVOKED')$x['trustLevel']='NONE';$local['keys'][$id]=$x;$added[]=(string)$id;
        }
        $known=[];foreach((array)($local['rotations']??[]) as $r)if(is_array($r))$known[(string)($r['previousKeyId']??'').'|'.(string)($r['newKeyId']??'')]=true;
        foreach((array)($incoming['rotations']??[]) as $r){if(!is_array($r))continue;$k=(string)($r['previousKeyId']??'').'|'.(string)($r['newKeyId']??'');if($k!=='|'&&!isset($known[$k])){$local['rotations'][]=$r;$known[$k]=true;}}
        if(!isset($local['recoveryTransitions']))$local['recoveryTransitions']=[];
        foreach((array)($incoming['recoveryTransitions']??[]) as $r)if(is_array($r))$local['recoveryTransitions'][]=$r;
        $local['schema']='easyit.assistant.release-trust-store.v2';$local['updatedAt']=gmdate('c');$this->atomicJson($this->registryPath,$local,0640);
        if($applyPolicy)$this->atomicJson($this->policyPath,$policy,0640);
        $this->mergeCatalog($catalog);
        $importId='import-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));$prov=$this->trustDir.'/imports/'.$importId;$this->ensureDir($prov);file_put_contents($prov.'/audit.jsonl',$files['audit.jsonl']??'');$this->atomicJson($prov.'/manifest.json',$manifest,0640);
        $this->audit('PUBLIC_BUNDLE_IMPORT','',['importId'=>$importId,'addedKeys'=>$added,'existingKeys'=>$updated,'allowRelease'=>$allowRelease,'applyPolicy'=>$applyPolicy,'bundleSha256'=>hash_file('sha256',$zipPath)]);
        return ['schema'=>'easyit.assistant.trust-public-import-result.v1','ok'=>true,'verdict'=>'PASS','addedKeys'=>$added,'existingKeys'=>$updated,'allowRelease'=>$allowRelease,'policyApplied'=>$applyPolicy,'provenanceId'=>$importId,'chainVerification'=>$this->trust->verifyChain()];
    }

    /** @return array<string,mixed> */
    public function createPrivateBackup(string $passphrase,string $confirmation):array
    {
        $this->assertSodium();if(trim($confirmation)!=='BACKUP PRIVATE KEYS')throw new \RuntimeException('Private-Key-Backup abgebrochen. Bestätigung muss exakt "BACKUP PRIVATE KEYS" lauten.');
        if(strlen($passphrase)<10)throw new \InvalidArgumentException('Die Backup-Passphrase muss mindestens 10 Zeichen lang sein.');
        $registry=$this->loadRegistry();$secrets=[];
        foreach((array)($registry['keys']??[]) as $id=>$key){if(!is_array($key))continue;$p=$this->privatePath((string)$id);if(!is_file($p))continue;$raw=trim((string)file_get_contents($p));$secret=base64_decode($raw,true);if($secret===false||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new \RuntimeException('Privater Schlüssel ist beschädigt: '.$id);$pub=sodium_crypto_sign_publickey_from_secretkey($secret);if(!hash_equals((string)($key['publicKey']??''),base64_encode($pub)))throw new \RuntimeException('Privater Schlüssel passt nicht zum registrierten Public Key: '.$id);$secrets[(string)$id]=base64_encode($secret);}
        if($secrets===[])throw new \RuntimeException('Es ist kein privater Signing-Key für ein Backup vorhanden.');
        $material=['schema'=>'easyit.assistant.trust-private-material.v1','createdAt'=>gmdate('c'),'registry'=>$registry,'secrets'=>$secrets];$plain=$this->json($material);
        $salt=random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);$op=SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;$mem=SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;$key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES,$passphrase,$salt,$op,$mem,SODIUM_CRYPTO_PWHASH_ALG_DEFAULT);$nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);$cipher=sodium_crypto_secretbox($plain,$nonce,$key);sodium_memzero($key);
        $envelope=['schema'=>'easyit.assistant.trust-private-backup.v1','algorithm'=>'XSalsa20-Poly1305','kdf'=>'Argon2id','createdAt'=>gmdate('c'),'keyIds'=>array_keys($secrets),'registrySha256'=>hash('sha256',$this->json($registry)),'salt'=>base64_encode($salt),'opslimit'=>$op,'memlimit'=>$mem,'nonce'=>base64_encode($nonce),'ciphertext'=>base64_encode($cipher)];
        $this->ensureDir($this->recoveryDir.'/private');$name='easyit-trust-private-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.trust-private.json';$path=$this->recoveryDir.'/private/'.$name;$this->atomicJson($path,$envelope,0600);$sha=(string)hash_file('sha256',$path);file_put_contents($path.'.sha256',$sha.'  '.$name."\n");@chmod($path.'.sha256',0600);$this->audit('PRIVATE_BACKUP_CREATE','',['keyIds'=>array_keys($secrets),'fileSha256'=>$sha]);
        return ['schema'=>'easyit.assistant.trust-private-backup-result.v1','ok'=>true,'file'=>$name,'path'=>$path,'sha256'=>$sha,'keyIds'=>array_keys($secrets),'encrypted'=>true,'passphraseStored'=>false];
    }

    /** @return array<string,mixed> */
    public function restorePrivateBackup(string $backupPath,string $passphrase,string $confirmation,string $reason=''):array
    {
        $this->assertSodium();if(trim($confirmation)!=='RESTORE PRIVATE KEYS')throw new \RuntimeException('Private-Key-Restore abgebrochen. Bestätigung muss exakt "RESTORE PRIVATE KEYS" lauten.');if(!is_file($backupPath))throw new \RuntimeException('Private-Key-Backup wurde nicht gefunden.');if(filesize($backupPath)>10*1024*1024)throw new \RuntimeException('Private-Key-Backup ist zu groß.');
        $env=$this->decodeJson((string)file_get_contents($backupPath),'Private-Key-Backup');if(($env['schema']??'')!=='easyit.assistant.trust-private-backup.v1')throw new \RuntimeException('Unbekanntes Private-Key-Backup-Schema.');
        $salt=base64_decode((string)($env['salt']??''),true);$nonce=base64_decode((string)($env['nonce']??''),true);$cipher=base64_decode((string)($env['ciphertext']??''),true);$op=(int)($env['opslimit']??0);$mem=(int)($env['memlimit']??0);if($salt===false||$nonce===false||$cipher===false||strlen($salt)!==SODIUM_CRYPTO_PWHASH_SALTBYTES||strlen($nonce)!==SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new \RuntimeException('Backup-Verschlüsselungsparameter sind ungültig.');if($op<1||$op>SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE||$mem<1024*1024||$mem>SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE)throw new \RuntimeException('Backup-KDF-Parameter liegen außerhalb der erlaubten Grenzen.');
        $key=sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES,$passphrase,$salt,$op,$mem,SODIUM_CRYPTO_PWHASH_ALG_DEFAULT);$plain=sodium_crypto_secretbox_open($cipher,$nonce,$key);sodium_memzero($key);if($plain===false)throw new \RuntimeException('Backup konnte nicht entschlüsselt werden. Passphrase falsch oder Datei manipuliert.');$material=$this->decodeJson($plain,'Private-Key-Material');if(($material['schema']??'')!=='easyit.assistant.trust-private-material.v1')throw new \RuntimeException('Entschlüsseltes Backup-Material ist ungültig.');
        $incoming=(array)($material['registry']??[]);$secrets=(array)($material['secrets']??[]);$local=$this->loadRegistry();$restored=[];
        foreach($secrets as $id=>$encoded){if(!is_string($encoded)||!preg_match('/^ed25519-[a-f0-9]{24}$/',(string)$id))continue;$secret=base64_decode($encoded,true);if($secret===false||strlen($secret)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new \RuntimeException('Backup enthält ungültigen privaten Schlüssel: '.$id);$pub=base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));$incomingKey=$incoming['keys'][$id]??null;if(!is_array($incomingKey)||!hash_equals((string)($incomingKey['publicKey']??''),$pub))throw new \RuntimeException('Backup Public/Private-Key-Paar ist inkonsistent: '.$id);if(isset($local['keys'][$id])&&is_array($local['keys'][$id])&&!hash_equals((string)($local['keys'][$id]['publicKey']??''),$pub))throw new \RuntimeException('Lokaler Trust-Store besitzt abweichenden Public Key für '.$id);if(!isset($local['keys'][$id]))$local['keys'][$id]=$incomingKey;$this->ensureDir($this->privateDir);file_put_contents($this->privatePath((string)$id),base64_encode($secret)."\n",LOCK_EX);@chmod($this->privatePath((string)$id),0600);$restored[]=(string)$id;}
        if($restored===[])throw new \RuntimeException('Backup enthält keine wiederherstellbaren privaten Schlüssel.');
        $known=[];foreach((array)($local['rotations']??[]) as $r)if(is_array($r))$known[(string)($r['previousKeyId']??'').'|'.(string)($r['newKeyId']??'')]=true;foreach((array)($incoming['rotations']??[]) as $r){if(!is_array($r))continue;$k=(string)($r['previousKeyId']??'').'|'.(string)($r['newKeyId']??'');if($k!=='|'&&!isset($known[$k])){$local['rotations'][]=$r;$known[$k]=true;}}
        $candidate=(string)($incoming['activeKeyId']??'');if($candidate!==''&&in_array($candidate,$restored,true)&&isset($local['keys'][$candidate])&&($local['keys'][$candidate]['status']??'TRUSTED')==='TRUSTED'&&($local['keys'][$candidate]['trustLevel']??'RELEASE')==='RELEASE')$local['activeKeyId']=$candidate;$local['updatedAt']=gmdate('c');$this->atomicJson($this->registryPath,$local,0640);$this->audit('PRIVATE_BACKUP_RESTORE',(string)($local['activeKeyId']??''),['restoredKeyIds'=>$restored,'reason'=>trim($reason),'backupSha256'=>hash_file('sha256',$backupPath)]);
        return ['schema'=>'easyit.assistant.trust-private-restore-result.v1','ok'=>true,'verdict'=>'PASS','restoredKeyIds'=>$restored,'activeKeyId'=>$local['activeKeyId']??null,'chainVerification'=>$this->trust->verifyChain()];
    }

    /** @return array<string,mixed> */
    public function recoverLostSigningKey(string $lostKeyId,string $reason,string $confirmation):array
    {
        $this->assertSodium();$reason=trim($reason);if($reason==='')throw new \InvalidArgumentException('Für einen Recovery-Rollover ist ein Grund erforderlich.');if(trim($confirmation)!=='RECOVER LOST KEY '.$lostKeyId)throw new \RuntimeException('Recovery-Rollover abgebrochen. Bestätigung muss exakt "RECOVER LOST KEY '.$lostKeyId.'" lauten.');
        $r=$this->loadRegistry();$old=$r['keys'][$lostKeyId]??null;if(!is_array($old))throw new \RuntimeException('Verlorener Schlüssel ist im Trust-Store unbekannt.');if(is_file($this->privatePath($lostKeyId)))throw new \RuntimeException('Privater Schlüssel ist noch vorhanden. Verwende normale Schlüsselrotation statt Lost-Key-Recovery.');if(($old['status']??'TRUSTED')==='REVOKED')throw new \RuntimeException('Ein bereits widerrufener Schlüssel kann nicht als Lost-Key-Rollover verwendet werden.');
        $old['trustLevel']='VERIFY_ONLY';$old['lostAt']=gmdate('c');$old['lostReason']=$reason;$r['keys'][$lostKeyId]=$old;$r['activeKeyId']=null;
        $kp=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($kp);$pub=sodium_crypto_sign_publickey($kp);$newId='ed25519-'.substr(hash('sha256',$pub),0,24);$now=gmdate('c');$new=['schema'=>'easyit.assistant.release-trust-key.v2','keyId'=>$newId,'algorithm'=>'Ed25519','publicKey'=>base64_encode($pub),'status'=>'TRUSTED','trustLevel'=>'RELEASE','createdAt'=>$now,'validFrom'=>$now,'validUntil'=>null,'previousKeyId'=>null,'transition'=>null,'recoveryRoot'=>true,'recoveryOf'=>$lostKeyId];
        $payload=$this->canonical(['schema'=>'easyit.assistant.release-key-recovery-transition.v1','lostKeyId'=>$lostKeyId,'newKeyId'=>$newId,'newPublicKey'=>base64_encode($pub),'reason'=>$reason,'recoveredAt'=>$now]);$transition=['schema'=>'easyit.assistant.release-key-recovery-transition.v1','lostKeyId'=>$lostKeyId,'newKeyId'=>$newId,'recoveredAt'=>$now,'reason'=>$reason,'continuity'=>'ADMINISTRATIVE_NOT_CRYPTOGRAPHIC','payloadSha256'=>hash('sha256',$payload),'newKeySignature'=>base64_encode(sodium_crypto_sign_detached($payload,$secret))];
        $r['keys'][$newId]=$new;$r['activeKeyId']=$newId;$r['recoveryTransitions'][]=$transition;$r['updatedAt']=$now;$this->ensureDir($this->privateDir);file_put_contents($this->privatePath($newId),base64_encode($secret)."\n",LOCK_EX);@chmod($this->privatePath($newId),0600);$this->atomicJson($this->registryPath,$r,0640);$this->audit('LOST_KEY_RECOVERY',$newId,['lostKeyId'=>$lostKeyId,'reason'=>$reason,'continuity'=>$transition['continuity']]);
        return ['schema'=>'easyit.assistant.trust-lost-key-recovery-result.v1','ok'=>true,'verdict'=>'PASS_WITH_WARNINGS','lostKeyId'=>$lostKeyId,'lostKeyTrustLevel'=>'VERIFY_ONLY','newKeyId'=>$newId,'continuity'=>$transition['continuity'],'warning'=>'Ohne Backup des alten privaten Schlüssels kann keine kryptographische Alt→Neu-Kontinuität bewiesen werden. Historische Signaturen bleiben nur als VERIFY_ONLY prüfbar.'];
    }

    /** @return array<string,mixed> */
    public function createOfflineBundle(string $catalogId):array
    {
        $entry=$this->catalog->get($catalogId);if($entry===[])throw new \RuntimeException('Katalogeintrag wurde nicht gefunden.');$payload=(array)($entry['payload']??[]);$moduleId=(string)($payload['moduleId']??'');$visibility=(string)($payload['visibility']??'system');$owner=isset($payload['ownerProject'])&&is_string($payload['ownerProject'])?$payload['ownerProject']:null;$package=$this->libraryStore->packagePath($moduleId,$visibility,$owner);if(!is_file($package))throw new \RuntimeException('Zum Katalogeintrag wurde kein Modulpaket gefunden.');if(!hash_equals((string)($payload['packageSha256']??''),(string)hash_file('sha256',$package)))throw new \RuntimeException('Modulpaket-SHA stimmt bereits vor Offline-Export nicht mit dem Katalog überein.');
        $files=['trusted-keys.json'=>$this->pretty($this->loadRegistry()),'policy.json'=>$this->pretty($this->loadPolicy()),'audit.jsonl'=>is_file($this->auditPath)?(string)file_get_contents($this->auditPath):'','catalog-entry.json'=>$this->pretty($entry),'module.dataform-module.zip'=>(string)file_get_contents($package)];
        $gateId=(string)($payload['gateReportId']??'');if($gateId!==''){$scope=$visibility==='project'?'project-'.$owner:'system';$gatePath=$this->root.'/storage/assistant/module-release-gates/'.$scope.'/'.$moduleId.'/'.$gateId.'.json';if(is_file($gatePath))$files['gate-report.json']=(string)file_get_contents($gatePath);}
        $files['verify.php']=$this->offlineVerifierScript();$manifest=$this->manifest('easyit.assistant.trust-offline-bundle.v1',$files,['kind'=>'offline-release-verification','catalogId'=>$catalogId,'privateMaterial'=>false]);$files['offline-manifest.json']=$this->pretty($manifest);$this->ensureDir($this->recoveryDir.'/offline');$name='easyit-offline-'.$this->safe($moduleId).'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3)).'.trust-offline.zip';$path=$this->recoveryDir.'/offline/'.$name;$this->writeZip($path,$files);$sha=(string)hash_file('sha256',$path);file_put_contents($path.'.sha256',$sha.'  '.$name."\n");$this->audit('OFFLINE_BUNDLE_EXPORT',(string)($entry['signature']['keyId']??''),['catalogId'=>$catalogId,'sha256'=>$sha]);return ['schema'=>'easyit.assistant.trust-offline-export-result.v1','ok'=>true,'file'=>$name,'path'=>$path,'sha256'=>$sha,'catalogId'=>$catalogId,'moduleId'=>$moduleId,'manifest'=>$manifest];
    }

    /** @return array<string,mixed> */
    public function verifyOfflineBundle(string $zipPath):array
    {
        if(!is_file($zipPath))throw new \RuntimeException('Offline-Prüfpaket wurde nicht gefunden.');if(filesize($zipPath)>80*1024*1024)throw new \RuntimeException('Offline-Prüfpaket ist zu groß.');$tmp=sys_get_temp_dir().'/easyit-offline-'.bin2hex(random_bytes(6));$this->ensureDir($tmp);try{$zip=new \PharData($zipPath);foreach($this->archiveEntries($zip,$zipPath) as $n){if($n===''||str_starts_with($n,'/')||preg_match('#(^|/)\.\.(/|$)#',$n)||str_contains($n,'\\'))throw new \RuntimeException('Unsicherer ZIP-Pfad im Offline-Prüfpaket.');}$zip->extractTo($tmp,null,true);unset($zip);}catch(\Throwable $e){$this->rrmdir($tmp);throw new \RuntimeException('Offline-Prüfpaket kann nicht geöffnet/extrahiert werden: '.$e->getMessage(),0,$e);}$script=$tmp.'/verify.php';if(!is_file($script)){$this->rrmdir($tmp);throw new \RuntimeException('Offline-Verifier fehlt.');}$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1';exec($cmd,$out,$code);$raw=implode("\n",$out);$result=json_decode($raw,true);$this->rrmdir($tmp);if(!is_array($result))$result=['schema'=>'easyit.assistant.trust-offline-verification.v1','ok'=>false,'verdict'=>'FAIL','errors'=>['Offline-Verifier lieferte kein gültiges JSON.'],'raw'=>$raw];$result['processExitCode']=$code;$result['bundleSha256']=hash_file('sha256',$zipPath);return $result;
    }

    /** @return list<array<string,mixed>> */
    public function catalogEntries():array{return $this->catalog->entries();}

    private function offlineVerifierScript():string
    {
        return <<<'PHP'
<?php
declare(strict_types=1);
function norm($v){if(!is_array($v))return $v;if(array_is_list($v))return array_map('norm',$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=norm($x);return $v;}
function canon(array $v):string{$j=json_encode(norm($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($j===false)throw new RuntimeException('JSON canonicalization failed');return $j;}
function result(bool $ok,array $errors=[],array $extra=[]):never{echo json_encode(array_merge(['schema'=>'easyit.assistant.trust-offline-verification.v1','ok'=>$ok,'verdict'=>$ok?'PASS':'FAIL','errors'=>$errors,'checkedAt'=>gmdate('c')],$extra),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";exit($ok?0:2);}
try{
$base=__DIR__;$manifest=json_decode((string)file_get_contents($base.'/offline-manifest.json'),true);if(!is_array($manifest)||($manifest['schema']??'')!=='easyit.assistant.trust-offline-bundle.v1')result(false,['Manifest ungültig.']);
foreach((array)($manifest['files']??[]) as $name=>$sha){$p=$base.'/'.$name;if(!is_file($p)||!hash_equals((string)$sha,(string)hash_file('sha256',$p)))result(false,['Dateiprüfsumme stimmt nicht: '.$name]);}
if(!function_exists('sodium_crypto_sign_verify_detached'))result(false,['ext-sodium fehlt.']);
$trust=json_decode((string)file_get_contents($base.'/trusted-keys.json'),true);$entry=json_decode((string)file_get_contents($base.'/catalog-entry.json'),true);if(!is_array($trust)||!is_array($entry))result(false,['Trust Store oder Katalogeintrag ungültig.']);$payload=(array)($entry['payload']??[]);$sig=(array)($entry['signature']??[]);$keyId=(string)($sig['keyId']??'');$key=$trust['keys'][$keyId]??null;if(!is_array($key))result(false,['Signaturschlüssel fehlt im Trust Store.']);if(($key['status']??'TRUSTED')!=='TRUSTED'||($key['trustLevel']??'RELEASE')!=='RELEASE')result(false,['Signaturschlüssel ist nicht für RELEASE vertrauenswürdig.']);$now=time();foreach(['validFrom'=>false,'validUntil'=>true] as $f=>$isUntil){if(!empty($key[$f])){$t=strtotime((string)$key[$f]);if($t!==false&&((!$isUntil&&$now<$t)||($isUntil&&$now>$t)))result(false,['Signaturschlüssel außerhalb Gültigkeit.']);}}
$seen=[];$cur=$keyId;while($cur!==''){$k=$trust['keys'][$cur]??null;if(!is_array($k)||isset($seen[$cur])||($k['status']??'')==='REVOKED')result(false,['Trust-Chain ungültig.']);$seen[$cur]=true;$prev=(string)($k['previousKeyId']??'');if($prev==='')break;$rot=null;foreach((array)($trust['rotations']??[]) as $r)if(is_array($r)&&($r['newKeyId']??'')===$cur&&($r['previousKeyId']??'')===$prev){$rot=$r;break;}if(!is_array($rot))result(false,['Rotation in Trust-Chain fehlt.']);$old=$trust['keys'][$prev]??null;if(!is_array($old)||($old['status']??'')==='REVOKED')result(false,['Vorheriger Trust-Key ungültig.']);$rp=canon(['schema'=>'easyit.assistant.release-key-transition.v1','previousKeyId'=>$prev,'newKeyId'=>$cur,'newPublicKey'=>(string)($k['publicKey']??''),'rotatedAt'=>(string)($rot['rotatedAt']??'')]);if(!hash_equals((string)($rot['payloadSha256']??''),hash('sha256',$rp)))result(false,['Rotation-Payload-SHA ungültig.']);$op=base64_decode((string)($old['publicKey']??''),true);$np=base64_decode((string)($k['publicKey']??''),true);$os=base64_decode((string)($rot['previousSignature']??''),true);$ns=base64_decode((string)($rot['newSignature']??''),true);if($op===false||$np===false||$os===false||$ns===false||!sodium_crypto_sign_verify_detached($os,$rp,$op)||!sodium_crypto_sign_verify_detached($ns,$rp,$np))result(false,['Rotation-Signatur ungültig.']);$cur=$prev;}
$canonical=canon($payload);if(!hash_equals((string)($entry['payloadSha256']??''),hash('sha256',$canonical)))result(false,['Katalog-Payload-SHA ungültig.']);$pub=base64_decode((string)($key['publicKey']??''),true);$signature=base64_decode((string)($sig['signature']??''),true);if($pub===false||$signature===false||!sodium_crypto_sign_verify_detached($signature,$canonical,$pub))result(false,['Ed25519-Katalogsignatur ungültig.']);if(!is_file($base.'/module.dataform-module.zip')||!hash_equals((string)($payload['packageSha256']??''),(string)hash_file('sha256',$base.'/module.dataform-module.zip')))result(false,['Modulpaket-SHA stimmt nicht.']);if(is_file($base.'/gate-report.json')&&!hash_equals((string)($payload['gateReportSha256']??''),(string)hash_file('sha256',$base.'/gate-report.json')))result(false,['Gate-Berichts-SHA stimmt nicht.']);
if(is_file($base.'/audit.jsonl')){$lines=file($base.'/audit.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$prev='';foreach($lines as $line){$e=json_decode($line,true);if(!is_array($e))result(false,['Audit JSON ungültig.']);$eh=(string)($e['eventHash']??'');$sp=(string)($e['previousHash']??'');$tmp=$e;unset($tmp['eventHash']);if(!hash_equals($sp,$prev)||!hash_equals($eh,hash('sha256',canon($tmp))))result(false,['Audit-Hashkette ungültig.']);$prev=$eh;}}
result(true,[],['catalogId'=>$entry['catalogId']??null,'moduleId'=>$payload['moduleId']??null,'version'=>$payload['version']??null,'keyId'=>$keyId,'packageSha256'=>$payload['packageSha256']??null]);
}catch(Throwable $e){result(false,[$e->getMessage()]);}
PHP;
    }

    /** @return array<string,mixed> */
    private function manifest(string $schema,array $files,array $extra=[]):array{$hashes=[];foreach($files as $name=>$content)$hashes[$name]=hash('sha256',$content);return array_merge(['schema'=>$schema,'createdAt'=>gmdate('c'),'files'=>$hashes],$extra);}
    private function verifyManifest(array $manifest,array $files,string $schema):void{if(($manifest['schema']??'')!==$schema)throw new \RuntimeException('Bundle-Manifest-Schema ist ungültig.');foreach((array)($manifest['files']??[]) as $name=>$sha){if(!array_key_exists((string)$name,$files)||!hash_equals((string)$sha,hash('sha256',(string)$files[$name])))throw new \RuntimeException('Bundle-Dateiprüfsumme stimmt nicht: '.(string)$name);}}
    /** @return array<string,string> */private function readZipStrict(string $path,array $allowed):array{try{$z=new \PharData($path);}catch(\Throwable $e){throw new \RuntimeException('ZIP kann nicht geöffnet werden: '.$e->getMessage(),0,$e);}$out=[];$allowedMap=array_fill_keys($allowed,true);foreach($this->archiveEntries($z,$path) as $n){if($n===''||str_starts_with($n,'/')||preg_match('#(^|/)\.\.(/|$)#',$n)||str_contains($n,'\\')||!isset($allowedMap[$n])){unset($z);throw new \RuntimeException('Unerwarteter oder unsicherer ZIP-Eintrag: '.$n);}$out[$n]=(string)$z[$n]->getContent();}unset($z);foreach($allowed as $name)if(!array_key_exists($name,$out))throw new \RuntimeException('Erforderlicher ZIP-Eintrag fehlt: '.$name);return $out;}
    private function writeZip(string $path,array $files):void{if(is_file($path))@unlink($path);try{$z=new \PharData($path,0,null,\Phar::ZIP);foreach($files as $name=>$content)$z->addFromString($name,$content);unset($z);}catch(\Throwable $e){@unlink($path);throw new \RuntimeException('ZIP kann nicht erstellt werden: '.$e->getMessage(),0,$e);}@chmod($path,0640);}
    /** @return list<string> */private function archiveEntries(\PharData $phar,string $path):array{$prefix='phar://'.str_replace('\\','/',$path).'/';$out=[];$it=new \RecursiveIteratorIterator($phar);foreach($it as $f){$p=str_replace('\\','/',$f->getPathname());if(str_starts_with($p,$prefix))$p=substr($p,strlen($prefix));if($p!==''&&!str_ends_with($p,'/'))$out[]=$p;}return $out;}
    /** @return array<string,mixed> */private function loadRegistry():array{if(!is_file($this->registryPath))return ['schema'=>'easyit.assistant.release-trust-store.v2','activeKeyId'=>null,'keys'=>[],'rotations'=>[],'recoveryTransitions'=>[]];$d=json_decode((string)file_get_contents($this->registryPath),true);return is_array($d)?array_merge(['schema'=>'easyit.assistant.release-trust-store.v2','activeKeyId'=>null,'keys'=>[],'rotations'=>[],'recoveryTransitions'=>[]],$d):[];}
    /** @return array<string,mixed> */private function loadPolicy():array{if(!is_file($this->policyPath))return ['schema'=>'easyit.assistant.release-trust-policy.v1','installMinimumTrustLevel'=>'RELEASE','historicalMinimumTrustLevel'=>'VERIFY_ONLY','blockSuspended'=>true,'blockRevoked'=>true,'blockExpired'=>true];$d=json_decode((string)file_get_contents($this->policyPath),true);return is_array($d)?$d:[];}
    /** @return array<string,mixed> */private function loadCatalog():array{if(!is_file($this->catalogPath))return ['schema'=>'easyit.assistant.release-catalog.v1','entries'=>[]];$d=json_decode((string)file_get_contents($this->catalogPath),true);return is_array($d)?$d:['schema'=>'easyit.assistant.release-catalog.v1','entries'=>[]];}
    private function mergeCatalog(array $incoming):void{$local=$this->loadCatalog();foreach((array)($incoming['entries']??[]) as $id=>$entry)if(is_array($entry)&&!isset($local['entries'][$id]))$local['entries'][$id]=$entry;$local['updatedAt']=gmdate('c');$this->atomicJson($this->catalogPath,$local,0640);}
    private function audit(string $action,string $keyId,array $details):void{$this->ensureDir($this->trustDir);$previous='';if(is_file($this->auditPath)){$lines=file($this->auditPath,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[];$last=end($lines);if(is_string($last)){$e=json_decode($last,true);if(is_array($e))$previous=(string)($e['eventHash']??'');}}$event=['schema'=>'easyit.assistant.release-trust-audit-event.v1','eventId'=>'trust-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)),'action'=>$action,'keyId'=>$keyId,'occurredAt'=>gmdate('c'),'previousHash'=>$previous,'details'=>$details];$event['eventHash']=hash('sha256',$this->canonical($event));file_put_contents($this->auditPath,$this->json($event)."\n",FILE_APPEND|LOCK_EX);@chmod($this->auditPath,0640);}
    private function privatePath(string $id):string{if(!preg_match('/^ed25519-[a-f0-9]{24}$/',$id))throw new \InvalidArgumentException('Ungültige Key-ID.');return $this->privateDir.'/'.$id.'.key';}
    private function atomicJson(string $path,array $data,int $mode):void{$this->ensureDir(dirname($path));$tmp=$path.'.tmp.'.bin2hex(random_bytes(4));$j=$this->pretty($data);if(file_put_contents($tmp,$j,LOCK_EX)===false||!rename($tmp,$path)){@unlink($tmp);throw new \RuntimeException('Datei kann nicht atomar geschrieben werden: '.$path);}@chmod($path,$mode);}
    private function ensureDir(string $dir):void{if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Verzeichnis kann nicht erstellt werden: '.$dir);}
    private function countFiles(string $dir,string $pattern):int{return is_dir($dir)?count(glob($dir.'/'.$pattern)?:[]):0;}
    private function pretty(array $data):string{return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}
    private function json(array $data):string{return json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    /** @return array<string,mixed> */private function decodeJson(string $raw,string $what):array{$d=json_decode($raw,true);if(!is_array($d))throw new \RuntimeException($what.' ist kein gültiges JSON.');return $d;}
    /** @param mixed $v */private function normalize($v){if(!is_array($v))return $v;if(array_is_list($v))return array_map(fn($x)=>$this->normalize($x),$v);ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=$this->normalize($x);return $v;}
    private function canonical(array $v):string{return json_encode($this->normalize($v),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function safe(string $s):string{return trim((string)preg_replace('/[^A-Za-z0-9._-]+/','-',trim($s)),'-')?:'module';}
    private function assertSodium():void{if(!function_exists('sodium_crypto_pwhash')||!function_exists('sodium_crypto_secretbox'))throw new \RuntimeException('Für Trust-Recovery wird ext-sodium benötigt.');}
    private function rrmdir(string $dir):void{if(!is_dir($dir))return;$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){$p=$f->getPathname();$f->isDir()?@rmdir($p):@unlink($p);}@rmdir($dir);}
}
