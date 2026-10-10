<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=[
 'client'=>$get('system/licensing/LicenseApiClient.php'),
 'bridge'=>$get('system/licensing/EnterpriseDataFormPolicyBridge.php'),
 'runtime'=>$get('products/dataform/runtime.php'),
 'foundation'=>$get('products/dataform/foundation.php'),
 'relations'=>$get('products/dataform/relations.php'),
 'api'=>$get('products/dataform/api.php'),
 'apps'=>$get('products/dataform/applications.php'),
 'packages'=>$get('products/dataform/packages.php'),
 'reports'=>$get('products/dataform/reports.php'),
];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo ($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('license API exposes per-DF policy request',str_contains($f['client'],'function dataFormPolicy')&&str_contains($f['client'],"/api/v1/dataforms/policy")&&str_contains($f['client'],'df_license_token'));
$c('enterprise policy bridge uses approved token identity',str_contains($f['bridge'],'class EnterpriseDataFormPolicyBridge')&&str_contains($f['bridge'],"license_status']??'')!=='approved")&&str_contains($f['bridge'],'license_token'));
$c('bridge re-synchronizes pending local registry state',str_contains($f['bridge'],'EnterpriseDataFormRegistryBridge::synchronizeProject'));
$c('bridge exposes fail-closed require helper',str_contains($f['bridge'],'function require')&&str_contains($f['bridge'],'CAPABILITY_NOT_LICENSED'));
$c('workspace resolves selected DF policy',str_contains($f['runtime'],'selectedLicensePolicy')&&str_contains($f['runtime'],'EnterpriseDataFormPolicyBridge::policy'));
$c('workspace derives CRUD/search/filter from selected DF',str_contains($f['runtime'],"'dataset.read'")&&str_contains($f['runtime'],"'dataset.create'")&&str_contains($f['runtime'],"'dataset.update'")&&str_contains($f['runtime'],"'dataset.delete'")&&str_contains($f['runtime'],"'dataform.search'")&&str_contains($f['runtime'],"'dataform.filter'"));
$c('workspace derives designer/export/api/relations from selected DF',str_contains($f['runtime'],"'designer.publish'")&&str_contains($f['runtime'],"'export.execute'")&&str_contains($f['runtime'],"'api.access'")&&str_contains($f['runtime'],"'relation.read'"));
$c('workspace hides runtime links without dataset.read',str_contains($f['runtime'],'licenseCanRead')&&str_contains($f['runtime'],'Realvorschau'));
$c('designer foundation uses selected DF policy',str_contains($f['foundation'],'EnterpriseDataFormPolicyBridge::policy')&&str_contains($f['foundation'],'designer.validate')&&str_contains($f['foundation'],'designer.publish'));
$c('relation actions require capabilities on involved DFs',str_contains($f['relations'],'requireRelationEndpoints')&&str_contains($f['relations'],"'relation.attach'")&&str_contains($f['relations'],"'relation.detach'"));
$c('API designer carries per-DF capability maps',str_contains($f['api'],'licensed_caps')&&str_contains($f['api'],'data-caps')&&str_contains($f['api'],'applyDataFormCaps'));
$c('API endpoint creation enforces per-DF API and CRUD',str_contains($f['api'],'EnterpriseDataFormPolicyBridge::policy')&&str_contains($f['api'],"'api.access'")&&str_contains($f['api'],'$opCap'));
$c('application build validates every included DF export right',str_contains($f['apps'],'applicationExportAllowed')&&str_contains($f['apps'],'EnterpriseDataFormPolicyBridge::policy')&&str_contains($f['apps'],"'export.execute'"));
$c('package export validates every selected DF export right',str_contains($f['packages'],'license_export_allowed')&&str_contains($f['packages'],'selectedExportIds')&&str_contains($f['packages'],"'export.execute'"));
$c('report source returns effective runtime capabilities',str_contains($f['reports'],'reportSourceAuth')&&str_contains($f['reports'],"'capabilities'=>\$caps"));
$c('report export requires source DF export capability',str_contains($f['reports'],'reportCanExport')&&str_contains($f['reports'],"['export.execute']")&&str_contains($f['reports'],'Export ist für das DataForm dieser Berichtsquelle'));
echo $fail?"HF86_PHASE2_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE2_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
