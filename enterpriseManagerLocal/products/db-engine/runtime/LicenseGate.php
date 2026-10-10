<?php
declare(strict_types=1);
final class EasyItDbEngineLicenseGate {
 public static function assert(string $root,array $cfg): array {
  if(!extension_loaded('sodium'))throw new RuntimeException('DB_ENGINE_SODIUM_REQUIRED');
  $f=$root.'/license/project-product-token.json';if(!is_file($f))throw new RuntimeException('DB_ENGINE_LICENSE_TOKEN_MISSING');$tok=json_decode((string)file_get_contents($f),true);if(!is_array($tok))throw new RuntimeException('DB_ENGINE_LICENSE_TOKEN_INVALID');
  $p=$tok['payload']??null;$sig=(string)($tok['signature']??'');$pub64=(string)($tok['public_key']??'');if(!is_array($p)||$sig===''||$pub64==='')throw new RuntimeException('DB_ENGINE_LICENSE_TOKEN_INVALID');
  if((string)($p['schema']??'')!=='easyit.project-product-token.v1'||(string)($p['product_code']??'')!=='db-engine')throw new RuntimeException('DB_ENGINE_LICENSE_TOKEN_BINDING_INVALID');
  if((string)($p['project_id']??'')!==(string)($cfg['project_id']??'') && (string)($p['project_slug']??'')!==(string)($cfg['project_slug']??''))throw new RuntimeException('DB_ENGINE_LICENSE_TOKEN_BINDING_INVALID');
  if((int)($p['valid_until']??0)<time())throw new RuntimeException('DB_ENGINE_LICENSE_TOKEN_EXPIRED');
  $caps=array_map('strval',(array)($p['capabilities']??[]));if(!in_array('db-engine.runtime',$caps,true))throw new RuntimeException('DB_ENGINE_RUNTIME_NOT_LICENSED');
  $pub=base64_decode($pub64,true);$signature=base64_decode($sig,true);$json=json_encode($p,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if($pub===false||$signature===false||!sodium_crypto_sign_verify_detached($signature,$json,$pub))throw new RuntimeException('DB_ENGINE_LICENSE_SIGNATURE_INVALID');return $p;
 }
}
