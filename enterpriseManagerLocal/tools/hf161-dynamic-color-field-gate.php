<?php
declare(strict_types=1);
$root=dirname(__DIR__);$a=file_get_contents($root.'/products/calendar/admin.php');$h=file_get_contents($root.'/products/calendar/api/admin_handler.php');$tests=[];function c161(bool $ok,string $n){global $tests;$tests[]=[$ok,$n];echo ($ok?'PASS':'FAIL')."\t".$n."\n";}
c161(str_contains($a,'COLOR / Farbe'),'Designer bietet COLOR / Farbe an');
c161(str_contains($a,'SEMANTIC_TYPE')&&str_contains($a,"==='color'"),'Editor wertet semantischen Typ color aus');
c161(str_contains($a,'isColorFieldName'),'Namensfallback bleibt erhalten');
c161(str_contains($h,"'COLOR'")&&str_contains($h,"VARCHAR(7)"),'COLOR wird als VARCHAR(7) materialisiert');
c161(str_contains($h,"field_type='color'"),'Bestehendes DataForm-Feld wird auf color gesetzt');
c161(str_contains($h,"'color',\$pos"),'Neues DataForm-Feld wird als color registriert');
c161(str_contains($h,'calendar_admin_semantic_types'),'Spaltenantwort enthält DataForm-Feldtyp');
exit(count(array_filter($tests,fn($x)=>!$x[0]))?1:0);
