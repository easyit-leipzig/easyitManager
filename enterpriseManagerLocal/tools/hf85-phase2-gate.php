<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$b=(string)@file_get_contents($root.'/system/licensing/EnterpriseDataFormRegistryBridge.php');
$fail=[];$ok=[];$c=function(string $n,bool $v)use(&$fail,&$ok){if($v){$ok[]=$n;}else{$fail[]=$n;}};
$c('pending is not skipped locally',!str_contains($b,"in_array(\$status,['pending','approved'"));
$c('approved is recognized',str_contains($b,"'approved'"));
$c('rejected is recognized',str_contains($b,"'rejected'"));
$c('pending status is polled via signed registration',str_contains($b,'registerDataForm(')&&str_contains($b,'pending wird bewusst erneut'));
$c('registration timestamp is preserved',str_contains($b,"license_registered_at")&&str_contains($b,'$registeredAt'));
foreach($ok as $n) echo "PASS $n\n";
foreach($fail as $n) echo "FAIL $n\n";
if($fail){exit(1);} echo "HF85_PHASE2_ENTERPRISE_GATE PASS\n";
