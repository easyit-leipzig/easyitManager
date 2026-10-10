<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$dir=$root.'/DataForm5-Core/storage/framework/cache/data';
$files=is_dir($dir)?(glob($dir.'/*.cache')?:[]):[];
$bytes=0;foreach($files as $file)$bytes+=(int)(filesize($file)?:0);
echo json_encode(['cache_files'=>count($files),'bytes'=>$bytes,'config_cache'=>is_file($root.'/DataForm5-Core/storage/framework/cache/config.php')],JSON_PRETTY_PRINT).PHP_EOL;
