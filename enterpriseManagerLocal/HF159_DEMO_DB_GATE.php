<?php
declare(strict_types=1);
$root=__DIR__;
$setup=(string)file_get_contents($root.'/setup.php');
$store=(string)file_get_contents($root.'/system/app/project_store.php');
$version=trim((string)file_get_contents($root.'/VERSION'));
$display=trim((string)file_get_contents($root.'/DISPLAY_VERSION'));
$checks=[
 'EXISTING_DB_PROBE'=>str_contains($setup,'setup_project_store_exists($form)'),
 'SESSION_CONFIRMATION'=>str_contains($setup,"setup_project_db_confirmation"),
 'REUSE_ACTION'=>str_contains($setup,'value="reuse"') && str_contains($setup,'Vorhandene Demo-DB verwenden'),
 'RECREATE_ACTION'=>str_contains($setup,'value="recreate"') && str_contains($setup,'Demo-DB löschen und neu anlegen'),
 'DELETE_CONFIRM'=>str_contains($setup,'SICHERHEITSABFRAGE') && str_contains($setup,'window.confirm'),
 'DELETE_VERIFY'=>str_contains($setup,'Sicherheitsprüfung fehlgeschlagen: Die Projektdatenbank ist nach dem Löschen weiterhin vorhanden'),
 'MYSQL_REUSE'=>str_contains($store,'Existing project databases are a supported reuse path') && str_contains($store,'INFORMATION_SCHEMA.SCHEMATA'),
 'HF159_VERSION'=>str_contains($version,'HF159-DEMO-DB-REUSE-RECREATE'),
 'DISPLAY_VERSION_COMPACT'=>$display==='RC1.8',
];
$failed=[];
foreach($checks as $name=>$ok){echo $name."\t".($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$failed[]=$name;}
exit($failed?1:0);
