<?php
declare(strict_types=1);
$kernel=require dirname(__DIR__).'/DataForm5-Core/bootstrap/app.php';
$container=$kernel->container();
$manager=$container->get(\DataForm5\Cache\Core\CacheManager::class);
$ok=$manager->store()->clear();
$configCache=dirname(__DIR__).'/DataForm5-Core/storage/framework/cache/config.php';
if(is_file($configCache))@unlink($configCache);
fwrite(STDOUT,$ok?"CACHE_CLEAR_OK\n":"CACHE_CLEAR_PARTIAL\n");
exit($ok?0:1);
