<?php
/**
 * Beispielkonfiguration für DataForm5 STAND 4 / MSSQL.
 * Diese Datei enthält absichtlich keine produktiven Zugangsdaten.
 *
 * type   = logischer easyIT-Speichertyp
 * driver = tatsächlicher PDO-Treiber
 */
return [
    'administration' => [
        'type' => 'mssql',
        'driver' => 'sqlsrv',
        'host' => '127.0.0.1',
        'port' => 1433,
        'database' => 'easyit_license',
        'username' => '',
        'password' => '',
        'schema' => 'dbo',
        'encrypt' => true,
        'trust_server_certificate' => false,
        'login_timeout' => 15,
    ],

    'project' => [
        'type' => 'mssql',
        'driver' => 'sqlsrv',
        'host' => '127.0.0.1',
        'port' => 1433,
        'database' => 'easyit_project',
        'username' => '',
        'password' => '',
        'schema' => 'dbo',
        'encrypt' => true,
        'trust_server_certificate' => false,
        'login_timeout' => 15,
    ],
];
