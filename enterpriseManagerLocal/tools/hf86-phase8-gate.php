<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=(string)@file_get_contents($root.'/app/account.php');
$fail=0;
$c=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('domain last-rule message exists',str_contains($f,'SURFACE_DOMAIN_LAST_REQUIRED'));
$c('domain current-rule message exists',str_contains($f,'SURFACE_DOMAIN_CURRENT_REQUIRED'));
$c('network last-rule message exists',str_contains($f,'SURFACE_IP_LAST_REQUIRED'));
$c('network current-rule message exists',str_contains($f,'SURFACE_IP_CURRENT_REQUIRED'));
$c('self-service wording covers domains and networks',str_contains($f,'Domain-/IP-Freigaben'));
echo $fail?"HF86_PHASE8_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE8_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
