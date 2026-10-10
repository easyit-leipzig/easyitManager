<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$type=(string)($_GET['type']??'');$file=(string)($_GET['file']??'');
$dirs=[
    'sync'=>$root.'/storage/assistant/trust/instances/sync',
    'disaster'=>$root.'/storage/assistant/trust/instances/disaster',
];
if(!isset($dirs[$type])||$file===''||basename($file)!==$file||str_contains($file,'..')||str_contains($file,'/')||str_contains($file,'\\')){http_response_code(400);exit('Ungültige Download-Anforderung.');}
$path=$dirs[$type].'/'.$file;if(!is_file($path)){http_response_code(404);exit('Federation-Datei wurde nicht gefunden.');}
$real=realpath($path);$base=realpath($dirs[$type]);if($real===false||$base===false||!str_starts_with($real,$base.DIRECTORY_SEPARATOR)){http_response_code(400);exit('Ungültiger Federation-Pfad.');}
$mime=str_ends_with($file,'.zip')?'application/zip':(str_ends_with($file,'.sha256')?'text/plain':'application/octet-stream');
header('Content-Type: '.$mime);header('Content-Length: '.(string)filesize($real));header('Content-Disposition: attachment; filename="'.str_replace('"','',$file).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store, private');readfile($real);
