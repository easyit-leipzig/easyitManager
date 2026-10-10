<?php
declare(strict_types=1);

return [
    'app' => [
        'environment' => getenv('EASYIT_ENV') ?: 'production',
        'base_path' => dirname(__DIR__),
        'api_version' => '1.0',
        'clock_skew_seconds' => 300,
        'maintenance_mode' => getenv('EASYIT_MAINTENANCE') ?: 'off', // off|read_only|full
    ],
    'database' => [
        // mysql:host=127.0.0.1;port=3306;dbname=easyit_license;charset=utf8mb4
        // pgsql:host=127.0.0.1;port=5432;dbname=easyit_license
        'dsn' => getenv('EASYIT_LICENSE_DSN') ?: '',
        'user' => getenv('EASYIT_LICENSE_DB_USER') ?: '',
        'password' => getenv('EASYIT_LICENSE_DB_PASSWORD') ?: '',
    ],
    'security' => [
        'server_key_id' => getenv('EASYIT_SERVER_KEY_ID') ?: 'SERVER-2026-01',
        'server_private_key_file' => getenv('EASYIT_SERVER_PRIVATE_KEY') ?: dirname(__DIR__) . '/secure/keys/server-private.key',
        'server_public_key_file' => getenv('EASYIT_SERVER_PUBLIC_KEY') ?: dirname(__DIR__) . '/secure/keys/server-public.key',
        // HF86 Phase 11: local keyring metadata. Private keys remain below secure/ and are never shipped.
        'server_keyring_file' => getenv('EASYIT_SERVER_KEYRING') ?: dirname(__DIR__) . '/secure/keys/server-keyring.json',
        'server_key_rotation_grace_seconds' => 86400,
    ],
    'runtime' => [
        'lease_seconds' => 3600,
        'grace_seconds' => 86400,
        'action_ttl_seconds' => 60,
    ],

    'network' => [
        // Nur hier eingetragene Reverse-Proxy-IP-Adressen dürfen X-Forwarded-For liefern.
        // Bei direktem Shared Hosting normalerweise leer lassen.
        'trusted_proxy_ips' => [],
    ],
    'customer_portal' => [
        'session_name' => 'EASYITLICCUSTOMER',
        'idle_timeout_seconds' => 1800,
        'max_failed_logins' => 5,
        'lock_seconds' => 900,
    ],
    'admin' => [
        // Das Setup-Token wird nach Anlage des ersten Superadmins automatisch gelöscht.
        'setup_token_file' => dirname(__DIR__) . '/secure/admin-setup.token',
        'session_name' => 'EASYITLICADMIN',
        'idle_timeout_seconds' => 1800,
        'max_failed_logins' => 5,
        'lock_seconds' => 900,
    ],
];
