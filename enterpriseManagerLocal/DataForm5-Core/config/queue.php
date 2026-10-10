<?php
declare(strict_types=1);
use DataForm5\Core\Configuration\Env;
return [
    'default'=>Env::get('QUEUE_CONNECTION','sync'),
    'connections'=>[
        'sync'=>['driver'=>'sync'],
        'file'=>['driver'=>'file','path'=>Env::get('QUEUE_PATH','storage/framework/queue')],
        'database'=>[
            'driver'=>'database',
            'db_driver'=>Env::get('QUEUE_DB_DRIVER',Env::get('ADMIN_DB_DRIVER','mysql')),
            'host'=>Env::get('QUEUE_DB_HOST',Env::get('ADMIN_DB_HOST','127.0.0.1')),
            'port'=>(int)Env::get('QUEUE_DB_PORT',Env::get('ADMIN_DB_PORT','3306')),
            'database'=>Env::get('QUEUE_DB_DATABASE',Env::get('ADMIN_DB_DATABASE','')),
            'schema'=>Env::get('QUEUE_DB_SCHEMA',Env::get('ADMIN_DB_SCHEMA','public')),
            'username'=>Env::get('QUEUE_DB_USERNAME',Env::get('ADMIN_DB_USERNAME','')),
            'password'=>Env::get('QUEUE_DB_PASSWORD',Env::get('ADMIN_DB_PASSWORD','')),
            'charset'=>'utf8mb4',
            'table'=>Env::get('QUEUE_DB_TABLE','enterprise_queue_jobs'),
            'worker_id'=>Env::get('CLUSTER_NODE_ID',gethostname()?:'node-local'),
        ],
    ],
];
