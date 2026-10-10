<?php
declare(strict_types=1);
$configFile=dirname(__DIR__).'/config/app.php';
if(!is_file($configFile)){http_response_code(503);echo '<!doctype html><meta charset="utf-8"><h1>Lizenzserver nicht konfiguriert</h1>';exit;}
$config=require dirname(__DIR__).'/bootstrap.php';
if(!easyit_license_database_configured($config)){header('Location: ../setup/');exit;}
\EasyIT\LicenseServer\Customer\CustomerPortalApp::run($config);
