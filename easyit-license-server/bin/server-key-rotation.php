<?php
declare(strict_types=1);
$config=require dirname(__DIR__).'/bootstrap.php';

use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Security\KeyCodec;

if(!extension_loaded('sodium')){fwrite(STDERR,"ERROR sodium extension missing\n");exit(2);}

$args=[];foreach(array_slice($argv,2) as $a){if(str_starts_with($a,'--')&&str_contains($a,'=')){[$k,$v]=explode('=',substr($a,2),2);$args[$k]=$v;}}
$cmd=$argv[1]??'status';$root=dirname(__DIR__);$security=(array)($config['security']??[]);
$keyringFile=(string)($security['server_keyring_file']??($root.'/secure/keys/server-keyring.json'));
$legacyId=(string)($security['server_key_id']??'SERVER-2026-01');
$legacyPrivate=(string)($security['server_private_key_file']??($root.'/secure/keys/server-private.key'));
$legacyPublic=(string)($security['server_public_key_file']??($root.'/secure/keys/server-public.key'));
$grace=max(0,(int)($security['server_key_rotation_grace_seconds']??86400));
$force=(string)($args['force']??'0')==='1';

$usage=static function():never{echo <<<TXT
HF86 Phase 11 - Server signing key rotation

Commands:
  status
  prepare --key-id=SERVER-YYYY-NN
  activate --key-id=SERVER-YYYY-NN [--force=1]
  revoke --key-id=OLD-KEY-ID [--force=1]

Safe sequence:
  1. prepare B  -> A remains active, B is distributed as staged.
  2. Wait until status shows all active installations trust B.
  3. activate B -> B signs primarily, A signs responses as retiring alternate key.
  4. Wait until all active DataForms have received B-tokens and grace elapsed.
  5. revoke A   -> A is no longer accepted for signatures/tokens.

TXT;exit(0);};

