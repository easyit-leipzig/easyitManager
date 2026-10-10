<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$type=(string)($_GET['type']??'');$file=(string)($_GET['file']??'');
$dirs=[
    'public'=>$root.'/storage/assistant/trust/recovery/public',
    'private'=>$root.'/storage/assistant/trust/recovery/private',
    'offline'=>$root.'/storage/assistant/trust/recovery/offline',
];
if(!isset($dirs[$type])||$file===''||basename($file)!==$file||str_contains($file,'..')||str_contains($file,'/')||str_contains($file,'\\')){http_response_code(400);exit('Ungültige Download-Anforderung.');}
$path=$dirs[$type].'/'.$file;if(!is_file($path)){http_response_code(404);exit('Recovery-Datei wurde nicht gefunden.');}
$real=realpath($path);$base=realpath($dirs[$type]);if($real===false||$base===false||!str_starts_with($real,$base.DIRECTORY_SEPARATOR)){http_response_code(400);exit('Ungültiger Recovery-Pfad.');}
$ext=strtolower(pathinfo($file,PATHINFO_EXTENSION));$mime=$ext==='zip'?'application/zip':'application/octet-stream';if(str_ends_with($file,'.json'))$mime='application/json';if(str_ends_with($file,'.sha256'))$mime='text/plain';
header('Content-Type: '.$mime);header('Content-Length: '.(string)filesize($real));header('Content-Disposition: attachment; filename="'.str_replace('"','',$file).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store, private');readfile($real);
