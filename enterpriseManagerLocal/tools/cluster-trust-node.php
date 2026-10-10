<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

$nodeId=trim((string)($argv[1]??''));
$secret=(string)($argv[2]??'');
$label=(string)($argv[3]??'');
if($nodeId===''||$secret===''){
    fwrite(STDERR,"Usage: php tools/cluster-trust-node.php <node-id> <secret> [label]\n");
    exit(2);
}
try{
    enterprise_cluster_trust_store()->trust($nodeId,$secret,$label);
    fwrite(STDOUT,"TRUSTED {$nodeId}\n");
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);
}
