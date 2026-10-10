<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);
$f=[
 'api'=>(string)@file_get_contents($root.'/system/licensing/LicenseApiClient.php'),
 'registry'=>(string)@file_get_contents($root.'/system/licensing/EnterpriseDataFormRegistryBridge.php'),
 'runtimeclient'=>(string)@file_get_contents($root.'/system/licensing/DataFormRuntimeClient.php'),
 'ui'=>(string)@file_get_contents($root.'/products/dataform/runtime.php'),
];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo ($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('signed release client endpoint',str_contains($f['api'],"/api/v1/dataforms/release")&&str_contains($f['api'],'function releaseDataForm'));
$c('released identity persisted locally',str_contains($f['api'],'released_dataforms')&&str_contains($f['api'],"hash('sha256',\$projectId.'|'.\$localDataformId)"));
$c('release bridge exists',str_contains($f['registry'],'function releaseForDelete'));
$c('release bridge clears token material',str_contains($f['registry'],"license_status='released'")&&str_contains($f['registry'],'license_token=NULL'));
$c('offline cache gets release tombstone',str_contains($f['runtimeclient'],'invalidateReleasedCache')&&str_contains($f['runtimeclient'],".released"));
$c('offline fallback rejects released DataForm',str_contains($f['runtimeclient'],'released_dataforms')&&str_contains($f['runtimeclient'],'DATAFORM_REGISTRATION_REVOKED'));
$releasePos=strpos($f['ui'],'EnterpriseDataFormRegistryBridge::releaseForDelete');
$deletePos=strpos($f['ui'],'DataFormManager::delete($projectPdo,$deleteDataformId)');
$c('central release occurs before local delete',$releasePos!==false&&$deletePos!==false&&$releasePos<$deletePos);
$c('local delete success reports central release',str_contains($f['ui'],'Lizenzplatz zentral freigegeben'));
echo $fail?"HF85_PHASE4_ENTERPRISE_GATE FAIL ($fail)\n":"HF85_PHASE4_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
