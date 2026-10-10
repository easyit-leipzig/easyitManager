<?php
declare(strict_types=1);
$root=dirname(__DIR__);$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=['migration'=>$get('src/Database/MigrationRunner.php'),'roles'=>$get('src/Service/CustomerRoleService.php'),'account'=>$get('src/Service/AccountService.php'),'app'=>$get('src/App/Application.php'),'admin'=>$get('src/Admin/AdminApp.php')];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('customer role schema exists',str_contains($f['migration'],'customer_roles')&&str_contains($f['migration'],'customer_role_capabilities')&&str_contains($f['migration'],'customer_user_roles'));
$c('default customer roles exist',str_contains($f['roles'],"'customer_admin'")&&str_contains($f['roles'],"'editor'")&&str_contains($f['roles'],"'viewer'"));
$c('admin role cannot widen license',str_contains($f['roles'],'array_intersect($roleProduct,$licenseCaps)')&&str_contains($f['roles'],"capability_policy']??'')==='legacy_unrestricted"));
$c('tenant isolation on roles/users',str_contains($f['roles'],'customer_id=?')&&str_contains($f['roles'],'r.customer_id=?'));
$c('customer admin has team administration',str_contains($f['roles'],'account.users.manage')&&str_contains($f['roles'],'account.roles.manage')&&str_contains($f['roles'],'account.projects.manage'));
$c('self lockout is blocked',str_contains($f['roles'],'ACCOUNT_CANNOT_CHANGE_SELF'));
$c('login/profile return role policy',substr_count($f['account'],"'role_policy'")>=2&&str_contains($f['account'],'$this->roles()->policy'));
$c('customer team API endpoints exist',str_contains($f['app'],'/api/v1/account/team/user/create')&&str_contains($f['app'],'/api/v1/account/team/user/set')&&str_contains($f['app'],'/api/v1/account/team/role/save'));
$c('support-created users use email and current license-or-demo',str_contains($f['admin'],'FILTER_VALIDATE_EMAIL')&&str_contains($f['admin'],'license_id')&&str_contains($f['admin'],"\$licenseId!==''?\$licenseId:null"));
echo $fail?"HF86_PHASE3_SERVER_GATE FAIL ($fail)\n":"HF86_PHASE3_SERVER_GATE PASS\n";exit($fail?1:0);
