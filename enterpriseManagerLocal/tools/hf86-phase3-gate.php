<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=['boot'=>$get('system/app/bootstrap.php'),'login'=>$get('login.php'),'client'=>$get('system/licensing/LicenseApiClient.php'),'bridge'=>$get('system/licensing/EnterpriseDataFormPolicyBridge.php'),'runtime'=>$get('system/licensing/EnterpriseRuntimeBridge.php'),'layout'=>$get('system/ui/layout.php'),'team'=>$get('app/team.php')];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('login stores role policy',str_contains($f['login'],'role_policy')&&str_contains($f['login'],'enterprise_customer_permissions($modules,$policy,$rolePolicy)'));
$c('live refresh reloads role policy',str_contains($f['boot'],'$rolePolicy=(array)($profile[\'role_policy\']??[])')&&str_contains($f['boot'],'$user[\'role_policy\']=$rolePolicy'));
$c('effective permissions intersect role and license',str_contains($f['boot'],'$licenseAllowed&&$roleAllowed')&&str_contains($f['boot'],'enterprise_customer_internal_capability'));
$c('project management is role-bound',str_contains($f['boot'],'account.projects.manage')&&str_contains($f['boot'],'projects.create'));
$c('dataform policy bridge applies user role',str_contains($f['bridge'],'role_policy_applied')&&str_contains($f['bridge'],'array_intersect($caps,$rc)'));
$c('runtime manifest applies user role',str_contains($f['runtime'],'enterprise_license_capability')&&str_contains($f['runtime'],'CAPABILITY_NOT_ALLOWED_FOR_ROLE'));
$c('customer team client endpoints exist',str_contains($f['client'],'function accountTeam')&&str_contains($f['client'],'function createAccountTeamUser')&&str_contains($f['client'],'function saveAccountTeamRole'));
$c('customer navigation exposes team only by permission',str_contains($f['layout'],'customer.users.manage')&&str_contains($f['layout'],'customer.roles.manage')&&str_contains($f['layout'],'Benutzer & Rollen'));
$c('team page is tenant customer only',str_contains($f['team'],'empty($user[\'customer\'])')&&str_contains($f['team'],'customer.users.manage')&&str_contains($f['team'],'customer.roles.manage'));
echo $fail?"HF86_PHASE3_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE3_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
