<?php
declare(strict_types=1);
return [
 'enabled'=>true,
 'server_url'=>'http://localhost/easyit-license-server',
 'surface_origin'=>'http://localhost',
 'connect_timeout'=>5,
 'request_timeout'=>15,
 'verify_tls'=>false,
 'storage_dir'=>dirname(__DIR__).'/storage/licensing',
 // localhost: der Public Key wird beim ersten Verbindungs-Test automatisch geladen.
 'trusted_server_keys'=>[],
];
