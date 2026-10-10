<?php
declare(strict_types=1);$root=dirname(__DIR__);$checks=[
'calendar setup'=>is_file($root.'/products/calendar/setup.php'),
'calendar project service'=>is_file($root.'/system/app/calendar_project.php'),
'csv engine'=>is_file($root.'/products/csv-engine/index.php'),
];
$setup=(string)@file_get_contents($root.'/products/calendar/setup.php');$svc=(string)@file_get_contents($root.'/system/app/calendar_project.php');$export=(string)@file_get_contents($root.'/app/projects/export.php');
$checks['setup sections']=str_contains($setup,'Datenquelle')&&str_contains($setup,'Datenpflege')&&str_contains($setup,'Zuordnungen')&&str_contains($setup,'Auslieferung');
$checks['csv dependency']=str_contains($svc,"\$driver==='csv'")&&str_contains($svc,"'csv-engine'");
$checks['calendar export dispatch']=str_contains($export,'enterprise_calendar_distribution_create');
$bad=array_keys(array_filter($checks,fn($v)=>!$v));foreach($checks as $k=>$v)echo ($v?'PASS ':'FAIL ').$k.PHP_EOL;if($bad){exit(1);}echo "HF90 Phase 9 Gate: PASS\n";
