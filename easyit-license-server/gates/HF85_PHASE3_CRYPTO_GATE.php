<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/src/Security/KeyCodec.php';
require $root.'/src/Security/ServerSigner.php';
use EasyIT\LicenseServer\Security\KeyCodec;
use EasyIT\LicenseServer\Security\ServerSigner;
if(!extension_loaded('sodium')){fwrite(STDERR,"FAIL sodium missing\n");exit(1);}
$pair=sodium_crypto_sign_keypair();
$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);
$dir=sys_get_temp_dir().'/hf85p3_'.bin2hex(random_bytes(6));mkdir($dir,0700,true);
$sf=$dir.'/secret.key';$pf=$dir.'/public.key';file_put_contents($sf,KeyCodec::encode($secret));file_put_contents($pf,KeyCodec::encode($public));
try{
 $signer=new ServerSigner('hf85-phase3-test',$sf,$pf);
 $msg='DFT1 test '.bin2hex(random_bytes(16));$sig=$signer->sign($msg);
 $ok1=$signer->verify($msg,$sig);$ok2=!$signer->verify($msg.'x',$sig);
 echo ($ok1?'PASS':'FAIL')." valid Ed25519 signature\n";
 echo ($ok2?'PASS':'FAIL')." tampered payload rejected\n";
 if(!$ok1||!$ok2)exit(1);
 echo "HF85_PHASE3_CRYPTO_GATE PASS\n";
}finally{@unlink($sf);@unlink($pf);@rmdir($dir);}
