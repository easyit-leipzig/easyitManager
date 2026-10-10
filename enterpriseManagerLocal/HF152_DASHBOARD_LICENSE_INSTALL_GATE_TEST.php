<?php
declare(strict_types=1);
$d=file_get_contents(__DIR__.'/app/dashboard.php');
$s=file_get_contents(__DIR__.'/setup.php');
$checks=[
 'gate before auth'=>strpos($d,'$dashboardLicenseReady=')<strpos($d,'$user=enterprise_require_auth'),
 'enabled required'=>str_contains($d,"!empty(\$dashboardLicenseCfg['enabled'])"),
 'server url required'=>str_contains($d,"\$dashboardLicenseCfg['server_url']"),
 'trust required'=>str_contains($d,"\$dashboardLicenseCfg['trusted_server_keys']"),
 'installation redirect'=>str_contains($d,'../setup.php?required=license-server#license-server'),
 'hard exit'=>str_contains($d,"header('Location: ../setup.php?required=license-server#license-server');") && str_contains($d,"exit;"),
 'setup anchor'=>str_contains($s,'id="license-server"'),
 'setup reason message'=>str_contains($s,'Das Dashboard kann erst geöffnet werden, nachdem der Lizenzserver vollständig eingerichtet')
];
$ok=true; foreach($checks as $n=>$v){echo $n.': '.($v?'PASS':'FAIL').PHP_EOL; if(!$v)$ok=false;}
exit($ok?0:1);
