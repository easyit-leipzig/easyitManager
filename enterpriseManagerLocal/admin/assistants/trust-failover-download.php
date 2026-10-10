<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$file=(string)($_GET['file']??'');
try{
    if($file===''||basename($file)!==$file||!preg_match('/^[A-Za-z0-9._-]+\.json$/',$file))throw new RuntimeException('Ungültiger Failover-Dateiname.');
    $dir=$root.'/storage/assistant/trust/instances/failover/outbox';
    $path=$dir.'/'.$file;
    if(!is_file($path)||realpath(dirname($path))!==realpath($dir))throw new RuntimeException('Failover-Artefakt wurde nicht gefunden.');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.basename($file).'"');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
}catch(Throwable $e){http_response_code(400);header('Content-Type: text/plain; charset=utf-8');echo $e->getMessage();}
