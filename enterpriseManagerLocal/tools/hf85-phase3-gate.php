<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=[
 'identity'=>(string)@file_get_contents($root.'/system/licensing/DataFormLicenseIdentity.php'),
 'registry'=>(string)@file_get_contents($root.'/system/licensing/EnterpriseDataFormRegistryBridge.php'),
 'api'=>(string)@file_get_contents($root.'/system/licensing/LicenseApiClient.php'),
 'runtime'=>(string)@file_get_contents($root.'/system/licensing/DataFormRuntimeClient.php'),
 'designer'=>(string)@file_get_contents($root.'/system/licensing/DataFormDesignerClient.php'),
 'schema'=>(string)@file_get_contents($root.'/installer/schema/project/002_dataforms.php'),
 'csv'=>(string)@file_get_contents($root.'/system/app/EnterpriseCsvPdo.php'),
 'ui'=>(string)@file_get_contents($root.'/products/dataform/runtime.php'),
];
$fail=[];$ok=[];$c=function(string $n,bool $v)use(&$fail,&$ok){if($v){$ok[]=$n;}else{$fail[]=$n;}};
$c('local token columns are migratable',str_contains($f['identity'],'license_token')&&str_contains($f['identity'],'license_token_key_id')&&str_contains($f['identity'],'license_token_issued_at'));
$c('new-install schema contains token columns',str_contains($f['schema'],'license_token')&&str_contains($f['schema'],'license_token_key_id'));
$c('CSV store contains token columns',str_contains($f['csv'],"'license_token'")&&str_contains($f['csv'],"'license_token_issued_at'"));
$c('signed token endpoint client exists',str_contains($f['api'],"/api/v1/dataforms/token")&&str_contains($f['api'],'function dataFormToken'));
$c('signed DATAFORM denial invalidates local authorization',str_contains($f['api'],'MODULE_|DATAFORM_'));
$c('approved registry synchronization stores token',str_contains($f['registry'],'df_license_token')&&str_contains($f['registry'],'license_token_issued_at'));
$c('runtime fetches token before start',str_contains($f['runtime'],'dataFormToken(')&&str_contains($f['runtime'],"'df_license_token' => \$this->dataformToken"));
$c('runtime renew/action carry token',substr_count($f['runtime'],"'df_license_token'")>=3);
$c('runtime protocol 2',str_contains($f['runtime'],"'runtime_protocol' => '2'"));
$c('offline cache requires token-bound manifest',str_contains($f['runtime'],"dataform_token_hash")&&str_contains($f['runtime'],"dataform_uuid"));
$c('designer fetches and sends token',str_contains($f['designer'],'dataFormToken(')&&substr_count($f['designer'],"'df_license_token'")>=4);
$c('UI exposes token issued state',str_contains($f['ui'],'DF-Token:')&&str_contains($f['ui'],'license_token_issued_at'));
foreach($ok as $n) echo "PASS $n\n";
foreach($fail as $n) echo "FAIL $n\n";
if($fail) exit(1);
echo "HF85_PHASE3_ENTERPRISE_GATE PASS\n";
