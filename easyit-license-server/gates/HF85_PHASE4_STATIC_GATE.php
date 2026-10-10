<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=[
 'migration'=>(string)@file_get_contents($root.'/src/Database/MigrationRunner.php'),
 'registry'=>(string)@file_get_contents($root.'/src/Service/DataFormRegistryService.php'),
 'app'=>(string)@file_get_contents($root.'/src/App/Application.php'),
 'admin'=>(string)@file_get_contents($root.'/src/Admin/AdminApp.php'),
 'token'=>(string)@file_get_contents($root.'/src/Service/DataFormTokenService.php'),
];
$fail=0;
$c=function(string $name,bool $ok)use(&$fail){echo ($ok?'PASS ':'FAIL ').$name.PHP_EOL;if(!$ok)$fail++;};
$c('release metadata migration',str_contains($f['migration'],"released_at")&&str_contains($f['migration'],"release_request_id"));
$c('signed release endpoint',str_contains($f['app'],"/api/v1/dataforms/release")&&str_contains($f['app'],'$registry->release('));
$c('release service exists',str_contains($f['registry'],'function release(array $installation'));
$c('release exact identity checks',str_contains($f['registry'],'DATAFORM_INSTALLATION_MISMATCH')&&str_contains($f['registry'],'DATAFORM_TOKEN_BINDING_INVALID'));
$c('release is idempotent',str_contains($f['registry'],"status==='released'")&&str_contains($f['registry'],"'already_released'=>true"));
$c('unregistered delete creates terminal tombstone',str_contains($f['registry'],"'status'=>'unregistered'")&&str_contains($f['registry'],'release_tombstone_created'));
$c('release frees central slot',str_contains($f['registry'],'releaseDataform($licenseId,$projectId,$localId)'));
$c('release revokes active runtime sessions',str_contains($f['registry'],"runtime_sessions SET status='revoked'"));
$c('release audit event',str_contains($f['registry'],"DATAFORM_RELEASED"));
$c('token remains approved-only',str_contains($f['token'],"status']??'')!=='approved'"));
$c('admin registry constructor has token service',str_contains($f['admin'],'new DataFormTokenService')&&str_contains($f['admin'],'new DataFormRegistryService($this->pdo,$this->licenses,$this->audit,new SurfacePolicyService')&&str_contains($f['admin'],',$tokens)'));
echo $fail?"HF85_PHASE4_SERVER_GATE FAIL ($fail)\n":"HF85_PHASE4_SERVER_GATE PASS\n";
exit($fail?1:0);
