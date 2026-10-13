<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=0;
function c158(bool $ok,string $n):void{global $fail;echo ($ok?'PASS':'FAIL')."  $n\n";if(!$ok)$fail++;}
$display=trim((string)@file_get_contents($root.'/DISPLAY_VERSION'));
$technical=trim((string)@file_get_contents($root.'/VERSION'));
$layout=(string)@file_get_contents($root.'/system/ui/layout.php');
$workspace=(string)@file_get_contents($root.'/products/dataform/system/WorkspaceLayout.php');
$runtime=(string)@file_get_contents($root.'/products/dataform/runtime.php');
c158($display==='RC1.8','DISPLAY_VERSION ist kompakt');
c158(strlen($technical)>strlen($display),'Technische VERSION bleibt separat erhalten');
c158(str_contains($layout,"/DISPLAY_VERSION"),'Globales Layout verwendet DISPLAY_VERSION');
c158(!str_contains($layout,'$versionFile = dirname(__DIR__, 2) . \'/VERSION\''),'Globales Layout liest technische VERSION nicht mehr sichtbar');
c158(str_contains($workspace,"/DISPLAY_VERSION"),'DataForm Workspace verwendet DISPLAY_VERSION');
c158(str_contains($runtime,"/DISPLAY_VERSION"),'DataForm Runtime verwendet DISPLAY_VERSION');
c158(str_contains($layout,'enterprise-manager-brand__version'),'Versionsplatz im Header bleibt vorhanden');
echo 'HF158 Compact Visible Version Gate: '.($fail?'FAIL':'PASS')."\n";exit($fail?1:0);
