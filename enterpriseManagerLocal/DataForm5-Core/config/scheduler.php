<?php
declare(strict_types=1);
return [
    'mutex_driver' => getenv('SCHEDULER_MUTEX_DRIVER') ?: 'file',
    'mutex_table' => getenv('SCHEDULER_MUTEX_TABLE') ?: 'enterprise_cluster_locks',
    'db_driver' => getenv('SCHEDULER_DB_DRIVER') ?: (getenv('ADMIN_DB_DRIVER') ?: 'mysql'),
    'db_host' => getenv('SCHEDULER_DB_HOST') ?: (getenv('ADMIN_DB_HOST') ?: '127.0.0.1'),
    'db_port' => (int)(getenv('SCHEDULER_DB_PORT') ?: (getenv('ADMIN_DB_PORT') ?: 3306)),
    'db_database' => getenv('SCHEDULER_DB_DATABASE') ?: (getenv('ADMIN_DB_DATABASE') ?: ''),
    'db_schema' => getenv('SCHEDULER_DB_SCHEMA') ?: (getenv('ADMIN_DB_SCHEMA') ?: 'public'),
    'db_username' => getenv('SCHEDULER_DB_USERNAME') ?: (getenv('ADMIN_DB_USERNAME') ?: ''),
    'db_password' => getenv('SCHEDULER_DB_PASSWORD') ?: (getenv('ADMIN_DB_PASSWORD') ?: ''),
    'node_id' => getenv('CLUSTER_NODE_ID') ?: (gethostname() ?: 'node-local'),
    'lock_path' => getenv('SCHEDULER_LOCK_PATH') ?: 'storage/framework/scheduler/locks',
    'history_path' => getenv('SCHEDULER_HISTORY_PATH') ?: 'storage/framework/scheduler/history',
];
