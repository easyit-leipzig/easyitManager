<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fail=0;
$check=function(string $n,bool $ok)use(&$fail):void{echo($ok?'PASS ':'FAIL ').$n.PHP_EOL;if(!$ok)$fail++;};
$a=(string)file_get_contents($root.'/app/account.php');
$check('extension is default for licensed customer',str_contains($a,'<option value="extension" selected>Lizenz erweitern</option>'));
$check('visible top submit exists',substr_count($a,'Lizenzerweiterung beantragen')>=2);
$check('current installation limit prefilled',str_contains($a,'$currentMaxInstall'));
$check('current dataform limit prefilled',str_contains($a,'$currentMaxDataformsValue'));
$check('current modules preselected',str_contains($a,'$currentModuleMap'));
$check('current capabilities preselected',str_contains($a,'$currentCapabilityMap'));
$check('advanced section collapsible',str_contains($a,'<details class="full">'));
$check('non admin gets explicit message',str_contains($a,'Keine Antragsberechtigung'));
$check('misleading additional license option removed',!str_contains($a,'Zusätzliche Lizenz'));
exit($fail===0?0:1);
