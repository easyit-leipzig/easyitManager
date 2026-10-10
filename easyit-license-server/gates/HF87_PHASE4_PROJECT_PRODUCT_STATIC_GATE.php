<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
$files=['src/Database/MigrationRunner.php','src/Service/AccountService.php','src/App/Application.php','src/Admin/AdminApp.php'];
foreach($files as $f)$checks['file '.$f]=is_file($root.'/'.$f);
$m=file_get_contents($root.'/src/Database/MigrationRunner.php')?:'';$a=file_get_contents($root.'/src/Service/AccountService.php')?:'';$app=file_get_contents($root.'/src/App/Application.php')?:'';$admin=file_get_contents($root.'/src/Admin/AdminApp.php')?:'';
$checks['product_catalog']=str_contains($m,'CREATE TABLE IF NOT EXISTS product_catalog');
$checks['project_product_requests']=str_contains($m,'CREATE TABLE IF NOT EXISTS project_product_requests');
$checks['project_product_entitlements']=str_contains($m,'CREATE TABLE IF NOT EXISTS project_product_entitlements');
$checks['catalog endpoint']=str_contains($app,"/api/v1/account/project-products");
$checks['request endpoint']=str_contains($app,"/api/v1/account/project-product/request");
$checks['admin project licenses']=str_contains($admin,"'project_licenses'")&&str_contains($admin,'approveProjectProductRequest');
$checks['account service request']=str_contains($a,'requestProjectProduct')&&str_contains($a,'projectProductPayload');
$fail=0;foreach($checks as $name=>$ok){echo($ok?'PASS':'FAIL')." - {$name}\n";if(!$ok)$fail++;}exit($fail?1:0);
