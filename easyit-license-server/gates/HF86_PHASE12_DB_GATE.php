<?php
declare(strict_types=1);

/**
 * HF86 Phase 12 real database end-gate.
 *
 * IMPORTANT: This gate creates a disposable namespace (database on MySQL/MariaDB,
 * schema on PostgreSQL) and destroys it afterwards. It never runs the destructive
 * test cases directly in the configured production namespace.
 *
 * Examples:
 *   php gates/HF86_PHASE12_DB_GATE.php --dsn="mysql:host=127.0.0.1;port=3306;charset=utf8mb4" --user=root --password=""
 *   php gates/HF86_PHASE12_DB_GATE.php --dsn="pgsql:host=127.0.0.1;port=5432;dbname=postgres" --user=postgres --password=secret
 */

$root=dirname(__DIR__);
require $root.'/bootstrap.php';

use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Service\LicenseService;
use EasyIT\LicenseServer\Service\ActivationService;
use EasyIT\LicenseServer\Service\InstallationLifecycleService;
use EasyIT\LicenseServer\Service\SurfacePolicyService;
use EasyIT\LicenseServer\Service\CustomerCredentialService;
use EasyIT\LicenseServer\Security\KeyCodec;

$args=[];
foreach(array_slice($argv,1) as $a){
    if(str_starts_with($a,'--')&&str_contains($a,'=')){
        [$k,$v]=explode('=',substr($a,2),2);$args[$k]=$v;
    }
}
$dsn=(string)($args['dsn']??getenv('HF86_DB_DSN')?:'');
$user=(string)($args['user']??getenv('HF86_DB_USER')?:'');
$password=(string)($args['password']??getenv('HF86_DB_PASSWORD')?:'');
$label=(string)($args['label']??getenv('HF86_DB_LABEL')?:'');
$keep=(string)($args['keep']??'0')==='1';
if($dsn===''){
    fwrite(STDERR,"ERROR HF86_DB_DSN/--dsn is required. Use a MariaDB/MySQL or PostgreSQL server.\n");exit(2);
}
$driver=strtolower((string)strtok($dsn,':'));
if(!in_array($driver,['mysql','pgsql'],true)){
    fwrite(STDERR,"ERROR unsupported driver: {$driver}. Expected mysql or pgsql.\n");exit(2);
}
if(!in_array($driver,PDO::getAvailableDrivers(),true)){
    fwrite(STDERR,"ERROR PDO driver '{$driver}' is not installed in this PHP runtime. Available: ".implode(',',PDO::getAvailableDrivers())."\n");exit(2);
}
if(!extension_loaded('sodium')){
    fwrite(STDERR,"ERROR sodium extension is required.\n");exit(2);
}

$checks=[];$ok=true;
$check=function(string $name,bool $pass,string $detail='')use(&$checks,&$ok):void{
    $checks[]=[$name,$pass,$detail];$ok=$ok&&$pass;
    echo ($pass?'PASS ':'FAIL ').str_pad($name,48).($detail!==''?' '.$detail:'').PHP_EOL;
};
$expectCode=function(callable $fn,string $code)use($check):void{
    try{$fn();$check('expected '.$code,false,'no exception');}
    catch(Throwable $e){$check('expected '.$code,$e->getMessage()===$code,'got='.$e->getMessage());}
};

$gateId='hf86_gate_'.date('Ymd_His').'_'.bin2hex(random_bytes(3));
$adminPdo=null;$pdo=null;$pdo2=null;$cleanup=null;$namespace='';$testDsn='';

$quoteIdent=static function(string $id,string $driver):string{
    if(preg_match('/^[A-Za-z0-9_]+$/',$id)!==1)throw new RuntimeException('unsafe identifier');
    return $driver==='mysql'?'`'.$id.'`':'"'.$id.'"';
};
$replaceMysqlDb=static function(string $dsn,string $db):string{
    $body=substr($dsn,6);$parts=array_values(array_filter(explode(';',$body),static fn($v)=>$v!==''));$out=[];$found=false;
    foreach($parts as $p){if(str_starts_with(strtolower($p),'dbname=')){if($db!=='')$out[]='dbname='.$db;$found=true;}else{$out[]=$p;}}
    if(!$found&&$db!=='')$out[]='dbname='.$db;
    return 'mysql:'.implode(';',$out);
};

