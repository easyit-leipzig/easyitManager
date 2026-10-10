<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$account=(string)@file_get_contents($root.'/app/account.php');
$client=(string)@file_get_contents($root.'/system/licensing/LicenseApiClient.php');
$boot=(string)@file_get_contents($root.'/system/app/bootstrap.php');
$fail=0;
$c=function(string $name,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$c('client exposes installation retirement',str_contains($client,'function retireAccountInstallation')&&str_contains($client,"/api/v1/account/installation/retire"));
$c('retiring current installation fails closed locally',str_contains($client,"last_server_decision']='INSTALLATION_RETIRED'")&&str_contains($client,"activated']=false")&&str_contains($client,"runtime-cache"));
$c('customer installation capability is mapped',str_contains($boot,"account.installations.manage")&&str_contains($boot,"customer.installations.manage"));
$c('account offers authorized retire action',str_contains($account,"action==='retire_installation'")&&str_contains($account,"enterprise_can(\$user,'customer.installations.manage')"));
$c('account explains occupied blocked slots',str_contains($account,"['active','blocked']")&&str_contains($account,'belegen weiterhin einen Installationsplatz'));
$c('account warns retirement is irreversible',str_contains($account,'kann nicht rückgängig gemacht werden')&&str_contains($account,'Runtime-Sitzungen werden sofort widerrufen'));
$c('dataform release remains separate',!str_contains($account,"action==='release_dataform'"));
echo $fail?"HF86_PHASE10_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE10_ENTERPRISE_GATE PASS\n";
exit($fail?1:0);
