<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/system/app/project_storage.php';

$root=dirname(__DIR__);
$apply=in_array('--repair',$argv,true);
$projectsRoot=$root.'/storage/projects';
$required=['csv','sqlite','pgsql','pgsql/dumps','documents','documents/dataform','documents/calendar','documents/attachments','documents/imports','documents/generated','backup','export','temp'];
$errors=[];$warnings=[];$checked=0;$repaired=0;
if(!is_dir($projectsRoot)){echo "HF88 Storage Project Integrity\nFAIL\n - storage/projects fehlt.\n";exit(2);}
foreach(glob($projectsRoot.'/prj_*',GLOB_ONLYDIR)?:[] as $dir){
    $checked++;$key=basename($dir);
    try{enterprise_project_storage_validate_key($key);}catch(Throwable $e){$errors[]="$key: ungültiger storage_key";continue;}
    foreach($required as $rel){
        $p=$dir.'/'.$rel;
        if(!is_dir($p)){
            if($apply){enterprise_project_storage_mkdir($p);$repaired++;}
            else $errors[]="$key: Ordner fehlt: $rel";
        }
    }
    $manifest=$dir.'/project.json';
    if(!is_file($manifest)){$errors[]="$key: project.json fehlt";continue;}
    try{$m=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){$errors[]="$key: project.json ist ungültig";continue;}
    if((string)($m['storage_key']??'')!==$key)$errors[]="$key: project.json.storage_key stimmt nicht mit Ordner überein";
    if((int)($m['project_id']??0)<1)$errors[]="$key: project.json.project_id fehlt/ungültig";
    if(!is_file($dir.'/.htaccess')){$warnings[]="$key: .htaccess fehlt";}
}
echo "HF88 Storage Project Integrity\n";
echo $errors?"FAIL\n":"PASS\n";
echo "Geprüfte Projektordner: $checked\n";
if($apply)echo "Reparierte Ordner: $repaired\n";
foreach($errors as $e)echo " - ERROR: $e\n";
foreach($warnings as $e)echo " - WARN: $e\n";
exit($errors?2:0);
