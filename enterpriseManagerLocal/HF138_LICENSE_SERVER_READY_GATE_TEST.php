<?php
declare(strict_types=1);
$f=file_get_contents(__DIR__.'/setup.php');
$checks=[
 'READY payload is required'=>str_contains($f,"(\$payload['ready']??false)!==true")&&str_contains($f,"(string)(\$payload['status']??'')!=='ready'"),
 'setup required message'=>str_contains($f,'Lizenzserver noch nicht eingerichtet. Bitte zuerst das Lizenzserver-Setup'),
 'preflight before manager install'=>strpos($f,'$licenseCfg=setup_configure_local_license_server();')<strpos($f,'$result=$installer->install(['),
 'status card'=>str_contains($f,'Voraussetzung: Lizenzserver')&&str_contains($f,'SETUP_REQUIRED'),
 'setup link'=>str_contains($f,'Lizenzserver jetzt einrichten'),
 'install button locked until ready'=>str_contains($f,'Lizenzserver zuerst vollständig einrichten'),
];
$ok=true;foreach($checks as $n=>$v){echo ($v?'PASS':'FAIL')." - $n\n";$ok=$ok&&$v;}exit($ok?0:1);
