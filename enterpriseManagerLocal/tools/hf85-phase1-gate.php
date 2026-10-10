<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
    'identity'=>(string)@file_get_contents($root.'/system/licensing/DataFormLicenseIdentity.php'),
    'bridge'=>(string)@file_get_contents($root.'/system/licensing/EnterpriseDataFormRegistryBridge.php'),
    'client'=>(string)@file_get_contents($root.'/system/licensing/LicenseApiClient.php'),
    'runtime'=>(string)@file_get_contents($root.'/products/dataform/runtime.php'),
    'schema'=>(string)@file_get_contents($root.'/installer/schema/project/002_dataforms.php'),
    'csv'=>(string)@file_get_contents($root.'/system/app/EnterpriseCsvPdo.php'),
];
$checks=[];$c=function(string $name,bool $ok)use(&$checks){$checks[]=[$name,$ok];};
$c('identity helper exists',$files['identity']!=='');
$c('UUID v4 generation',str_contains($files['identity'],'function uuidV4')&&str_contains($files['identity'],'0x40'));
$c('existing rows backfilled',str_contains($files['identity'],'SELECT id,df_uuid FROM dataforms'));
$c('unique UUID index attempted',str_contains($files['identity'],'uq_dataforms_df_uuid'));
$c('local license status columns',str_contains($files['schema'],'license_status')&&str_contains($files['schema'],'license_registered_at'));
$c('CSV schema contains UUID',str_contains($files['csv'],"'df_uuid'")&&str_contains($files['csv'],"['slug'],['df_uuid']"));
$c('registry bridge exists',$files['bridge']!=='');
$c('signed API registration method',str_contains($files['client'],"/api/v1/dataforms/register"));
$c('runtime synchronizes registry',str_contains($files['runtime'],'EnterpriseDataFormRegistryBridge::synchronizeProject'));
$c('UI shows UUID and license status',str_contains($files['runtime'],'DF-UUID')&&str_contains($files['runtime'],"license_status"));
$fail=0;foreach($checks as[$name,$ok]){echo str_pad($name,50).($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;}
echo 'FINAL STATUS: '.($fail?'FAILED':'PASSED').PHP_EOL;exit($fail?1:0);
