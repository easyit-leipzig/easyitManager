<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
$must=['products/calendar/api/runtime.php','products/calendar/api/db.php','products/calendar/api/calendar_handler.php','products/calendar/setup.php','system/app/EnterpriseCsvPdo.php'];foreach($must as $f)if(!is_file($root.'/'.$f))$fail[]='missing '.$f;
$checks=[
 ['products/calendar/api/db.php','easyit_calendar_db'],
 ['products/calendar/api/runtime.php','enterprise_project_store_for_project'],
 ['products/calendar/api/runtime.php','easyit_calendar_schema_install'],
 ['products/calendar/setup.php','initialize_schema'],
 ['system/app/EnterpriseCsvPdo.php',"'events'=>"],
];foreach($checks as [$f,$n])if(!str_contains((string)file_get_contents($root.'/'.$f),$n))$fail[]="$f lacks $n";
$handler=(string)file_get_contents($root.'/products/calendar/api/calendar_handler.php');if(!str_contains($handler,'easyit_calendar_table')&&!str_contains($handler,'DataFormRecordStore'))$fail[]='calendar handler has neither legacy table adapter nor HF105 DataFormRecordStore';
$db=(string)file_get_contents($root.'/products/calendar/api/db.php');if(str_contains($db,'mysql:host='))$fail[]='hard-coded mysql DSN remains';
if($fail){fwrite(STDERR,"HF91 Phase 10 Gate: FAIL\n- ".implode("\n- ",$fail)."\n");exit(1);}echo "HF91 Phase 10 Gate: PASS\n";
