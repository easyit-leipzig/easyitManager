<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

try{
    $node=enterprise_cluster()->heartbeat(['cli'=>true,'pid'=>getmypid()]);
    fwrite(STDOUT,"CLUSTER_HEARTBEAT {$node->id} {$node->heartbeatAt}\n");
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n");
    exit(1);
}
