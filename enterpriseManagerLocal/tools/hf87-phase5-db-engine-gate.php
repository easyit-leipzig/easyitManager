<?php
declare(strict_types=1);
$root=dirname(__DIR__);$files=['registry'=>$root.'/system/app/product_modules.php','boot'=>$root.'/system/app/bootstrap.php','export'=>$root.'/products/db-engine/export.php','pkg'=>$root.'/products/db-engine/DbEnginePackage.php','runtime'=>$root.'/system/app/project_runtime/bootstrap.php'];$fail=0;foreach($files as $k=>$f){$ok=is_file($f);echo($ok?'PASS':'FAIL')." file $k\n";if(!$ok)$fail++;}$checks=[
 'db-engine registry'=>str_contains((string)@file_get_contents($files['registry']),"'db-engine'"),
 'manager project gate'=>str_contains((string)@file_get_contents($files['export']),"enterprise_require_project_product(\$user,\$projectId,'db-engine'"),
 'manager capability gate'=>str_contains((string)@file_get_contents($files['export']),"db-engine.export"),
 'runtime external engine gate'=>str_contains((string)@file_get_contents($files['runtime']),'easyit_db_engine_pdo'),
 'all drivers export'=>str_contains((string)@file_get_contents($files['pkg']),'EnterpriseCsvPdo.php')&&str_contains((string)@file_get_contents($files['pkg']),'EnterpriseSqlitePdo.php')&&str_contains((string)@file_get_contents($files['pkg']),'EnterprisePgsqlPdo.php')&&str_contains((string)@file_get_contents($files['pkg']),'EnterpriseOraclePdo.php')&&str_contains((string)@file_get_contents($files['pkg']),'EnterpriseMssqlPdo.php'),
];foreach($checks as $n=>$ok){echo($ok?'PASS':'FAIL')." $n\n";if(!$ok)$fail++;}exit($fail?1:0);
