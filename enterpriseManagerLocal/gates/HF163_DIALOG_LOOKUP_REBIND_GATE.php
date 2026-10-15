<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$f=$root.'/products/calendar/system/CalendarDataFormBridge.php';
$s=file_get_contents($f) ?: '';
$checks=[
 'FORCE_LOOKUP_HELPER'=>str_contains($s,'function easyit_calendar_force_lookup'),
 'CORE_REBIND_HELPER'=>str_contains($s,'function easyit_calendar_rebind_core_lookups'),
 'FIELD_TYPE_LOOKUP'=>str_contains($s,"SET field_type='lookup',configuration_json=?"),
 'PLACE_REBIND'=>str_contains($s,"easyit_calendar_force_lookup(\$pdo,'calendar_event_place'"),
 'RESOURCE_REBIND'=>str_contains($s,"easyit_calendar_force_lookup(\$pdo,'calendar_event_resource'"),
 'CATEGORY_REBIND'=>str_contains($s,"easyit_calendar_force_lookup(\$pdo,'calendar_event_category'"),
 'FAST_PATH_REBIND'=>str_contains($s,'easyit_calendar_rebind_core_lookups($pdo,$ctx,$bindings);'),
];
$ok=true;foreach($checks as $name=>$pass){echo $name.' '.($pass?'PASS':'FAIL').PHP_EOL;$ok=$ok&&$pass;}exit($ok?0:1);
