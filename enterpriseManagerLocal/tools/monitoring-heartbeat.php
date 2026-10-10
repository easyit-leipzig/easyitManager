<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
$name=(string)($argv[1]??'worker'); enterprise_monitoring_heartbeat()->beat($name,['source'=>'cli']); echo "HEARTBEAT {$name}\n";
