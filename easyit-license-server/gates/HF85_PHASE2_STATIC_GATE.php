<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=[
 'service'=>(string)@file_get_contents($root.'/src/Service/DataFormRegistryService.php'),
 'admin'=>(string)@file_get_contents($root.'/src/Admin/AdminApp.php'),
 'runtime'=>(string)@file_get_contents($root.'/src/Service/RuntimeService.php'),
 'designer'=>(string)@file_get_contents($root.'/src/Service/DesignerService.php'),
];
$fail=[];$ok=[];$c=function(string $n,bool $v)use(&$fail,&$ok){if($v){$ok[]=$n;}else{$fail[]=$n;}};
$c('manual approve service',str_contains($f['service'],'function approve(')&&str_contains($f['service'],"status='approved'"));
$c('approve claims exactly through central slot service',str_contains($f['service'],'claimDataform(')&&str_contains($f['service'],'already_approved'));
$c('manual reject service',str_contains($f['service'],'function reject(')&&str_contains($f['service'],"status='rejected'"));
$c('reject releases possible phase1 legacy claim',str_contains($f['service'],'releaseDataform(')&&str_contains($f['service'],'legacy_slot_released'));
$c('decisions serialize on registry row',substr_count($f['service'],'LIMIT 1 FOR UPDATE')>=2);
$c('decisions rotate license generation',substr_count($f['service'],'generation=generation+1')>=2);
$c('approval audit',str_contains($f['service'],'DATAFORM_LICENSE_APPROVED'));
$c('rejection audit',str_contains($f['service'],'DATAFORM_LICENSE_REJECTED'));
$c('superadmin dataform page',str_contains($f['admin'],"'dataforms'=>\$this->renderRegisteredDataforms")&&str_contains($f['admin'],'DataForm-Freigaben'));
$c('superadmin approve action',str_contains($f['admin'],"action==='approve_dataform'")&&str_contains($f['admin'],'approveRegisteredDataform'));
$c('superadmin reject action',str_contains($f['admin'],"action==='reject_dataform'")&&str_contains($f['admin'],'rejectRegisteredDataform'));
$c('registry-bound slots cannot be released through legacy admin action',str_contains($f['admin'],'DATAFORM_SLOT_BOUND_TO_APPROVAL')&&str_contains($f['admin'],'Registry-gebunden'));
$c('dashboard exposes pending approvals',str_contains($f['admin'],'pending_dataforms')&&str_contains($f['admin'],'DF-Freigaben offen'));
$phase3=is_file($root.'/src/Service/DataFormTokenService.php');
$c('legacy runtime gate remains until phase3, then is replaced by token gate',($phase3&&!str_contains($f['runtime'],'claimDataform')&&!str_contains($f['designer'],'claimDataform'))||(!$phase3&&str_contains($f['runtime'],'claimDataform')&&str_contains($f['designer'],'claimDataform')));
foreach($ok as $n) echo "PASS $n\n";
foreach($fail as $n) echo "FAIL $n\n";
if($fail){exit(1);} echo "HF85_PHASE2_SERVER_GATE PASS\n";
