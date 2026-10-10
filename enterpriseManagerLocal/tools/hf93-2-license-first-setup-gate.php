<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$fail=[];
$login=(string)file_get_contents($root.'/login.php');
$admin=(string)file_get_contents($root.'/admin-login.php');
$remote=(string)file_get_contents($root.'/app/licensing/remote.php');
$store=(string)file_get_contents($root.'/system/licensing/LicenseConfigStore.php');
$checks=[
 'login detects configured state'=>str_contains($login,'$licenseConfigured='),
 'login has immediate admin action'=>str_contains($login,'Lokale Administration öffnen') && str_contains($login,"app/licensing/remote.php"),
 'admin preserves local next target'=>str_contains($admin,'$safeNext=') && str_contains($admin,"'://'"),
 'admin blocks traversal'=>str_contains($admin,"preg_match("),
 'remote writes persistent store'=>str_contains($remote,'EnterpriseLicenseConfigStore::save'),
 'persistent path'=>str_contains($store,"/storage/system/config/licensing.php"),
];
foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail[]=$name;}
if($fail){fwrite(STDERR,"HF93.2 License First Setup Gate: FAIL\n");exit(1);}echo "HF93.2 License First Setup Gate: PASS\n";
