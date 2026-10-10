<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
    'setup.php',
    'installer/database.php',
    'DataForm5-Core/config/database.php',
    'DataForm5-Core/system/installer/Core/EnterpriseInstaller.php',
    'DataForm5-Core/.env.example',
    'DataForm5-Core/.env.production.example',
];
$forbidden=['storage/project-csv','storage/project-sqlite','project_csv_base','project_sqlite_base'];
$errors=[];
foreach($files as $rel){
    $path=$root.'/'.$rel;
    if(!is_file($path)){$errors[]='Fehlt: '.$rel;continue;}
    $txt=(string)file_get_contents($path);
    foreach($forbidden as $needle)if(str_contains($txt,$needle))$errors[]=$rel.' enthält Legacy-Pfad/-Feld: '.$needle;
}
$config=(string)@file_get_contents($root.'/DataForm5-Core/config/database.php');
$installer=(string)@file_get_contents($root.'/DataForm5-Core/system/installer/Core/EnterpriseInstaller.php');
if(!str_contains($config,'PROJECT_STORAGE_KEY'))$errors[]='Core-Konfiguration verwendet PROJECT_STORAGE_KEY nicht.';
if(!str_contains($config,"/sqlite/database.sqlite"))$errors[]='Kanonischer SQLite-Pfad fehlt.';
if(!str_contains($config,"/csv"))$errors[]='Kanonischer CSV-Pfad fehlt.';
if(!str_contains($installer,"'PROJECT_STORAGE_KEY'"))$errors[]='Installer schreibt PROJECT_STORAGE_KEY nicht.';
if(!str_contains($installer,'enterprise_project_store_create_for_project'))$errors[]='Installer verwendet projektorientierte Store-Provisionierung nicht.';
if($errors){echo "HF88 Storage Phase 6 Gate\nFAIL\n";foreach($errors as $e)echo ' - '.$e."\n";exit(2);} 
echo "HF88 Storage Phase 6 Gate\nPASS\n";
echo "Fresh installer and DB assistant use project-oriented storage; CSV/SQLite base-path inputs are removed.\n";
