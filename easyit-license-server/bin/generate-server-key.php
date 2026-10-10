<?php
declare(strict_types=1);
$config=require dirname(__DIR__).'/bootstrap.php';
if(!extension_loaded('sodium')){fwrite(STDERR,"sodium extension missing\n");exit(2);} $pair=sodium_crypto_sign_keypair();$secret=sodium_crypto_sign_secretkey($pair);$public=sodium_crypto_sign_publickey($pair);$sf=$config['security']['server_private_key_file'];$pf=$config['security']['server_public_key_file'];@mkdir(dirname($sf),0700,true);if(is_file($sf)||is_file($pf)){fwrite(STDERR,"Key files already exist; refusing overwrite.\n");exit(3);}file_put_contents($sf,base64_encode($secret).PHP_EOL);file_put_contents($pf,base64_encode($public).PHP_EOL);@chmod($sf,0600);@chmod($pf,0644);echo "key_id=".$config['security']['server_key_id'].PHP_EOL;echo "public_key=".base64_encode($public).PHP_EOL;
