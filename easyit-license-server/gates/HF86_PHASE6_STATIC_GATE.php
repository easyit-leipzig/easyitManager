<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'migration'=>$root.'/src/Database/MigrationRunner.php',
 'credential'=>$root.'/src/Service/CustomerCredentialService.php',
 'auth'=>$root.'/src/Customer/CustomerAuth.php',
 'portal'=>$root.'/src/Customer/CustomerPortalApp.php',
 'account'=>$root.'/src/Service/AccountService.php',
 'roles'=>$root.'/src/Service/CustomerRoleService.php',
 'app'=>$root.'/src/App/Application.php',
 'admin'=>$root.'/src/Admin/AdminApp.php',
];
foreach($files as $n=>$f)if(!is_file($f)){fwrite(STDERR,"missing {$n}: {$f}\n");exit(1);} 
$checks=[
 'credential version schema'=>str_contains(file_get_contents($files['migration']),'credential_version'),
 'credential service'=>str_contains(file_get_contents($files['credential']),'CUSTOMER_PASSWORD_CHANGED')&&str_contains(file_get_contents($files['credential']),"status='active'")&&str_contains(file_get_contents($files['credential']),'credential_version=COALESCE(credential_version,1)+1'),
 'account password route'=>str_contains(file_get_contents($files['app']),'/api/v1/account/password/change'),
 'team reset route'=>str_contains(file_get_contents($files['app']),'/api/v1/account/team/user/password-reset'),
 'role permission'=>str_contains(file_get_contents($files['roles']),"'account.users.manage'")&&str_contains(file_get_contents($files['roles']),'resetUserPassword'),
 'portal version enforcement'=>str_contains(file_get_contents($files['auth']),'credential_version')&&str_contains(file_get_contents($files['auth']),'acceptCredentialVersion'),
 'superadmin reset'=>str_contains(file_get_contents($files['admin']),'reset_customer_user_password'),
];
$failed=[];foreach($checks as $n=>$ok){echo ($ok?'PASS ':'FAIL ').$n."\n";if(!$ok)$failed[]=$n;}if($failed){exit(1);}echo "HF86_PHASE6_STATIC_GATE PASS\n";
