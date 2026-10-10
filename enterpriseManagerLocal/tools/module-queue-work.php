<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';
enterprise_monitoring_heartbeat()->beat('queue-worker',['source'=>'cli']);

$connection=(string)($argv[1]??'file');
$maxJobs=max(0,(int)($argv[2]??0));
try{
    $manager=enterprise_container()->get(\DataForm5\Queue\Core\QueueManager::class);
    $worker=enterprise_container()->get(\DataForm5\Queue\Core\QueueWorker::class);
    $count=$worker->work($manager->connection($connection),$maxJobs);
    fwrite(STDOUT,"PROCESSED {$count}\n");
}catch(\Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n"); exit(1);
}
