<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require_once $root.'/system/licensing/LicenseConfigStore.php';
$checks=[
 'persistent store'=>is_file($root.'/system/licensing/LicenseConfigStore.php'),
 'bootstrap uses store'=>str_contains((string)file_get_contents($root.'/system/app/bootstrap.php'),'EnterpriseLicenseConfigStore::load'),
 'remote admin uses store'=>str_contains((string)file_get_contents($root.'/app/licensing/remote.php'),'EnterpriseLicenseConfigStore::save'),
 'calendar retained'=>is_dir($root.'/products/calendar'),
 'calendar maintenance retained'=>is_file($root.'/products/calendar/maintenance.php'),
];
$tmp=sys_get_temp_dir().'/easyit-license-repair-'.bin2hex(random_bytes(4));
mkdir($tmp.'/config',0770,true);
file_put_contents($tmp.'/config/licensing.php',"<?php return ['enabled'=>true,'server_url'=>'https://license.example.test','trusted_server_keys'=>['T'=>'abc']];");
$cfg=EnterpriseLicenseConfigStore::load($tmp);
$checks['legacy migration']=(($cfg['server_url']??'')==='https://license.example.test' && is_file($tmp.'/storage/system/config/licensing.php'));
foreach($checks as $name=>$ok){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)exit(1);} 
echo "HF93 License Config Repair Gate: PASS\n";
