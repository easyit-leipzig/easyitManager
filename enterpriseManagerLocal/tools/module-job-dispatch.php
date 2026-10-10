<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

$args=$argv; array_shift($args);
$job=(string)($args[0]??'');
if($job===''){
    fwrite(STDERR,"Usage: php tools/module-job-dispatch.php <job-name> [json-payload] [delay] [queue]\n");
    exit(2);
}
$payload=[];
if(isset($args[1]) && trim((string)$args[1])!==''){
    $decoded=json_decode((string)$args[1],true);
    if(!is_array($decoded)){fwrite(STDERR,"Payload muss gültiges JSON-Objekt sein.\n");exit(2);}
    $payload=$decoded;
}
$delay=max(0,(int)($args[2]??0));
$queue=isset($args[3]) && $args[3]!==''?(string)$args[3]:null;
try{
    $id=enterprise_module_background()->dispatch($job,$payload,$delay,$queue);
    fwrite(STDOUT,"DISPATCHED {$job} {$id}\n");
}catch(\Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n"); exit(1);
}
