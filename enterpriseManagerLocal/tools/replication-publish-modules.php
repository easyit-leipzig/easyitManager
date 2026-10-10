<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

try{
    $base=dirname(__DIR__);
    $payload=['modules'=>enterprise_replication_snapshots()->moduleMetadata($base.'/modules')];
    $event=enterprise_replication()->publish('modules.snapshot',$payload);
    fwrite(STDOUT,"REPLICATION_PUBLISHED {$event->id}\n");
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);
}
