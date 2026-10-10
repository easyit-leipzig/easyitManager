<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=[
 'account'=>$get('src/Service/AccountService.php'),
 'app'=>$get('src/App/Application.php'),
 'surface'=>$get('src/Service/SurfacePolicyService.php'),
];
$fail=0;
$c=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('network add service exists',str_contains($f['account'],'public function addNetwork')&&str_contains($f['account'],'ACCOUNT_NETWORK_ADDED'));
$c('network remove service exists',str_contains($f['account'],'public function removeNetwork')&&str_contains($f['account'],'ACCOUNT_NETWORK_REMOVED'));
$c('network endpoints exist',str_contains($f['app'],'/api/v1/account/network/add')&&str_contains($f['app'],'/api/v1/account/network/remove'));
$c('network input uses central CIDR normalizer',str_contains($f['account'],'normalizeCidr')&&str_contains($f['surface'],'public function normalizeCidr'));
$c('network self-service remains opt-in',substr_count($f['account'],'assertSurfaceSelfService')>=5);
$c('network mutations are tenant scoped',(str_contains($f['account'],"WHERE rule_id=? AND customer_id=?")||str_contains($f['surface'],"WHERE rule_id=? AND customer_id=? AND status='active'"))&&str_contains($f['account'],'removeNetworkRuleSafely'));
echo $fail?"HF86_PHASE7_STATIC_GATE FAIL ($fail)\n":"HF86_PHASE7_STATIC_GATE PASS\n";
exit($fail?1:0);
