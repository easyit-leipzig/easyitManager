<?php
declare(strict_types=1);

/**
 * HF87 Phase 1 real database gate.
 * Creates and destroys a disposable MariaDB/MySQL database or PostgreSQL schema.
 *
 * MariaDB/MySQL:
 * php gates/HF87_PHASE1_DB_GATE.php --dsn="mysql:host=127.0.0.1;port=3306;charset=utf8mb4" --user=root --password=""
 *
 * PostgreSQL:
 * php gates/HF87_PHASE1_DB_GATE.php --dsn="pgsql:host=127.0.0.1;port=5432;dbname=postgres" --user=postgres --password=secret
 */
$root=dirname(__DIR__);require $root.'/bootstrap.php';

use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Service\AccountService;
use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Service\LicenseService;
use EasyIT\LicenseServer\Service\SurfacePolicyService;

$args=[];foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--')&&str_contains($arg,'=')){[$k,$v]=explode('=',substr($arg,2),2);$args[$k]=$v;}
$dsn=(string)($args['dsn']??getenv('HF87_DB_DSN')?:'');$user=(string)($args['user']??getenv('HF87_DB_USER')?:'');$password=(string)($args['password']??getenv('HF87_DB_PASSWORD')?:'');$keep=(string)($args['keep']??'0')==='1';
if($dsn===''){fwrite(STDERR,"ERROR HF87_DB_DSN/--dsn required.\n");exit(2);}
$driver=strtolower((string)strtok($dsn,':'));if(!in_array($driver,['mysql','pgsql'],true)){fwrite(STDERR,"ERROR mysql or pgsql required.\n");exit(2);}if(!in_array($driver,PDO::getAvailableDrivers(),true)){fwrite(STDERR,"ERROR PDO driver {$driver} unavailable.\n");exit(2);}

$ok=true;$check=function(string $name,bool $pass,string $detail='')use(&$ok):void{echo($pass?'PASS ':'FAIL ').str_pad($name,44).($detail!==''?' '.$detail:'').PHP_EOL;if(!$pass)$ok=false;};
$expect=function(callable $fn,string $code)use($check):void{try{$fn();$check('expected '.$code,false,'no exception');}catch(Throwable $e){$check('expected '.$code,$e->getMessage()===$code,'got='.$e->getMessage());}};
$gate='hf87_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));$admin=null;$pdo=null;$cleanup=null;
$quote=static fn(string $v,string $d):string=>$d==='mysql'?'`'.$v.'`':'"'.$v.'"';
$mysqlDsn=static function(string $dsn,string $db):string{$body=substr($dsn,6);$parts=array_values(array_filter(explode(';',$body),fn($v)=>$v!==''));$out=[];$seen=false;foreach($parts as $p){if(str_starts_with(strtolower($p),'dbname=')){$seen=true;if($db!=='')$out[]='dbname='.$db;}else$out[]=$p;}if(!$seen&&$db!=='')$out[]='dbname='.$db;return'mysql:'.implode(';',$out);};