try{
    if($driver==='mysql'){
        $namespace=$gateId;
        $adminDsn=$replaceMysqlDb($dsn,'');
        $adminPdo=Connection::create(['dsn'=>$adminDsn,'user'=>$user,'password'=>$password]);
        $q=$quoteIdent($namespace,$driver);
        $adminPdo->exec("CREATE DATABASE {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $testDsn=$replaceMysqlDb($dsn,$namespace);
        $pdo=Connection::create(['dsn'=>$testDsn,'user'=>$user,'password'=>$password]);
        $pdo2=Connection::create(['dsn'=>$testDsn,'user'=>$user,'password'=>$password]);
        $cleanup=static function()use($adminPdo,$q):void{$adminPdo->exec("DROP DATABASE IF EXISTS {$q}");};
    }else{
        $namespace=$gateId;
        $adminPdo=Connection::create(['dsn'=>$dsn,'user'=>$user,'password'=>$password]);
        $q=$quoteIdent($namespace,$driver);
        $adminPdo->exec("CREATE SCHEMA {$q}");
        $pdo=Connection::create(['dsn'=>$dsn,'user'=>$user,'password'=>$password]);
        $pdo2=Connection::create(['dsn'=>$dsn,'user'=>$user,'password'=>$password]);
        $pdo->exec("SET search_path TO {$q}");$pdo2->exec("SET search_path TO {$q}");
        $cleanup=static function()use($adminPdo,$q):void{$adminPdo->exec("DROP SCHEMA IF EXISTS {$q} CASCADE");};
        $testDsn=$dsn.';search_path='.$namespace;
    }
    $check('temporary namespace created',true,$driver.':'.$namespace);

    $runner=new MigrationRunner($pdo);$runner->migrate();$runner->migrate();
    $requiredTables=['customers','licenses','modules','license_modules','capability_registry','license_policies','license_capabilities','license_dataform_usage','license_dataforms','license_dataform_capabilities','installations','installation_keys','activation_events','dataform_definitions','runtime_sessions','runtime_actions','used_nonces','audit_events','admin_users','customer_allowed_domains','customer_allowed_networks','customer_users','customer_roles','customer_role_capabilities','customer_user_roles','customer_role_resource_scopes','customer_role_projects','customer_role_dataforms','customer_user_resource_scopes','customer_user_projects','customer_user_dataforms','customer_access_requests','customer_account_sessions','installation_server_key_trust'];
    foreach($requiredTables as $table){
        if($driver==='mysql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$st->execute([$table]);}
        else{$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=?');$st->execute([$table]);}
        $check('table '.$table,(int)$st->fetchColumn()===1);
    }
    $check('migration idempotent',true);
    $check('module seeds',(int)$pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn()>=5);
    $check('capability seeds',(int)$pdo->query('SELECT COUNT(*) FROM capability_registry')->fetchColumn()>=19);

    if($driver==='mysql'){
        $st=$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_collation='utf8mb4_unicode_ci'");
        $check('mysql utf8mb4_unicode_ci',(int)$st->fetchColumn()>=count($requiredTables));
    }else{
        $check('postgres current_schema',$pdo->query('SELECT current_schema()')->fetchColumn()===$namespace,(string)$pdo->query('SELECT current_schema()')->fetchColumn());
    }

    // Upgrade/idempotency check: remove columns added over HF85/HF86 and let MigrationRunner restore them.
    $dropColumns=[
        ['customers','surface_self_service'],['customer_users','credential_version'],['runtime_sessions','surface_origin'],['runtime_sessions','source_ip'],
        ['license_dataforms','released_at'],['license_dataforms','release_request_id'],['license_dataforms','token_last_issued_key_id'],['license_dataforms','token_last_issued_at'],
    ];
    foreach($dropColumns as [$table,$column]){$pdo->exec('ALTER TABLE '.$quoteIdent($table,$driver).' DROP COLUMN '.$quoteIdent($column,$driver));}
    $runner->migrate();
    $missing=0;
    foreach($dropColumns as [$table,$column]){
        if($driver==='mysql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$st->execute([$table,$column]);}
        else{$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?');$st->execute([$table,$column]);}
        if((int)$st->fetchColumn()!==1)$missing++;
    }
    $check('upgrade columns restored',$missing===0,'missing='.$missing);

    $now=time();$customer='CUS-GATE';$license='LIC-GATE';$licenseNo='LIC-HF86-GATE';$secret='GateSecret-'.bin2hex(random_bytes(8));
    $pdo->prepare('INSERT INTO customers (customer_id,customer_number,name,company,email,status,created_at,updated_at,surface_domain_required,surface_ip_required,surface_self_service) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$customer,'CNO-GATE','HF86 Gate','HF86 Gate','gate@example.invalid','active',$now,$now,1,1,1]);
    $pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$license,$licenseNo,password_hash($secret,PASSWORD_DEFAULT),$customer,'active',$now-60,$now+3600,1,3600,86400,1,$now,$now]);
    $pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)')->execute([$license,1,$now,$now]);
    $pdo->prepare("INSERT INTO license_modules (license_id,module_code,enabled,valid_from,valid_until,configuration) VALUES (?,?,'1',NULL,NULL,NULL)")->execute([$license,'dataform']);

    // Transaction rollback.
    $pdo->beginTransaction();$pdo->prepare('INSERT INTO audit_events (audit_id,event_type,entity_type,entity_id,actor_type,actor_id,old_value,new_value,request_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute(['AUD-ROLLBACK','ROLLBACK_TEST','gate','x','system','gate',null,null,null,$now]);$pdo->rollBack();
    $st=$pdo->prepare('SELECT COUNT(*) FROM audit_events WHERE audit_id=?');$st->execute(['AUD-ROLLBACK']);$check('transaction rollback',(int)$st->fetchColumn()===0);

    $licenseService=new LicenseService($pdo);
    $r=$licenseService->claimDataform($license,'P1','DF1');$check('dataform first claim',($r['claimed']??false)===true&&($r['used_dataforms']??0)===1);
    $r=$licenseService->claimDataform($license,'P1','DF1');$check('dataform idempotent claim',($r['already_active']??false)===true&&($r['used_dataforms']??0)===1);
    $expectCode(fn()=>$licenseService->claimDataform($license,'P1','DF2'),'DATAFORM_LIMIT_REACHED');
    $check('dataform release',$licenseService->releaseDataform($license,'P1','DF1')===true);
    $r=$licenseService->claimDataform($license,'P1','DF2');$check('dataform slot reusable',($r['claimed']??false)===true&&($r['used_dataforms']??0)===1);

    // Installation slot semantics: blocked still consumes the only slot.
    $pair=sodium_crypto_sign_keypair();$pub=sodium_crypto_sign_publickey($pair);$iid='INST-GATE-1';$keyId='IK-GATE-1';
    $pdo->prepare('INSERT INTO installations (installation_id,license_id,status,generation,product_code,product_version,environment_hash,activated_at,last_seen_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$iid,$license,'active',1,'easyIT-enterprise','gate','gate',$now,$now,$now,$now]);
    $pdo->prepare('INSERT INTO installation_keys (key_id,installation_id,algorithm,public_key,status,valid_from,valid_until,created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$keyId,$iid,'Ed25519',KeyCodec::encode($pub),'active',$now,null,$now]);
    $pdo->prepare('INSERT INTO runtime_sessions (runtime_id,license_id,installation_id,project_id,dataform_id,definition_id,license_generation,installation_generation,manifest_version,status,issued_at,lease_until,grace_until,last_seen_at,manifest_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute(['RT-GATE',$license,$iid,'P1','DF2','DEF-GATE',1,1,'3','active',$now,$now+3600,$now+7200,$now,'hash']);
    $audit=new AuditService($pdo);$life=new InstallationLifecycleService($pdo,$audit);
    $res=$life->transition($iid,'blocked','system','gate','REQ-GATE',$license);$check('installation block',($res['status']??'')==='blocked');
    $st=$pdo->prepare('SELECT status FROM runtime_sessions WHERE runtime_id=?');$st->execute(['RT-GATE']);$check('runtime revoked on block',$st->fetchColumn()==='revoked');
    $st=$pdo->prepare('SELECT status FROM installation_keys WHERE key_id=?');$st->execute([$keyId]);$check('key survives temporary block',$st->fetchColumn()==='active');
    $check('blocked consumes slot',InstallationLifecycleService::consumesSlot('blocked')===true);

    $activation=new ActivationService($pdo,$licenseService,$audit);$pair2=sodium_crypto_sign_keypair();$pub2=sodium_crypto_sign_publickey($pair2);
    $expectCode(fn()=>$activation->activate(['license_key'=>$licenseNo.'.'.$secret,'installation_id'=>'INST-GATE-2','installation_public_key'=>KeyCodec::encode($pub2),'product'=>['code'=>'easyIT-enterprise','version'=>'gate'],'environment'=>['hash'=>'gate2']],'REQ-ACT-2'),'INSTALLATION_LIMIT_REACHED');
    $res=$life->transition($iid,'active','system','gate','REQ-GATE-2',$license);$check('installation unblock',($res['status']??'')==='active');
    $res=$life->transition($iid,'retired','system','gate','REQ-GATE-3',$license);$check('installation retire terminal',($res['terminal']??false)===true&&($res['status']??'')==='retired');
    $st=$pdo->prepare('SELECT status FROM installation_keys WHERE key_id=?');$st->execute([$keyId]);$check('key revoked on retire',$st->fetchColumn()==='revoked');
    $expectCode(fn()=>$life->transition($iid,'active','system','gate','REQ-GATE-4',$license),'INSTALLATION_TERMINAL');
    $r=$activation->activate(['license_key'=>$licenseNo.'.'.$secret,'installation_id'=>'INST-GATE-2','installation_public_key'=>KeyCodec::encode($pub2),'product'=>['code'=>'easyIT-enterprise','version'=>'gate'],'environment'=>['hash'=>'gate2']],'REQ-ACT-3');
    $check('retired slot reusable',($r['installation_status']??'')==='active');

    // Surface self-lockout safety.
    $surface=new SurfacePolicyService($pdo,[]);
    $pdo->prepare("INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute(['DOM-1',$customer,'gate.example',$now,$now]);
    $pdo->prepare("INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute(['DOM-2',$customer,'other.example',$now,$now]);
    $expectCode(fn()=>$surface->removeDomainRuleSafely($customer,'DOM-1','gate.example'),'SURFACE_DOMAIN_CURRENT_REQUIRED');
    $check('domain removable with alternate current match',$surface->removeDomainRuleSafely($customer,'DOM-1','other.example')===true);
    $expectCode(fn()=>$surface->removeDomainRuleSafely($customer,'DOM-2','other.example'),'SURFACE_DOMAIN_LAST_REQUIRED');
    $pdo->prepare("INSERT INTO customer_allowed_networks (rule_id,customer_id,cidr,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute(['NET-1',$customer,'127.0.0.1/32',$now,$now]);
    $pdo->prepare("INSERT INTO customer_allowed_networks (rule_id,customer_id,cidr,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute(['NET-2',$customer,'10.0.0.0/8',$now,$now]);
    $expectCode(fn()=>$surface->removeNetworkRuleSafely($customer,'NET-1','127.0.0.1'),'SURFACE_IP_CURRENT_REQUIRED');
    $check('network removable with alternate current match',$surface->removeNetworkRuleSafely($customer,'NET-1','10.1.2.3')===true);
    $expectCode(fn()=>$surface->removeNetworkRuleSafely($customer,'NET-2','10.1.2.3'),'SURFACE_IP_LAST_REQUIRED');

    // Credential version + central session revocation.
    $uid='CU-GATE';$oldPassword='OldPassword123';$newPassword='NewPassword456';
    $pdo->prepare('INSERT INTO customer_users (customer_user_id,customer_id,username,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,credential_version,created_at,updated_at,email,license_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$uid,$customer,'gate@example.invalid',password_hash($oldPassword,PASSWORD_DEFAULT),'Gate User','customer_admin','active',0,null,$now,1,$now,$now,'gate@example.invalid',$license]);
    $pdo->prepare("INSERT INTO customer_account_sessions (session_id,customer_user_id,token_hash,status,issued_at,expires_at,last_seen_at,surface_host,created_at) VALUES (?,?,?,'active',?,?,?,?,?)")
        ->execute(['SES-GATE',$uid,hash('sha256','token'),$now,$now+3600,$now,'gate.example',$now]);
    $cred=new CustomerCredentialService($pdo,$audit);$version=$cred->resetPassword($customer,$uid,$newPassword,$newPassword,'system','gate');
    $check('credential version increment',$version===2);
    $st=$pdo->prepare('SELECT status FROM customer_account_sessions WHERE session_id=?');$st->execute(['SES-GATE']);$check('account session revoked',$st->fetchColumn()==='revoked');

    // Rotation DB metadata paths.
    $pdo->prepare("INSERT INTO installation_server_key_trust (installation_id,key_id,status,first_seen_at,last_seen_at) VALUES (?,?, 'trusted',?,?)")->execute(['INST-GATE-2','SERVER-B',$now,$now]);
    $dfUuid='123e4567-e89b-42d3-a456-426614174000';
    $pdo->prepare("INSERT INTO license_dataforms (dataform_uuid,license_id,customer_id,installation_id,project_id,local_dataform_id,name,slug,status,registered_at,last_seen_at,approved_at,revoked_at,released_at,release_request_id,token_last_issued_key_id,token_last_issued_at) VALUES (?,?,?,?,?,?,?,?, 'approved',?,?,?,?,?,?,?,?)")
        ->execute([$dfUuid,$license,$customer,'INST-GATE-2','P1','DF2','Gate DF','gate-df',$now,$now,$now,null,null,null,'SERVER-B',$now]);
    $st=$pdo->prepare("SELECT COUNT(*) FROM installations i WHERE i.status IN ('active','blocked') AND NOT EXISTS (SELECT 1 FROM installation_server_key_trust t WHERE t.installation_id=i.installation_id AND t.key_id=? AND t.status='trusted')");$st->execute(['SERVER-B']);$check('rotation installation readiness',(int)$st->fetchColumn()===0);
    $st=$pdo->prepare("SELECT COUNT(*) FROM license_dataforms d JOIN installations i ON i.installation_id=d.installation_id WHERE d.status='approved' AND i.status='active' AND COALESCE(d.token_last_issued_key_id,'')<>?");$st->execute(['SERVER-B']);$check('rotation dataform readiness',(int)$st->fetchColumn()===0);

    // Real row-lock semantics using two independent PDO connections.
    $pdo->beginTransaction();$s1=$pdo->prepare('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE');$s1->execute([$license]);
    if($driver==='mysql')$pdo2->exec('SET innodb_lock_wait_timeout=1');else $pdo2->exec("SET lock_timeout='1000ms'");
    $locked=false;
    try{$pdo2->beginTransaction();$s2=$pdo2->prepare('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE');$s2->execute([$license]);}
    catch(Throwable $e){$locked=true;if($pdo2->inTransaction())$pdo2->rollBack();}
    if($pdo->inTransaction())$pdo->rollBack();$check('FOR UPDATE blocks competing writer',$locked);
    $pdo2->beginTransaction();$s2=$pdo2->prepare('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE');$s2->execute([$license]);$pdo2->rollBack();$check('FOR UPDATE releases after rollback',true);

    echo "DRIVER={$driver}\nNAMESPACE={$namespace}\nLABEL={$label}\nFINAL STATUS: ".($ok?'PASSED':'FAILED').PHP_EOL;
}catch(Throwable $e){
    $ok=false;echo 'FAIL unhandled exception '.get_class($e).' '.$e->getMessage().PHP_EOL;echo "FINAL STATUS: FAILED\n";
}finally{
    if(!$keep&&is_callable($cleanup)){
        try{$pdo=null;$pdo2=null;$cleanup();echo "CLEANUP: PASSED\n";}catch(Throwable $e){echo 'CLEANUP: FAILED '.$e->getMessage().PHP_EOL;$ok=false;}
    }elseif($keep){echo "CLEANUP: SKIPPED (--keep=1) namespace={$namespace}\n";}
}
exit($ok?0:1);
