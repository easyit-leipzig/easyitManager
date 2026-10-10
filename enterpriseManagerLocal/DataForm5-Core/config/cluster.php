<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'enabled' => filter_var(Env::get('CLUSTER_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'node_id' => Env::get('CLUSTER_NODE_ID', gethostname() ?: 'node-local'),
    'node_name' => Env::get('CLUSTER_NODE_NAME', gethostname() ?: 'Local Node'),
    'registry_path' => Env::get('CLUSTER_REGISTRY_PATH', 'storage/framework/cluster/nodes.json'),
    'heartbeat_ttl' => (int)Env::get('CLUSTER_HEARTBEAT_TTL', '90'),
    'leader_strategy' => Env::get('CLUSTER_LEADER_STRATEGY', 'lowest-node-id'),
    'shared_secret' => Env::get('CLUSTER_SHARED_SECRET', ''),
    'security_enabled' => filter_var(Env::get('CLUSTER_SECURITY_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
    'node_secret' => Env::get('CLUSTER_NODE_SECRET', Env::get('CLUSTER_SHARED_SECRET', '')),
    'trust_store_path' => Env::get('CLUSTER_TRUST_STORE_PATH', 'storage/framework/cluster/trusted-nodes.json'),
    'replay_store_path' => Env::get('CLUSTER_REPLAY_STORE_PATH', 'storage/framework/cluster/replay.json'),
    'signature_ttl' => (int)Env::get('CLUSTER_SIGNATURE_TTL', '120'),
];
