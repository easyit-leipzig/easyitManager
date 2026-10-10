<?php
declare(strict_types=1);
require __DIR__.'/_client.php';

$result=calendar_api('GET','places',null);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
