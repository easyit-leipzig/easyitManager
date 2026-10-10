<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'default'=>Env::get('STORAGE_DISK','local'),
    'disks'=>[
        'local'=>[
            'driver'=>'local',
            'root'=>Env::get('STORAGE_LOCAL_ROOT','storage/app'),
        ],
        'shared'=>[
            'driver'=>'shared-filesystem',
            'root'=>Env::get('STORAGE_SHARED_ROOT','storage/shared'),
        ],
        's3'=>[
            'driver'=>'s3-compatible',
            'endpoint'=>Env::get('STORAGE_S3_ENDPOINT',''),
            'bucket'=>Env::get('STORAGE_S3_BUCKET',''),
            'region'=>Env::get('STORAGE_S3_REGION',''),
            'access_key'=>Env::get('STORAGE_S3_ACCESS_KEY',''),
            'secret_key'=>Env::get('STORAGE_S3_SECRET_KEY',''),
        ],
    ],
];
