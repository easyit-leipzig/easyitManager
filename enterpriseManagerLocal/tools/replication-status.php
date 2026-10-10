<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';

try{
    fwrite(STDOUT,json_encode(enterprise_replication()->health(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);
}catch(Throwable $e){
    fwrite(STDERR,"ERROR: {$e->getMessage()}\n");exit(1);
}
