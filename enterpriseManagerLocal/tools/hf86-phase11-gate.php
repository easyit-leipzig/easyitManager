<?php
declare(strict_types=1);
$root=dirname(__DIR__);$client=file_get_contents($root.'/system/licensing/LicenseApiClient.php');$ui=file_get_contents($root.'/app/licensing/remote.php');$doc=file_get_contents($root.'/docs/HF86_PHASE11.md');
$ok=true;$c=function(string $l,bool $p)use(&$ok){echo($p?'PASS ':'FAIL ').$l.PHP_EOL;if(!$p)$ok=false;};
$c('dynamic server trust is persisted',str_contains($client,'dynamic_server_keys')&&str_contains($client,'revoked_server_key_ids'));
$c('static and dynamic trust are merged',str_contains($client,'trustedServerKeys'));
$c('server trust is adopted only after response verification',strpos($client,'verifyServerResponse($raw')<strpos($client,'adoptServerTrust($data[\'server_trust\'])'));
$c('key-id public-key substitution is rejected',str_contains($client,'SERVER_TRUST_KEY_CONFLICT'));
$c('retiring alternate signature is accepted',str_contains($client,'x-easyit-server-alt-key-id')&&str_contains($client,'x-easyit-server-alt-signature'));
$c('client acknowledges trusted key ids',str_contains($client,'X-EasyIT-Trusted-Server-Keys'));
$c('runtime manifest uses merged trust store',str_contains($client,'public function verifyManifest')&&str_contains($client,'$keys = $this->trustedServerKeys()'));
$c('activation preserves dynamically learned trust',str_contains($client,'array_merge($existingState'));
$c('normal posts preserve trust learned by the response',substr_count($client,'array_merge($state,$this->identity->state())')>=1);
$c('licensing ui displays active/trusted/revoked keys',str_contains($ui,'Aktiver Server-Signaturschlüssel')&&str_contains($ui,'Vertrauenswürdige Server-Schlüssel')&&str_contains($ui,'Widerrufene Server-Schlüssel'));
$c('phase 11 documentation exists',str_contains($doc,'X-EasyIT-Trusted-Server-Keys'));
echo 'HF86_PHASE11_ENTERPRISE_GATE '.($ok?'PASS':'FAIL').PHP_EOL;exit($ok?0:1);
