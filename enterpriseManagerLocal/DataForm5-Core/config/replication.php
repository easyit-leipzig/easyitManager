<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'enabled' => filter_var(Env::get('REPLICATION_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'driver' => Env::get('REPLICATION_DRIVER', 'shared-storage'),
    'channel' => Env::get('REPLICATION_CHANNEL', 'enterprise'),
    'storage_disk' => Env::get('REPLICATION_STORAGE_DISK', 'shared'),
    'base_path' => Env::get('REPLICATION_BASE_PATH', 'replication'),
    'node_id' => Env::get('CLUSTER_NODE_ID', gethostname() ?: 'node-local'),
    'max_events' => (int)Env::get('REPLICATION_MAX_EVENTS', '1000'),
    'security_enabled' => filter_var(Env::get('CLUSTER_SECURITY_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'node_secret' => Env::get('CLUSTER_NODE_SECRET', Env::get('CLUSTER_SHARED_SECRET', '')),
    'signature_ttl' => (int)Env::get('CLUSTER_SIGNATURE_TTL', '120'),
];
