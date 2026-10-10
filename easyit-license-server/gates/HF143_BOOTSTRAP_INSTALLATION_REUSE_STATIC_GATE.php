<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$svc=(string)file_get_contents($root.'/src/Service/BootstrapActivationService.php');
$app=(string)file_get_contents($root.'/src/App/Application.php');
$checks=[];
$checks[]=['issue accepts current installation',str_contains($svc,'?string $currentInstallationId=null')];
$checks[]=['cleanup is bootstrap-only',str_contains($svc,"license_id=? AND installation_id<>? AND status IN ('active','blocked')")&&str_contains($svc,'self::LICENSE_ID,$currentInstallationId')];
$checks[]=['old bootstrap installation becomes replaced',str_contains($svc,"status='replaced'")];
$checks[]=['old installation keys revoked',str_contains($svc,"UPDATE installation_keys SET status='revoked'")];
$checks[]=['old runtime sessions revoked',str_contains($svc,"UPDATE runtime_sessions SET status='revoked'")];
$checks[]=['audit replacement',str_contains($svc,'BOOTSTRAP_INSTALLATION_REPLACED')];
$needle="issue('local-enterprise-setup',(string)\$data['installation_id'])";
$checks[]=['local route passes current installation id',str_contains($app,$needle)];
$checks[]=['loopback restriction retained',str_contains($app,'BOOTSTRAP_LOCAL_ONLY')&&str_contains($app,"['127.0.0.1','::1']")];
$fails=[];
foreach($checks as [$name,$ok]){echo ($ok?'PASS':'FAIL')." | $name\n";if(!$ok)$fails[]=$name;}
if($fails){echo 'HF143_BOOTSTRAP_INSTALLATION_REUSE_FAILED'.PHP_EOL;exit(1);}
echo 'HF143_BOOTSTRAP_INSTALLATION_REUSE_OK'.PHP_EOL;
