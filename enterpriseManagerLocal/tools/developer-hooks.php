<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
$state=enterprise_hook_inspector()->inspect();
$query=strtolower(trim((string)($argv[1]??'')));
if($query!=='')$state['hooks']=array_filter($state['hooks'],static fn(array $row):bool=>
    str_contains(strtolower($row['module'].' '.$row['hook'].' '.$row['handler']),$query));
echo json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
