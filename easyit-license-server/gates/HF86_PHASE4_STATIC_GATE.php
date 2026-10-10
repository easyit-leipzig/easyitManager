<?php
declare(strict_types=1);
$root=dirname(__DIR__);$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=['migration'=>$get('src/Database/MigrationRunner.php'),'roles'=>$get('src/Service/CustomerRoleService.php'),'account'=>$get('src/Service/AccountService.php')];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('role resource scope schema exists',str_contains($f['migration'],'customer_role_resource_scopes')&&str_contains($f['migration'],'customer_role_projects')&&str_contains($f['migration'],'customer_role_dataforms'));
$c('user resource scope schema exists',str_contains($f['migration'],'customer_user_resource_scopes')&&str_contains($f['migration'],'customer_user_projects')&&str_contains($f['migration'],'customer_user_dataforms'));
$c('existing role and user scopes default compatibly',str_contains($f['roles'],"ensureRoleScopeRow(\$rid")&&str_contains($f['roles'],"ensureUserScopeRow(\$uid,'inherit')"));
$c('resource catalog only exposes approved dataforms',str_contains($f['roles'],"status='approved'")&&str_contains($f['roles'],'resource_catalog'));
$c('role scopes union and user scopes reduce',str_contains($f['roles'],'$roleUnrestricted')&&str_contains($f['roles'],'$userRestricted')&&str_contains($f['roles'],'$roleAllowed')&&str_contains($f['roles'],'$userAllowed'));
$c('resource scope is returned in live role policy',str_contains($f['roles'],"'resource_scope'=>\$resource")&&str_contains($f['account'],'$licenseId'));
$c('team API carries resource catalog and assignments',str_contains($f['roles'],"'resource_catalog'=>\$this->resourceCatalog")&&str_contains($f['roles'],'resource_scope_mode')&&str_contains($f['roles'],'dataform_uuids'));
$c('protected customer admin role remains immutable',str_contains($f['roles'],'ACCOUNT_ADMIN_ROLE_PROTECTED')&&str_contains($f['roles'],"roleName==='customer_admin'"));
echo $fail?"HF86_PHASE4_SERVER_GATE FAIL ($fail)\n":"HF86_PHASE4_SERVER_GATE PASS\n";exit($fail?1:0);
