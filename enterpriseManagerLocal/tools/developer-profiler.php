<?php
declare(strict_types=1);
putenv('DEVELOPER_MODE=true');
require dirname(__DIR__).'/system/app/bootstrap.php';
$state=enterprise_profiler()->snapshot();
echo json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
