<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=0;
$check=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$a=(string)file_get_contents($root.'/src/Service/AccountService.php');$adm=(string)file_get_contents($root.'/src/Admin/AdminApp.php');$sign=(string)file_get_contents($root.'/src/Security/ServerSigner.php');
$check('request extension accepted',str_contains($a,"requestType==='extension'"));
$check('extension preserves installation limit',str_contains($a,'$maxInstall=max($maxInstall'));
$check('extension preserves modules',str_contains($a,'$this->licenses->modules($licenseId)'));
$check('extension preserves capabilities',str_contains($a,'globalCapabilityMap($licenseId)'));
$check('admin extension path explicit',str_contains($adm,"isExtension=\$requestType==='extension'"));
$check('admin uses additive modules',str_contains($adm,'enableModules($licenseId,$selectedModules)'));
$check('admin uses additive policy',str_contains($adm,'extendPolicy($licenseId,$maxDf,$selectedCaps,$legacyUnrestricted)'));
$check('extension expiry based on current expiry',str_contains($adm,"strtotime('+'.\$term.' months',\$baseTs)"));
$check('unlimited license preserved',str_contains($adm,"valid_until']??null)===null"));
$check('HF106 key bootstrap retained',str_contains($sign,'sodium_crypto_sign_keypair'));
exit($fail===0?0:1);
