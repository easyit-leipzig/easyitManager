<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/system/assistant/module/DataFormModuleLibraryStore.php';
$id=(string)($_GET['id']??'');$visibility=(string)($_GET['visibility']??'system');$project=isset($_GET['project_id'])&&$_GET['project_id']!==''?(string)$_GET['project_id']:null;
try{
    $store=new \EasyIT\Assistant\Module\DataFormModuleLibraryStore($root);$meta=$store->getInScope($id,$visibility,$project);if($meta===[])throw new RuntimeException('Modul nicht gefunden.');$path=$store->packagePath($id,$visibility,$project);if(!is_file($path))throw new RuntimeException('Modulpaket fehlt.');
    $safe=preg_replace('/[^A-Za-z0-9._-]+/','-',(string)($meta['name']??$id))?:'dataform-module';
    header('Cache-Control: no-store');header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$safe.'.dataform-module.zip"');header('Content-Length: '.(string)filesize($path));header('X-Content-Type-Options: nosniff');readfile($path);
}catch(Throwable $e){http_response_code(404);header('Content-Type: text/plain; charset=UTF-8');echo 'Export nicht möglich: '.$e->getMessage();}
