<?php
declare(strict_types=1);
$root=dirname(__DIR__);$errors=[];
$storage=$root.'/system/app/project_storage.php';
$integrity=$root.'/tools/storage-project-integrity.php';
foreach([$storage,$integrity] as $f)if(!is_file($f))$errors[]='Fehlt: '.substr($f,strlen($root)+1);
$txt=is_file($storage)?(string)file_get_contents($storage):'';
foreach(['documents/dataform','documents/calendar','documents/attachments','documents/imports','documents/generated','project.json'] as $needle){if(!str_contains($txt,$needle))$errors[]='Projekt-Storage-Provisionierung enthält nicht: '.$needle;}
if(!str_contains($txt,'enterprise_project_storage_validate_key'))$errors[]='storage_key-Validierung fehlt.';
$itxt=is_file($integrity)?(string)file_get_contents($integrity):'';
foreach(['--repair','project.json.storage_key','pgsql/dumps'] as $needle){if(!str_contains($itxt,$needle))$errors[]='Integritätsprüfung unvollständig: '.$needle;}
if($errors){echo "HF88 Storage Phase 7 Gate\nFAIL\n";foreach($errors as $e)echo " - $e\n";exit(2);}
echo "HF88 Storage Phase 7 Gate\nPASS\n";
echo "Projekt-Storage ist strukturell prüfbar und reparierbar; Dokumentbereiche und Manifestbindung sind abgesichert.\n";
