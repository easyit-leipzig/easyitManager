<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=[
    'client'=>$get('system/licensing/LicenseApiClient.php'),
    'account'=>$get('app/account.php'),
    'team'=>$get('app/team.php'),
    'login'=>$get('login.php'),
];
$fail=0;
$c=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('central self password endpoint client exists',str_contains($f['client'],'/api/v1/account/password/change')&&str_contains($f['client'],'changeAccountPassword'));
$c('team reset endpoint client exists',str_contains($f['client'],'/api/v1/account/team/user/password-reset')&&str_contains($f['client'],'resetAccountTeamUserPassword'));
$c('account page requires current password and confirmation',str_contains($f['account'],'current_password')&&str_contains($f['account'],'new_password_confirm'));
$c('self password change forces relogin',str_contains($f['account'],'password_changed=1')&&str_contains($f['account'],"unset(\$_SESSION['enterprise_user']"));
$c('login confirms completed password change',str_contains($f['login'],'password_changed')&&str_contains($f['login'],'mit dem neuen Kennwort'));
$c('team reset is permission guarded',str_contains($f['team'],"enterprise_can(\$user,'customer.users.manage')")&&str_contains($f['team'],'reset_password'));
$c('team self reset redirects to account semantics',str_contains($f['team'],'Eigenes Kennwort unter „Mein Konto“ ändern.'));
echo $fail?"HF86_PHASE6_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE6_ENTERPRISE_GATE PASS\n";
exit($fail?1:0);
