<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function c160(bool $ok,string $name):void{global $checks;$checks[]=[$ok,$name];echo ($ok?'PASS':'FAIL')."\t".$name."\n";}
$admin=(string)file_get_contents($root.'/products/calendar/admin.php');
$stand=(string)file_get_contents($root.'/system/app/calendar_runtime/admin.php');
c160(str_contains($admin,'isColorFieldName'),'Manager erkennt Farbfelder generisch');
c160(str_contains($admin,'type="color"')&&str_contains($admin,'color-picker'),'Manager enthält Color-Picker');
c160(str_contains($admin,'clear-color')&&str_contains($admin,'#RRGGBB'),'Manager unterstützt Hexwert und Leeren');
c160(str_contains($stand,"'category_color'=>'Terminbalkenfarbe'")&&str_contains($stand,"'calendar_color'=>'Terminfarbe'"),'Standalone verwaltet Kategorie- und Personenfarbe');
c160(str_contains($stand,'type="color"')&&str_contains($stand,'clear-color'),'Standalone enthält Color-Picker und Leeren');
c160(str_contains($stand,"preg_match('/(^|_)(color|colour|farbe)($|_)/i'"),'Standalone erkennt Farbfelder generisch');
$version=trim((string)file_get_contents($root.'/VERSION'));
c160(str_contains($version,'HF160-UNIVERSAL-COLOR-PICKER'),'VERSION enthält HF160');
$fail=count(array_filter($checks,fn($x)=>!$x[0]));
echo "SUMMARY\t".($fail?'FAIL':'PASS')."\t".(count($checks)-$fail).'/'.count($checks)."\n";
exit($fail?1:0);
