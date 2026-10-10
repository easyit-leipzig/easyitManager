<?php
declare(strict_types=1);
$root=dirname(__DIR__);$fails=[];$passes=[];
$check=function(bool $ok,string $label)use(&$fails,&$passes){if($ok)$passes[]=$label;else$fails[]=$label;};
$auth=(string)file_get_contents($root.'/src/Admin/AdminAuth.php');
$app=(string)file_get_contents($root.'/src/Admin/AdminApp.php');
$doc=(string)file_get_contents($root.'/docs/HF86_PHASE5.md');
$check(str_contains($auth,'ensureSetupToken'),'lokale Setup-Token-Erzeugung vorhanden');
$check(str_contains($auth,'random_bytes(32)'),'256-Bit-Zufall für Setup-Token vorhanden');
$check(str_contains($auth,'fopen($file,\'x\')'),'exklusive Token-Dateierzeugung vorhanden');
$check(str_contains($auth,'chmod($file,0600)'),'restriktive Dateirechte vorgesehen');
$check(str_contains($auth,"Require all denied"),'lokale HTTP-Sperre als Defense-in-Depth vorhanden');
$check(str_contains($auth,'@unlink($file)'),'Token wird nach Einrichtung entfernt');
$check(str_contains($app,'Das einmalige Setup-Token ist bereit'),'Setup-Oberfläche erklärt lokalen Tokenpfad');
$check(!is_file($root.'/secure/admin-setup.token'),'kein Setup-Token im Releasebaum ausgeliefert');
$check(str_contains($doc,'never added to a release archive')&&str_contains($doc,'256-bit random token'),'Dokumentation des lokalen Einmal-Tokens vorhanden');
foreach($passes as $p)echo "PASS  $p\n";
foreach($fails as $f)echo "FAIL  $f\n";
echo $fails?"FINAL STATUS: FAILED\n":"FINAL STATUS: PASSED\n";exit($fails?1:0);