try{
    if($driver==='mysql'){
        $admin=Connection::create(['dsn'=>$mysqlDsn($dsn,''),'user'=>$user,'password'=>$password]);$q=$quote($gate,$driver);$admin->exec("CREATE DATABASE {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$pdo=Connection::create(['dsn'=>$mysqlDsn($dsn,$gate),'user'=>$user,'password'=>$password]);$cleanup=static fn()=>$admin->exec("DROP DATABASE IF EXISTS {$q}");
    }else{
        $admin=Connection::create(['dsn'=>$dsn,'user'=>$user,'password'=>$password]);$q=$quote($gate,$driver);$admin->exec("CREATE SCHEMA {$q}");$pdo=Connection::create(['dsn'=>$dsn,'user'=>$user,'password'=>$password]);$pdo->exec("SET search_path TO {$q}");$cleanup=static fn()=>$admin->exec("DROP SCHEMA IF EXISTS {$q} CASCADE");
    }
    $check('temporary namespace',true,$driver.':'.$gate);
    $runner=new MigrationRunner($pdo);$runner->migrate();$runner->migrate();$check('migration idempotent',true);

    if($driver==='mysql'){$st=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='customer_key'");$idx=$pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='customers' AND index_name='ux_customers_customer_key'");}
    else{$st=$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='customers' AND column_name='customer_key'");$idx=$pdo->query("SELECT COUNT(*) FROM pg_indexes WHERE schemaname=current_schema() AND tablename='customers' AND indexname='ux_customers_customer_key'");}
    $check('customer_key column',(int)$st->fetchColumn()===1);$check('customer_key unique index',(int)$idx->fetchColumn()>=1);

    $audit=new AuditService($pdo);$licenses=new LicenseService($pdo);$surface=new SurfacePolicyService($pdo,[]);$accounts=new AccountService($pdo,$licenses,$audit,$surface);
    $surfaceData=['origin'=>'https://demo.example.test','host'=>'demo.example.test'];$passwordValue='HF87-Demo-Strong-123';
    $reg=$accounts->register(['email'=>'Demo.User@Example.Test','company'=>'HF87 Demo GmbH','first_name'=>'Demo','last_name'=>'User','password'=>$passwordValue,'password_confirm'=>$passwordValue,'requested_domain'=>'demo.example.test','_surface'=>$surfaceData],'HF87-DB-GATE');
    $check('registration returns demo',($reg['manager_mode']??'')==='demo');
    $check('customer key normalized',($reg['customer_key']??'')==='demo.user@example.test');
    $check('registration has no license',($reg['license']??null)===null);
    $token=(string)($reg['account_session_token']??'');$check('account session issued',$token!=='');
    $row=$pdo->query("SELECT customer_id,customer_number,customer_key,status FROM customers WHERE customer_key='demo.user@example.test'")->fetch();
    $check('customer persisted',is_array($row)&&($row['status']??'')==='active');
    $check('legacy number is technical',is_array($row)&&($row['customer_number']??'')===($row['customer_id']??'')&&($row['customer_number']??'')!==($row['customer_key']??''));
    $expect(fn()=>$accounts->register(['email'=>'DEMO.USER@example.test','company'=>'Duplicate','first_name'=>'Dupe','last_name'=>'User','password'=>$passwordValue,'password_confirm'=>$passwordValue,'_surface'=>$surfaceData],'HF87-DUP'),'ACCOUNT_ALREADY_EXISTS');
    $profile=$accounts->profile(['account_session_token'=>$token,'_surface'=>$surfaceData]);$check('profile remains demo',($profile['manager_mode']??'')==='demo');

    $now=time();$licenseId='LIC-HF87-GATE';$pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$licenseId,'LIC-HF87-DB-GATE',password_hash('secret',PASSWORD_DEFAULT),(string)$row['customer_id'],'active',$now-60,$now+3600,1,3600,86400,1,$now,$now]);
    $pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)')->execute([$licenseId,1,$now,$now]);
    $profile=$accounts->profile(['account_session_token'=>$token,'_surface'=>$surfaceData]);$check('late license switches licensed',($profile['manager_mode']??'')==='licensed'&&($profile['license']['id']??'')===$licenseId);
    $assigned=$pdo->prepare('SELECT license_id FROM customer_users WHERE customer_id=?');$assigned->execute([(string)$row['customer_id']]);$check('user binding refreshed',(string)$assigned->fetchColumn()===$licenseId);

    $pdo->prepare("UPDATE licenses SET status='suspended',updated_at=? WHERE license_id=?")->execute([time(),$licenseId]);
    $profile=$accounts->profile(['account_session_token'=>$token,'_surface'=>$surfaceData]);$check('invalid license falls back demo',($profile['manager_mode']??'')==='demo'&&($profile['license']??null)===null);
} catch(Throwable $e){$check('unexpected exception',false,$e->getMessage());}
finally{if(!$keep&&is_callable($cleanup)){try{$cleanup();$check('temporary namespace removed',true);}catch(Throwable $e){$check('temporary namespace removed',false,$e->getMessage());}}elseif($keep)$check('temporary namespace kept',true,$gate);}

echo 'HF87_PHASE1_DB_GATE '.($ok?'PASS':'FAIL').PHP_EOL;exit($ok?0:1);
