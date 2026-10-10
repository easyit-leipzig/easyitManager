<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$patterns=['storage/project-csv','storage/project-sqlite','storage/dataform/uploads','storage/project-delete-backups','storage/project-restore-previews','storage/project-distribution-packages','PROJECT_DB_CSV_BASE_PATH','PROJECT_DB_SQLITE_BASE_PATH'];
$deferred=['/tools/storage-project-','/tools/storage-audit.php','/docs/','/HF88_STORAGE_','/recovery.php','/setup.php','/installer/','/DataForm5-Core/','/system/app/project_store.php','/system/app/project_runtime/media.php','/system/app/project_distribution.php','/products/dataform/system/DataFormFieldTypes.php'];
$active=[];$later=[];
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as $f){
    if(!$f instanceof SplFileInfo||!$f->isFile()||$f->isLink())continue;
    $path=str_replace('\\','/',$f->getPathname());
    if(!preg_match('/\.(php|md|example)$/i',$path) && basename($path)!=='.env')continue;
    $txt=@file_get_contents($path);if(!is_string($txt))continue;
    foreach($patterns as $pattern){
        if(strpos($txt,$pattern)===false)continue;
        $rel=ltrim(substr($path,strlen(str_replace('\\','/',$root))),'/');
        $isDeferred=false;foreach($deferred as $d){if(strpos('/'.$rel,$d)!==false){$isDeferred=true;break;}}
        if($isDeferred)$later[$rel][]=$pattern; else $active[$rel][]=$pattern;
    }
}
echo "HF88 Storage Audit\n==================\n";
if($active===[])echo "PASS: Keine Legacy-Referenzen im aktiven, bereits umgestellten Laufzeitcode gefunden.\n";
else{echo "FAIL: Aktive Legacy-Referenzen:\n";foreach($active as $file=>$hits)echo " - {$file}: ".implode(', ',array_unique($hits))."\n";}
echo "\nBewusste Legacy-/Migrationsreferenzen (nur Lesen, Prüfung oder Phase 6):\n";
foreach($later as $file=>$hits)echo " - {$file}: ".implode(', ',array_unique($hits))."\n";
exit($active===[]?0:2);