$validateId=static function(string $id):string{
    $id=trim($id);if($id===''||preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$id)!==1)throw new RuntimeException('SERVER_KEY_ID_INVALID');return$id;
};
$loadRing=function()use($keyringFile,$legacyId,$legacyPrivate,$legacyPublic):array{
    if(is_file($keyringFile)){
        $r=json_decode((string)file_get_contents($keyringFile),true);if(!is_array($r)||((int)($r['version']??0))!==1)throw new RuntimeException('SERVER_KEYRING_INVALID');return$r;
    }
    if(!is_file($legacyPrivate)||!is_file($legacyPublic))throw new RuntimeException('SERVER_SIGNING_KEY_MISSING');
    return ['version'=>1,'active_key_id'=>$legacyId,'keys'=>[$legacyId=>[
        'key_id'=>$legacyId,'public_file'=>$legacyPublic,'private_file'=>$legacyPrivate,'status'=>'active','created_at'=>null,'activated_at'=>time(),'retired_at'=>null,'revoked_at'=>null,
    ]],'transition'=>null,'updated_at'=>time()];
};
$saveRing=static function(array $ring)use($keyringFile):void{
    $dir=dirname($keyringFile);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('SERVER_KEYRING_DIR_CREATE_FAILED');
    $ring['updated_at']=time();$json=json_encode($ring,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    $tmp=$keyringFile.'.tmp.'.bin2hex(random_bytes(4));if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('SERVER_KEYRING_WRITE_FAILED');@chmod($tmp,0600);if(!@rename($tmp,$keyringFile)){@unlink($tmp);throw new RuntimeException('SERVER_KEYRING_WRITE_FAILED');}@chmod($keyringFile,0600);
};

$pdo=Connection::create($config['database']);(new MigrationRunner($pdo))->migrate();$audit=new AuditService($pdo);
$status=function(array $ring)use($pdo):array{
    $active=(string)($ring['active_key_id']??'');$keys=(array)($ring['keys']??[]);$trust=[];
    foreach($keys as $id=>$row){
        $q=$pdo->prepare("SELECT COUNT(*) c FROM installations i WHERE i.status IN ('active','blocked') AND NOT EXISTS (SELECT 1 FROM installation_server_key_trust t WHERE t.installation_id=i.installation_id AND t.key_id=? AND t.status='trusted')");$q->execute([(string)$id]);
        $trust[(string)$id]=['missing_nonterminal_installations'=>(int)($q->fetch()['c']??0)];
    }
    $q=$pdo->prepare("SELECT COUNT(*) c FROM license_dataforms d JOIN installations i ON i.installation_id=d.installation_id WHERE d.status='approved' AND i.status='active' AND COALESCE(d.token_last_issued_key_id,'')<>?");$q->execute([$active]);
    $tokenMissing=(int)($q->fetch()['c']??0);
    return ['active_key_id'=>$active,'keys'=>$keys,'transition'=>$ring['transition']??null,'trust'=>$trust,'approved_dataforms_not_reissued_by_active_key'=>$tokenMissing];
};

try{
    if($cmd==='help'||$cmd==='--help'||$cmd==='-h')$usage();
    $ring=$loadRing();
    if($cmd==='status'){
        echo json_encode($status($ring),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).PHP_EOL;exit(0);
    }
    if($cmd==='prepare'){
        $newId=$validateId((string)($args['key-id']??''));if(isset($ring['keys'][$newId]))throw new RuntimeException('SERVER_KEY_ALREADY_EXISTS');
        $active=(string)$ring['active_key_id'];$dir=dirname($keyringFile);if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('SERVER_KEYRING_DIR_CREATE_FAILED');
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',$newId);$sf=$dir.'/server-'.$safe.'-private.key';$pf=$dir.'/server-'.$safe.'-public.key';if(is_file($sf)||is_file($pf))throw new RuntimeException('SERVER_KEY_FILE_EXISTS');
        $pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);
        if(file_put_contents($sf,KeyCodec::encode($secret).PHP_EOL,LOCK_EX)===false||file_put_contents($pf,KeyCodec::encode($public).PHP_EOL,LOCK_EX)===false)throw new RuntimeException('SERVER_KEY_WRITE_FAILED');@chmod($sf,0600);@chmod($pf,0644);
        $now=time();$ring['keys'][$newId]=['key_id'=>$newId,'public_file'=>$pf,'private_file'=>$sf,'status'=>'staged','created_at'=>$now,'activated_at'=>null,'retired_at'=>null,'revoked_at'=>null];
        $ring['transition']=['previous_key_id'=>$active,'new_key_id'=>$newId,'status'=>'staged','prepared_at'=>$now,'activated_at'=>null,'revoked_previous_at'=>null];$saveRing($ring);
        $audit->write('SERVER_KEY_ROTATION_PREPARED','server_key',$newId,'system','cli',null,['active_key_id'=>$active],['new_key_id'=>$newId,'public_key'=>KeyCodec::encode($public)]);
        echo "prepared=$newId\nactive=$active\npublic_key=".KeyCodec::encode($public)."\n";exit(0);
    }
    if($cmd==='activate'){
        $newId=$validateId((string)($args['key-id']??''));$row=$ring['keys'][$newId]??null;if(!is_array($row)||(string)($row['status']??'')!=='staged')throw new RuntimeException('SERVER_KEY_NOT_STAGED');
        $active=(string)$ring['active_key_id'];if($active===$newId)throw new RuntimeException('SERVER_KEY_ALREADY_ACTIVE');
        $q=$pdo->prepare("SELECT i.installation_id FROM installations i WHERE i.status IN ('active','blocked') AND NOT EXISTS (SELECT 1 FROM installation_server_key_trust t WHERE t.installation_id=i.installation_id AND t.key_id=? AND t.status='trusted') ORDER BY i.installation_id");$q->execute([$newId]);$missing=$q->fetchAll(PDO::FETCH_COLUMN);
        if($missing&&!$force)throw new RuntimeException('SERVER_KEY_NOT_ACKNOWLEDGED_BY_NONTERMINAL_INSTALLATIONS:'.count($missing).':'.implode(',',array_slice($missing,0,20)));
        $now=time();$ring['keys'][$active]['status']='retiring';$ring['keys'][$active]['retired_at']=$now;$ring['keys'][$newId]['status']='active';$ring['keys'][$newId]['activated_at']=$now;$ring['active_key_id']=$newId;
        $ring['transition']=['previous_key_id'=>$active,'new_key_id'=>$newId,'status'=>'active','prepared_at'=>$ring['transition']['prepared_at']??null,'activated_at'=>$now,'revoked_previous_at'=>null];$saveRing($ring);
        $audit->write('SERVER_KEY_ROTATION_ACTIVATED','server_key',$newId,'system','cli',null,['previous_key_id'=>$active],['active_key_id'=>$newId,'forced'=>$force,'missing_ack_count'=>count($missing)]);
        echo "active=$newId\nretiring=$active\nmissing_ack_count=".count($missing)."\n";exit(0);
    }
    if($cmd==='revoke'){
        $oldId=$validateId((string)($args['key-id']??''));$row=$ring['keys'][$oldId]??null;if(!is_array($row)||(string)($row['status']??'')!=='retiring')throw new RuntimeException('SERVER_KEY_NOT_RETIRING');
        $active=(string)$ring['active_key_id'];if($oldId===$active)throw new RuntimeException('SERVER_KEY_ACTIVE_CANNOT_REVOKE');
        $q=$pdo->prepare("SELECT i.installation_id FROM installations i WHERE i.status IN ('active','blocked') AND NOT EXISTS (SELECT 1 FROM installation_server_key_trust t WHERE t.installation_id=i.installation_id AND t.key_id=? AND t.status='trusted') ORDER BY i.installation_id");$q->execute([$active]);$missing=$q->fetchAll(PDO::FETCH_COLUMN);
        $q=$pdo->prepare("SELECT d.dataform_uuid FROM license_dataforms d JOIN installations i ON i.installation_id=d.installation_id WHERE d.status='approved' AND i.status='active' AND COALESCE(d.token_last_issued_key_id,'')<>? ORDER BY d.dataform_uuid");$q->execute([$active]);$stale=$q->fetchAll(PDO::FETCH_COLUMN);
        $activated=(int)($ring['transition']['activated_at']??0);$graceRemaining=$activated>0?max(0,$activated+$grace-time()):$grace;
        if(!$force&&($missing||$stale||$graceRemaining>0))throw new RuntimeException('SERVER_KEY_REVOKE_NOT_READY:missing_installations='.count($missing).',stale_dataforms='.count($stale).',grace_remaining='.$graceRemaining);
        $now=time();$ring['keys'][$oldId]['status']='revoked';$ring['keys'][$oldId]['revoked_at']=$now;if(is_array($ring['transition']??null)&&($ring['transition']['previous_key_id']??'')===$oldId){$ring['transition']['status']='completed';$ring['transition']['revoked_previous_at']=$now;}$saveRing($ring);
        $audit->write('SERVER_KEY_ROTATION_REVOKED','server_key',$oldId,'system','cli',null,['status'=>'retiring'],['status'=>'revoked','active_key_id'=>$active,'forced'=>$force,'missing_ack_count'=>count($missing),'stale_dataforms'=>count($stale)]);
        echo "revoked=$oldId\nactive=$active\n";exit(0);
    }
    $usage();
}catch(Throwable $e){fwrite(STDERR,'ERROR '.$e->getMessage().PHP_EOL);exit(1);}
