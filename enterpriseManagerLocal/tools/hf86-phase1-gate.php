<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);
$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=[
 'boot'=>$get('system/app/bootstrap.php'),'layout'=>$get('system/ui/layout.php'),
 'projects'=>$get('app/projects/index.php'),'projectview'=>$get('app/projects/view.php'),'runtime'=>$get('products/dataform/runtime.php'),
 'records'=>$get('products/dataform/records.php'),'apiRuntime'=>$get('products/dataform/api-runtime.php'),'api'=>$get('products/dataform/api.php'),
 'import'=>$get('products/dataform/import.php'),'relations'=>$get('products/dataform/relations.php'),'reports'=>$get('products/dataform/reports.php'),
 'queries'=>$get('products/dataform/queries.php'),'apps'=>$get('products/dataform/applications.php'),'packages'=>$get('products/dataform/packages.php'),
 'foundation'=>$get('products/dataform/foundation.php'),
];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo ($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('customer license refresh exists',str_contains($f['boot'],'function enterprise_refresh_customer_license')&&str_contains($f['boot'],'accountProfile($token)'));
$c('auth refreshes every customer request',str_contains($f['boot'],'enterprise_refresh_customer_license($user)'));
$c('refresh replaces modules policy permissions',str_contains($f['boot'],"['modules']")&&str_contains($f['boot'],"['license_policy']")&&(str_contains($f['boot'],'enterprise_customer_permissions($modules,$policy)')||str_contains($f['boot'],'enterprise_customer_permissions($modules,$policy,$rolePolicy)')));
$c('customer permissions derived from modules and capabilities',str_contains($f['boot'],'function enterprise_customer_permissions')&&str_contains($f['boot'],'dataset.read')&&str_contains($f['boot'],'designer.publish')&&str_contains($f['boot'],'export.execute')&&str_contains($f['boot'],'api.access'));
$c('module and capability guards exist',str_contains($f['boot'],'function enterprise_require_customer_module')&&str_contains($f['boot'],'function enterprise_require_license_capability'));
$c('customer navigation hides unlicensed projects',str_contains($f['layout'],'enterprise_customer_has_module')&&str_contains($f['layout'],"'dataform'"));
$c('project routes require licensed product path',(str_contains($f['projects'],'enterprise_require_customer_module')&&str_contains($f['projectview'],'enterprise_require_customer_module'))||(str_contains($f['projectview'],"products/load.php?project_id=")&&str_contains($f['boot'],'enterprise_require_project_product')));
$c('runtime surface has module/capability flags',str_contains($f['runtime'],'licenseCanExport')&&str_contains($f['runtime'],'licenseCanApi')&&str_contains($f['runtime'],'licenseCanRelations')&&str_contains($f['runtime'],'licenseCanEvents'));
$c('runtime preserves disabled settings instead of deleting config',str_contains($f['runtime'],'licenseCurrentStmt')&&str_contains($f['runtime'],'if(!$licenseCanSearch)')&&str_contains($f['runtime'],'if(!$licenseCanEvents)'));
$c('runtime custom javascript editing gated',str_contains($f['runtime'],'licenseCanCustomJavascript')&&str_contains($f['runtime'],'Zusätzliches JavaScript ist in der aktuellen Lizenz nicht freigegeben'));
$c('record runtime gates child relation creation',str_contains($f['records'],'allowCreateChild')&&str_contains($f['records'],'relation.createChild')&&str_contains($f['records'],'parentRelationContextId>0'));
$c('record runtime suppresses stored custom JS when unlicensed',str_contains($f['records'],'dataform.custom_javascript')&&str_contains($f['records'],'default_js_provider'));
$c('REST runtime requires token manifest and API capability',str_contains($f['apiRuntime'],'EnterpriseRuntimeBridge::boot')&&str_contains($f['apiRuntime'],'api.access'));
$c('REST runtime maps CRUD to dataset capabilities',str_contains($f['apiRuntime'],"'list','detail'=>'dataset.read'")&&str_contains($f['apiRuntime'],"'create'=>'dataset.create'")&&str_contains($f['apiRuntime'],"'delete'=>'dataset.delete'"));
$c('API designer requires module and api.access',str_contains($f['api'],'enterprise_require_customer_module')&&str_contains($f['api'],"'api'")&&str_contains($f['api'],'api.access'));
$c('CSV import requires per-DF runtime capabilities',str_contains($f['import'],'EnterpriseRuntimeBridge::boot')&&str_contains($f['import'],'dataset.create')&&str_contains($f['import'],'dataset.update')&&str_contains($f['import'],'dataset.delete'));
$c('relation editor requires relation capabilities',str_contains($f['relations'],'relation.read')&&str_contains($f['relations'],'relation.attach')&&str_contains($f['relations'],'relation.detach'));
$c('visual queries require per-DF token/capabilities',str_contains($f['queries'],'EnterpriseRuntimeBridge::boot')&&str_contains($f['queries'],'dataform.search')&&str_contains($f['queries'],'dataform.filter'));
$c('reports validate source DF and gate export',str_contains($f['reports'],'assertReportSource')&&str_contains($f['reports'],'EnterpriseRuntimeBridge::boot')&&str_contains($f['reports'],'export.execute'));
$c('application build is export-gated',str_contains($f['apps'],'licenseCanExport')&&str_contains($f['apps'],'Application-Build ist in der aktuellen Lizenz nicht freigegeben'));
$c('package export/download is export-gated',str_contains($f['packages'],'Paketexport ist in der aktuellen Lizenz nicht freigegeben')&&str_contains($f['packages'],'Paketdownload ist in der aktuellen Lizenz nicht freigegeben'));
$c('designer foundation requires designer module and capability',str_contains($f['foundation'],"'designer'")&&str_contains($f['foundation'],'designer.validate')&&str_contains($f['foundation'],'designer.publish'));
echo $fail?"HF86_PHASE1_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE1_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
