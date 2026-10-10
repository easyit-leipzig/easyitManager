<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];function c150(bool $ok,string $name):void{global $checks;$checks[]=[$ok,$name];}
$fc=fn(string $f)=>(string)@file_get_contents($root.'/'.$f);
$runtime=$fc('products/calendar/api/runtime.php');$bridge=$fc('products/calendar/system/CalendarDataFormBridge.php');$handler=$fc('products/calendar/api/calendar_handler.php');$js=$fc('products/calendar/assets/js/calendar.js');$css=$fc('products/calendar/assets/css/calendar.css');$standalone=$fc('system/app/calendar_runtime/api.php');$bootstrap=$fc('products/calendar/assets/js/bootstrap.js');$index=$fc('products/calendar/index.php');
c150(str_contains($runtime,"category_color")&&str_contains($runtime,"Kategorie-Terminfarbe"),'Schema/Migration erweitert Kategorien um category_color');
c150(str_contains($bridge,"Terminbalkenfarbe")&&str_contains($bridge,"type'=>'color"),'Kategorie-DataForm besitzt Color-Picker');
c150(str_contains($handler,"category_color")&&str_contains($handler,"categoryColorById"),'Manager-API liefert Kategoriefarbe pro Termin');
c150(str_contains($standalone,"category_color")&&str_contains($standalone,"categoryColorById"),'Standalone-API liefert Kategoriefarbe pro Termin');
c150(str_contains($js,'--category-color')&&str_contains($js,'category_color'),'Calendar-JavaScript reicht Kategoriefarbe an CSS durch');
c150(str_contains($css,'border-left: 5px solid var(--category-color'),'Linker Terminbalken verwendet Kategoriefarbe');
c150(str_contains($bootstrap,'calendar.js?v=20261008-150')&&str_contains($index,'calendar.css?v=20261008-150'),'Cache-Buster auf HF150 gesetzt');
c150(is_file($root.'/products/calendar/sql/migration_v4_to_v5_category_color.sql'),'SQL-Migration vorhanden');
$fail=array_filter($checks,fn($x)=>!$x[0]);foreach($checks as [$ok,$name])echo ($ok?'PASS':'FAIL').': '.$name."\n";echo 'HF150 Category calendar color gate: '.($fail?'FAIL':'PASS').' ('.(count($checks)-count($fail)).'/'.count($checks).")\n";exit($fail?1:0);
