<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=[
 'surface'=>$get('src/Service/SurfacePolicyService.php'),
 'account'=>$get('src/Service/AccountService.php'),
 'portal'=>$get('src/Customer/CustomerPortalApp.php'),
];
$fail=0;
$c=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('domain safe removal is centralized',str_contains($f['surface'],'removeDomainRuleSafely')&&str_contains($f['surface'],'SURFACE_DOMAIN_LAST_REQUIRED')&&str_contains($f['surface'],'SURFACE_DOMAIN_CURRENT_REQUIRED'));
$c('network safe removal is centralized',str_contains($f['surface'],'removeNetworkRuleSafely')&&str_contains($f['surface'],'SURFACE_IP_LAST_REQUIRED')&&str_contains($f['surface'],'SURFACE_IP_CURRENT_REQUIRED'));
$c('safe removal serializes concurrent mutations',substr_count($f['surface'],'FOR UPDATE')>=4&&substr_count($f['surface'],'beginTransaction')>=2&&substr_count($f['surface'],'rollBack')>=2);
$c('account removal protects current host',str_contains($f['account'],"\$surface['host']")&&str_contains($f['account'],'removeDomainRuleSafely'));
$c('account removal protects current source ip',str_contains($f['account'],"\$surface['source_ip']")&&str_contains($f['account'],'removeNetworkRuleSafely'));
$c('legacy customer portal uses safe removal service',str_contains($f['portal'],'removeDomainRuleSafely')&&str_contains($f['portal'],'removeNetworkRuleSafely'));
echo $fail?"HF86_PHASE8_STATIC_GATE FAIL ($fail)\n":"HF86_PHASE8_STATIC_GATE PASS\n";
exit($fail?1:0);
