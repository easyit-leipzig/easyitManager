<?php
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/src/Security/KeyCodec.php';require $root.'/src/Security/ServerSigner.php';
use EasyIT\LicenseServer\Security\ServerSigner;
if(!extension_loaded('sodium')){echo "SKIP sodium missing\n";exit(0);} 
$dir=sys_get_temp_dir().'/hf86p11-'.bin2hex(random_bytes(5));mkdir($dir,0700,true);
$mk=function(string $id)use($dir):array{$pair=sodium_crypto_sign_keypair();$sec=sodium_crypto_sign_secretkey($pair);$pub=sodium_crypto_sign_publickey($pair);$sf=$dir.'/'.$id.'.sec';$pf=$dir.'/'.$id.'.pub';file_put_contents($sf,base64_encode($sec));file_put_contents($pf,base64_encode($pub));return[$sf,$pf,$pub];};
[$as,$ap,$apub]=$mk('A');[$bs,$bp,$bpub]=$mk('B');$ring=$dir.'/ring.json';
file_put_contents($ring,json_encode(['version'=>1,'active_key_id'=>'B','keys'=>[
 'A'=>['key_id'=>'A','public_file'=>$ap,'private_file'=>$as,'status'=>'retiring','created_at'=>1,'activated_at'=>1,'retired_at'=>2,'revoked_at'=>null],
 'B'=>['key_id'=>'B','public_file'=>$bp,'private_file'=>$bs,'status'=>'active','created_at'=>2,'activated_at'=>3,'retired_at'=>null,'revoked_at'=>null],
],'transition'=>['previous_key_id'=>'A','new_key_id'=>'B','status'=>'active','prepared_at'=>2,'activated_at'=>3,'revoked_previous_at'=>null]]));
$s=new ServerSigner('A',$as,$ap,$ring);$msg='hf86-phase11';$primary=$s->sign($msg);$alts=$s->alternateSignatures($msg);$bundle=$s->trustBundle();
$ok=true;$c=function(string $l,bool $p)use(&$ok){echo($p?'PASS ':'FAIL ').$l.PHP_EOL;if(!$p)$ok=false;};
$c('B is active signer',$s->keyId()==='B'&&$s->verifyByKeyId($msg,$primary,'B'));
$c('A remains verification key while retiring',$s->verifyByKeyId($msg,$alts[0]['signature']??'','A'));
$c('alternate response signature is A',($alts[0]['key_id']??'')==='A');
$c('trust bundle publishes both keys',count((array)($bundle['keys']??[]))===2&&($bundle['active_key_id']??'')==='B');
// Revoke A: historical signature must become untrusted.
$r=json_decode((string)file_get_contents($ring),true);$r['keys']['A']['status']='revoked';$r['keys']['A']['revoked_at']=4;file_put_contents($ring,json_encode($r));$s2=new ServerSigner('A',$as,$ap,$ring);
$c('revoked A no longer verifies',$s2->verifyByKeyId($msg,$alts[0]['signature']??'','A')===false);
foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);
echo 'HF86_PHASE11_CRYPTO_GATE '.($ok?'PASS':'FAIL').PHP_EOL;exit($ok?0:1);
