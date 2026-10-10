<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=[];
function c145(bool $ok,string $m):void{global $fail;echo ($ok?'PASS ':'FAIL ').$m."\n";if(!$ok)$fail[]=$m;}
$runtime=(string)file_get_contents($root.'/products/calendar/api/runtime.php');
$bridge=(string)file_get_contents($root.'/products/calendar/system/CalendarDataFormBridge.php');
$handler=(string)file_get_contents($root.'/products/calendar/api/calendar_handler.php');
$js=(string)file_get_contents($root.'/products/calendar/assets/js/calendar.js');
$css=(string)file_get_contents($root.'/products/calendar/assets/css/calendar.css');
$standalone=(string)file_get_contents($root.'/system/app/calendar_runtime/api.php');
c145(str_contains($runtime,"'calendar_color'")&&str_contains($runtime,'addDataColumn'),'Schema/Upgrade enthält calendar_color');
c145(str_contains($bridge,"'label'=>'Terminfarbe'")&&str_contains($bridge,"'type'=>'color'"),'Personen-DataForm enthält Color-Picker');
c145(str_contains($handler,"'student_color'")&&str_contains($handler,'studentColorById'),'Manager-API liefert Schülerfarbe');
c145(str_contains($css,'.lesson::after')&&str_contains($css,'--student-color'),'CSS markiert rechte untere Ecke');
c145(str_contains($js,'lesson.student_color')&&str_contains($js,'student_color:'),'Frontend übernimmt Schülerfarbe');
$bootstrap=(string)file_get_contents($root.'/products/calendar/assets/js/bootstrap.js');c145(str_contains($bootstrap,'calendar.js?v=20261007-145'),'Calendar-JavaScript Cache-Buster ist HF145');
c145(str_contains($standalone,'participantsForm')&&str_contains($standalone,'studentColorById')&&str_contains($standalone,"'student_color'"),'Standalone-Export löst Teilnehmer und Schülerfarbe auf');
c145(is_file($root.'/products/calendar/sql/migration_v3_to_v4_student_calendar_color.sql'),'MySQL-Migrationsskript vorhanden');
echo 'HF145 Student Calendar Color Gate: '.($fail?'FAIL':'PASS')."\n";exit($fail?1:0);
