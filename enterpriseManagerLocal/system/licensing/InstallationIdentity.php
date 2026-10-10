<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;
final class InstallationIdentity
{
 public function __construct(private string $dir){if(!extension_loaded('sodium'))throw new \RuntimeException('PHP sodium extension is required.');if(!is_dir($dir))@mkdir($dir,0700,true);}
 public function ensure():array{$idFile=$this->dir.'/installation_id';$secFile=$this->dir.'/installation_private.key';$pubFile=$this->dir.'/installation_public.key';if(!is_file($idFile)||!is_file($secFile)||!is_file($pubFile)){$pair=sodium_crypto_sign_keypair();$sec=sodium_crypto_sign_secretkey($pair);$pub=sodium_crypto_sign_publickey($pair);$id='INST-'.bin2hex(random_bytes(16));file_put_contents($idFile,$id.PHP_EOL);file_put_contents($secFile,base64_encode($sec).PHP_EOL);file_put_contents($pubFile,base64_encode($pub).PHP_EOL);@chmod($idFile,0600);@chmod($secFile,0600);@chmod($pubFile,0644);}return ['installation_id'=>trim((string)file_get_contents($idFile)),'private_key'=>base64_decode(trim((string)file_get_contents($secFile)),true),'public_key'=>trim((string)file_get_contents($pubFile))];}
 public function state():array{$f=$this->dir.'/license_state.json';if(!is_file($f))return[];$d=json_decode((string)file_get_contents($f),true);return is_array($d)?$d:[];}
 public function saveState(array $s):void{file_put_contents($this->dir.'/license_state.json',json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));@chmod($this->dir.'/license_state.json',0600);}
}
