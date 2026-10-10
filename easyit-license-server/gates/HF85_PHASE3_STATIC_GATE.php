<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=[
 'token'=>(string)@file_get_contents($root.'/src/Service/DataFormTokenService.php'),
 'signer'=>(string)@file_get_contents($root.'/src/Security/ServerSigner.php'),
 'registry'=>(string)@file_get_contents($root.'/src/Service/DataFormRegistryService.php'),
 'runtime'=>(string)@file_get_contents($root.'/src/Service/RuntimeService.php'),
 'designer'=>(string)@file_get_contents($root.'/src/Service/DesignerService.php'),
 'compiler'=>(string)@file_get_contents($root.'/src/ProtectedCore/DataFormCompiler.php'),
 'app'=>(string)@file_get_contents($root.'/src/App/Application.php'),
];
$fail=[];$ok=[];$c=function(string $n,bool $v)use(&$fail,&$ok){if($v){$ok[]=$n;}else{$fail[]=$n;}};
$c('token service exists',$f['token']!==''&&str_contains($f['token'],'class DataFormTokenService'));
$c('compact DFT1 token',str_contains($f['token'],"'DFT1.'")&&str_contains($f['token'],'easyit.dataform.license'));
$c('token is server-signed',str_contains($f['token'],'$this->signer->sign(')&&str_contains($f['signer'],'function verify('));
$c('token binds exact DF identity',str_contains($f['token'],"'dataform_uuid'")&&str_contains($f['token'],"'installation_id'")&&str_contains($f['token'],"'project_id'")&&str_contains($f['token'],"'local_dataform_id'"));
$c('registry status must be approved',str_contains($f['token'],'DATAFORM_NOT_APPROVED'));
$c('licensed slot must remain active',str_contains($f['token'],'DATAFORM_SLOT_NOT_ACTIVE')&&str_contains($f['token'],'license_dataform_usage'));
$c('registration installation is frozen',str_contains($f['registry'],'DATAFORM_INSTALLATION_MISMATCH')&&!str_contains($f['registry'],'SET installation_id=?'));
$c('approved registration returns token',str_contains($f['registry'],'df_license_token')&&str_contains($f['registry'],'issueForRow'));
$c('dedicated token endpoint',str_contains($f['app'],"/api/v1/dataforms/token"));
$c('runtime checks token',substr_count($f['runtime'],'assertApproved(')>=3);
$c('runtime no longer claims slots',!str_contains($f['runtime'],'claimDataform('));
$c('runtime manifest binds token hash',str_contains($f['runtime'],'dataform_token_hash')&&str_contains($f['runtime'],'dataform_uuid'));
$c('manifest version 3',str_contains($f['compiler'],"'manifest_version'=>3")&&str_contains($f['runtime'],"'3','active'"));
$c('designer checks token',substr_count($f['designer'],'assertApproved(')>=4);
$c('designer no longer claims slots',!str_contains($f['designer'],'claimDataform('));
foreach($ok as $n) echo "PASS $n\n";
foreach($fail as $n) echo "FAIL $n\n";
if($fail) exit(1);
echo "HF85_PHASE3_SERVER_GATE PASS\n";
