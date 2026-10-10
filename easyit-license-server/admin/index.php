<?php
declare(strict_types=1);

$configFile=dirname(__DIR__).'/config/app.php';
if(!is_file($configFile)){
    http_response_code(503);header('Content-Type:text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Lizenzverwaltung</title><h1>Lizenzserver nicht konfiguriert</h1><p>Bitte zuerst <code>config/app.php</code> einrichten.</p>';exit;
}
$config=require dirname(__DIR__).'/bootstrap.php';
if(!easyit_license_database_configured($config)){header('Location: ../setup/');exit;}
\EasyIT\LicenseServer\Admin\AdminApp::run($config);
