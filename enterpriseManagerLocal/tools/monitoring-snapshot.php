<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
echo json_encode(enterprise_monitoring()->snapshot(true),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
