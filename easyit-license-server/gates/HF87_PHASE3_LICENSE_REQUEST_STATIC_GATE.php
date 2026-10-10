<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=0;
$check=function(string $name,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$file=function(string $rel)use($root):string{$v=@file_get_contents($root.'/'.$rel);return$v===false?'':$v;};
$v=trim($file('VERSION'));$mig=$file('src/Database/MigrationRunner.php');$acct=$file('src/Service/AccountService.php');$app=$file('src/App/Application.php');$admin=$file('src/Admin/AdminApp.php');
$check('version HF87 phase3',$v==='RC1.8-FC1-HF87-PHASE3-DIFFERENTIATED-LICENSE-REQUEST'||str_starts_with($v,'RC1.8-FC1-HF87-PHASE4-')||str_starts_with($v,'RC1.8-FC1-HF87-PHASE5-')||str_starts_with($v,'RC1.8-FC1-HF108-'));
$check('license request schema',str_contains($mig,'customer_license_requests')&&str_contains($mig,'requested_products')&&str_contains($mig,'requested_capabilities'));
$check('account submit request',str_contains($acct,'submitLicenseRequest')&&str_contains($acct,"LICENSE_REQUEST_SUBMITTED")&&str_contains($acct,"status='superseded'"));
$check('account cancel request',str_contains($acct,'cancelLicenseRequest')&&str_contains($acct,"LICENSE_REQUEST_CANCELLED"));
$check('profile exposes requests and catalog',str_contains($acct,"'license_requests'=>\$licenseRequests")&&str_contains($acct,"'license_request_catalog'=>\$licenseRequestCatalog"));
$check('request endpoint',str_contains($app,"/api/v1/account/license/request")&&str_contains($app,'submitLicenseRequest'));
$check('admin request page',str_contains($admin,"'license_requests'=>\$this->renderLicenseRequests")&&str_contains($admin,'approveLicenseRequest')&&str_contains($admin,'rejectLicenseRequest'));
$check('admin approval creates or updates license',str_contains($admin,'LICENSE_REQUEST_APPROVED')&&str_contains($admin,'resulting_license_id')&&str_contains($admin,'setModules($licenseId,$selectedModules)'));
$check('documentation',is_file($root.'/docs/HF87_PHASE3_DIFFERENTIATED_LICENSE_REQUEST.md'));
exit($fail===0?0:1);
