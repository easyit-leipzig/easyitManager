<?php
declare(strict_types=1);
return [
 'app'=>['environment'=>'development','base_path'=>dirname(__DIR__),'api_version'=>'1.0','clock_skew_seconds'=>300,'maintenance_mode'=>'off'],
 'database'=>['dsn'=>'','user'=>'','password'=>''],
 'security'=>['server_key_id'=>'SERVER-2026-01','server_private_key_file'=>dirname(__DIR__).'/secure/keys/server-private.key','server_public_key_file'=>dirname(__DIR__).'/secure/keys/server-public.key','server_keyring_file'=>dirname(__DIR__).'/secure/keys/server-keyring.json','server_key_rotation_grace_seconds'=>86400],
 'runtime'=>['lease_seconds'=>3600,'grace_seconds'=>86400,'action_ttl_seconds'=>60],
 'network'=>['trusted_proxy_ips'=>[]],
 'customer_portal'=>['session_name'=>'EASYITLICCUSTOMER','idle_timeout_seconds'=>1800,'max_failed_logins'=>5,'lock_seconds'=>900],
 'admin'=>['setup_token_file'=>dirname(__DIR__).'/secure/admin-setup.token','session_name'=>'EASYITLICADMIN','idle_timeout_seconds'=>1800,'max_failed_logins'=>5,'lock_seconds'=>900],
];
