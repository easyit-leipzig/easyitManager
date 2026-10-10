<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
$state=enterprise_event_inspector()->inspect();
$query=strtolower(trim((string)($argv[1]??'')));
if($query!=='')$state['events']=array_filter($state['events'],static fn(array $row):bool=>
    str_contains(strtolower($row['name'].' '.$row['description'].' '.$row['producer']),$query));
echo json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
