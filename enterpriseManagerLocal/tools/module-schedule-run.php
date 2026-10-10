<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';
enterprise_monitoring_heartbeat()->beat('scheduler',['source'=>'cli']);

try{
    $scheduler=enterprise_container()->get(\DataForm5\Scheduler\Core\Scheduler::class);
    $results=$scheduler->runDue();
    foreach($results as $name=>$status) fwrite(STDOUT,$name."\t".$status.PHP_EOL);
    if($results===[]) fwrite(STDOUT,"NO_DUE_TASKS\n");
}catch(\Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n"); exit(1);
}
