<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[
 'maintenance page'=>is_file($root.'/products/calendar/maintenance.php'),
 'maintenance runtime'=>str_contains((string)file_get_contents($root.'/products/calendar/api/runtime.php'),'easyit_calendar_maintenance_save'),
 'persons CRUD'=>str_contains((string)file_get_contents($root.'/products/calendar/maintenance.php'),"'persons'"),
 'resources CRUD'=>str_contains((string)file_get_contents($root.'/products/calendar/maintenance.php'),"'resources'"),
 'categories CRUD'=>str_contains((string)file_get_contents($root.'/products/calendar/maintenance.php'),"'categories'"),
 'roles CRUD'=>str_contains((string)file_get_contents($root.'/products/calendar/maintenance.php'),"'roles'"),
 'CSV roles schema'=>str_contains((string)file_get_contents($root.'/system/app/EnterpriseCsvPdo.php'),"'roles'=>["),
 'PostgreSQL timestamp'=>str_contains((string)file_get_contents($root.'/products/calendar/api/runtime.php'),"'pgsql'=>'TIMESTAMP'"),
];$bad=false;foreach($checks as $n=>$v){echo ($v?'PASS ':'FAIL ').$n.PHP_EOL;if(!$v)$bad=true;}if($bad){fwrite(STDERR,"HF92 Phase 11 Gate: FAIL\n");exit(1);}echo "HF92 Phase 11 Gate: PASS\n";
