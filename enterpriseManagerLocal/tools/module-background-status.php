<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

try{
    $registry=enterprise_module_background();
    fwrite(STDOUT,"JOBS\n");
    foreach($registry->jobs() as $job) fwrite(STDOUT,$job['name']."\t".$job['module']."\t".$job['handler'].PHP_EOL);
    fwrite(STDOUT,"SCHEDULES\n");
    foreach($registry->schedules() as $task) fwrite(STDOUT,$task['name']."\t".$task['cron']."\t".$task['job'].PHP_EOL);
}catch(\Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n"); exit(1);
}
