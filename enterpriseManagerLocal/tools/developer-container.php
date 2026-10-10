<?php
declare(strict_types=1);

$kernel=require dirname(__DIR__).'/DataForm5-Core/bootstrap/app.php';
$inspector=$kernel->container()->get(\DataForm5\Core\Developer\ContainerInspector::class);
$state=$inspector->inspect();
$query=strtolower(trim((string)($argv[1]??'')));
if($query!==''){
    $state['services']=array_filter($state['services'],static fn(array $row,string $id):bool=>
        str_contains(strtolower($id.' '.($row['class']??'')),$query),ARRAY_FILTER_USE_BOTH);
}
echo json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
