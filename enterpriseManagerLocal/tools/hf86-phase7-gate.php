<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=['client'=>$get('system/licensing/LicenseApiClient.php'),'account'=>$get('app/account.php')];
$fail=0;$c=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('network add endpoint client exists',str_contains($f['client'],'addAccountNetwork')&&str_contains($f['client'],'/api/v1/account/network/add'));
$c('network remove endpoint client exists',str_contains($f['client'],'removeAccountNetwork')&&str_contains($f['client'],'/api/v1/account/network/remove'));
$c('account renders returned networks',str_contains($f['account'],'$networks=(array)($profile[\'networks\']??[])')&&str_contains($f['account'],'foreach($networks as $n)'));
$c('account offers network add/remove actions',str_contains($f['account'],"value=\"add_network\"")&&str_contains($f['account'],"value=\"remove_network\""));
$c('network self-service follows central flag',substr_count($f['account'],"surface_self_service")>=4);
$c('network validation errors are user visible',str_contains($f['account'],'SURFACE_IP_INVALID'));
echo $fail?"HF86_PHASE7_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE7_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
