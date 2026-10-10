<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

$projectStorageKey=(string)Env::get('PROJECT_STORAGE_KEY','');
$projectStorageRoot=dirname(__DIR__).'/../storage/projects/'.($projectStorageKey!==''?$projectStorageKey:'__unconfigured__');

return [
    'default' => Env::get('DB_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            'driver' => 'csv',
            'base_path' => dirname(__DIR__) . '/storage/projects/demo/csv',
            'database' => Env::get('CSV_DATABASE', 'demo'),
            'auto_connect' => true,
        ],

        'admin' => [
            'driver' => Env::get('ADMIN_DB_DRIVER', 'mysql'),
            'base_path' => Env::get('ADMIN_DB_CSV_BASE_PATH', dirname(__DIR__) . '/../storage/admin-csv'),
            'sqlite_base_path' => Env::get('ADMIN_DB_SQLITE_BASE_PATH', dirname(__DIR__) . '/../storage/admin-sqlite'),
            'path' => rtrim((string)Env::get('ADMIN_DB_SQLITE_BASE_PATH', dirname(__DIR__) . '/../storage/admin-sqlite'), '/\\') . '/' . Env::get('ADMIN_DB_DATABASE', 'muster_admin') . '.sqlite',
            'host' => Env::get('ADMIN_DB_HOST', '127.0.0.1'),
            'port' => Env::get('ADMIN_DB_PORT', 3306),
            'database' => Env::get('ADMIN_DB_DATABASE', 'muster_admin'),
            'schema' => Env::get('ADMIN_DB_SCHEMA', 'public'),
            'username' => Env::get('ADMIN_DB_USERNAME', 'root'),
            'password' => Env::get('ADMIN_DB_PASSWORD', ''),
            'charset' => Env::get('ADMIN_DB_CHARSET', 'utf8mb4'),
            'service_name' => Env::get('ADMIN_DB_ORACLE_SERVICE', 'XEPDB1'),
            'auto_connect' => false,
        ],

        'project' => [
            'driver' => Env::get('PROJECT_DB_DRIVER', 'mysql'),
            'storage_key' => $projectStorageKey,
            'base_path' => $projectStorageRoot . '/csv',
            'sqlite_base_path' => $projectStorageRoot . '/sqlite',
            'path' => $projectStorageRoot . '/sqlite/database.sqlite',
            'host' => Env::get('PROJECT_DB_HOST', '127.0.0.1'),
            'port' => Env::get('PROJECT_DB_PORT', 3306),
            'database' => Env::get('PROJECT_DB_DATABASE', 'muster_projekt'),
            'schema' => Env::get('PROJECT_DB_SCHEMA', 'public'),
            'username' => Env::get('PROJECT_DB_USERNAME', 'root'),
            'password' => Env::get('PROJECT_DB_PASSWORD', ''),
            'charset' => Env::get('PROJECT_DB_CHARSET', 'utf8mb4'),
            'service_name' => Env::get('PROJECT_DB_ORACLE_SERVICE', 'XEPDB1'),
            'auto_connect' => false,
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => dirname(__DIR__) . '/storage/projects/demo/database.sqlite',
            'auto_connect' => false,
        ],

        'oracle' => [
            'driver' => 'oracle',
            'host' => Env::get('ORACLE_DB_HOST', '127.0.0.1'),
            'port' => Env::get('ORACLE_DB_PORT', 1521),
            'service_name' => Env::get('ORACLE_DB_SERVICE', 'XEPDB1'),
            'username' => Env::get('ORACLE_DB_USERNAME', ''),
            'password' => Env::get('ORACLE_DB_PASSWORD', ''),
            'charset' => Env::get('ORACLE_DB_CHARSET', 'AL32UTF8'),
            'auto_connect' => false,
        ],
    ],
];
