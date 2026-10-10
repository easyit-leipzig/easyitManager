<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

try{
    $results=enterprise_replication()->consume(max(1,(int)($argv[1]??100)));
    fwrite(STDOUT,json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);
}
