<?php
declare(strict_types=1);
use DataForm5\Core\Configuration\Env;
return [
    'configuration'=>Env::get('CONFIG_CACHE',false),
    'path'=>'storage/framework/cache/config.php',
    'module_discovery_ttl'=>(int)Env::get('MODULE_DISCOVERY_CACHE_TTL','3600'),
    'default'=>Env::get('CACHE_STORE','file'),
    'stores'=>[
        'file'=>['driver'=>'file','path'=>Env::get('CACHE_PATH','storage/framework/cache/data'),'namespace'=>Env::get('CACHE_NAMESPACE','dataform5')],
        'array'=>['driver'=>'array'],
    ],
];
