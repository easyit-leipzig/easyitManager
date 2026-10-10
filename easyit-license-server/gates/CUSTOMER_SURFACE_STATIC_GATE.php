<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[
 'customer portal entry'=>is_file($root.'/customer/index.php'),
 'customer auth'=>is_file($root.'/src/Customer/CustomerAuth.php'),
 'customer portal app'=>is_file($root.'/src/Customer/CustomerPortalApp.php'),
 'surface policy service'=>is_file($root.'/src/Service/SurfacePolicyService.php'),
 'allowed domains schema'=>str_contains(file_get_contents($root.'/src/Database/MigrationRunner.php'),'customer_allowed_domains'),
 'allowed networks schema'=>str_contains(file_get_contents($root.'/src/Database/MigrationRunner.php'),'customer_allowed_networks'),
 'customer users schema'=>str_contains(file_get_contents($root.'/src/Database/MigrationRunner.php'),'customer_users'),
 'self service switch'=>str_contains(file_get_contents($root.'/src/Database/MigrationRunner.php'),'surface_self_service'),
 'runtime surface enforcement'=>str_contains(file_get_contents($root.'/src/Service/RuntimeService.php'),'assertForLicense'),
 'admin domain management'=>str_contains(file_get_contents($root.'/src/Admin/AdminApp.php'),'adminAddDomain'),
 'admin network management'=>str_contains(file_get_contents($root.'/src/Admin/AdminApp.php'),'adminAddNetwork'),
 'admin customer portal users'=>str_contains(file_get_contents($root.'/src/Admin/AdminApp.php'),'createCustomerUser'),
];
$ok=true;foreach($checks as $n=>$v){printf("%-42s %s\n",$n,$v?'PASS':'FAIL');$ok=$ok&&$v;}echo 'FINAL STATUS: '.($ok?'PASSED':'FAILED').PHP_EOL;exit($ok?0:1);
