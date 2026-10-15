<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$s=(string)file_get_contents($root.'/products/calendar/system/CalendarDataFormBridge.php');
$checks=[
 'TUTOR_VIEW_HELPER'=>str_contains($s,'function easyit_calendar_ensure_tutors_view'),
 'TUTOR_DATAFORM'=>str_contains($s,"'name'=>'Calendar – Tutoren'"),
 'TUTOR_FILTER_ROLE'=>str_contains($s,"r.role_key='tutor'"),
 'TUTOR_READONLY'=>str_contains($s,"'readonly'=>true"),
 'TUTOR_PROFILE'=>str_contains($s,"'tutors'=>['display_name'=>['label'=>'Tutor'"),
 'INSTALL_ENSURES_VIEW'=>substr_count($s,'easyit_calendar_ensure_tutors_view($pdo,$ctx)')>=2,
];
$ok=true;foreach($checks as $name=>$pass){echo ($pass?'PASS':'FAIL')." - $name\n";$ok=$ok&&$pass;}exit($ok?0:1);
