<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
$dashboard=file_get_contents($root.'/app/dashboard.php')?:'';$client=file_get_contents($root.'/system/licensing/LicenseApiClient.php')?:'';$boot=file_get_contents($root.'/system/app/bootstrap.php')?:'';
$checks['product module registry']=is_file($root.'/system/app/product_modules.php');
$checks['generic loader']=is_file($root.'/app/products/load.php');
$checks['dashboard project request']=str_contains($dashboard,'request_project_product')&&str_contains($dashboard,'Projektlizenz anfordern');
$checks['api client project products']=str_contains($client,'accountProjectProducts')&&str_contains($client,'requestAccountProjectProduct');
$checks['project entitlement guard']=str_contains($boot,'enterprise_require_project_product');
$checks['phase4 doc']=is_file($root.'/HF87_PHASE4.md');
$fail=0;foreach($checks as $name=>$ok){echo($ok?'PASS':'FAIL')." - {$name}\n";if(!$ok)$fail++;}exit($fail?1:0);
