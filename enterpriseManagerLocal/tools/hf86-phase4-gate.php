<?php
declare(strict_types=1);
$root=$argv[1]??dirname(__DIR__);$get=static fn(string $p):string=>(string)@file_get_contents($root.'/'.$p);
$f=['boot'=>$get('system/app/bootstrap.php'),'team'=>$get('app/team.php'),'dashboard'=>$get('app/dashboard.php'),'projects'=>$get('app/projects/index.php'),'projectView'=>$get('app/projects/view.php'),'dfPolicy'=>$get('system/licensing/EnterpriseDataFormPolicyBridge.php'),'runtimeBridge'=>$get('system/licensing/EnterpriseRuntimeBridge.php'),'runtime'=>$get('products/dataform/runtime.php'),'api'=>$get('products/dataform/api.php'),'reports'=>$get('products/dataform/reports.php'),'relations'=>$get('products/dataform/relations.php')];
$fail=0;$c=function(string $n,bool $ok)use(&$fail){echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$c('live customer permissions consume resource scope',str_contains($f['boot'],'resource_scope')&&str_contains($f['boot'],'enterprise_resource_scope_unrestricted'));
$c('project visibility and management are separate',str_contains($f['boot'],'enterprise_project_scope_visible')&&str_contains($f['boot'],'enterprise_project_scope_manage'));
$c('dataform resource guard exists',str_contains($f['boot'],'enterprise_dataform_scope_allowed')&&str_contains($f['boot'],'enterprise_require_dataform_scope'));
$c('dashboard and project list are filtered',str_contains($f['dashboard'],'enterprise_filter_projects_for_scope')&&str_contains($f['projects'],'enterprise_filter_projects_for_scope'));
$c('direct project page is guarded',str_contains($f['projectView'],'enterprise_require_project_scope($user,$id,false)'));
$c('team page edits role and user resource scopes',str_contains($f['team'],'resource_scope_mode')&&str_contains($f['team'],'projects[]')&&str_contains($f['team'],'dataforms[]'));
$c('dataform policy bridge rejects unassigned dataforms',str_contains($f['dfPolicy'],'enterprise_dataform_scope_allowed')&&str_contains($f['dfPolicy'],'DATAFORM_SCOPE_DENIED'));
$c('runtime bridge rejects unassigned dataforms',str_contains($f['runtimeBridge'],'enterprise_require_dataform_scope'));
$c('workspace filters dataforms and protects project-wide resources',str_contains($f['runtime'],'enterprise_filter_dataforms_for_scope')&&str_contains($f['runtime'],'$projectWideScope')&&str_contains($f['runtime'],"['sources','tables']"));
$c('api endpoints are scoped by dataform',str_contains($f['api'],'enterprise_dataform_scope_allowed')&&str_contains($f['api'],'enterprise_require_dataform_scope'));
$c('reports are scoped by source dataform',str_contains($f['reports'],'$assertStoredReportScope')&&str_contains($f['reports'],'enterprise_dataform_scope_allowed'));
$c('relations are filtered by both endpoint dataforms',substr_count($f['relations'],'enterprise_dataform_scope_allowed')>=2);
echo $fail?"HF86_PHASE4_ENTERPRISE_GATE FAIL ($fail)\n":"HF86_PHASE4_ENTERPRISE_GATE PASS\n";exit($fail?1:0);
