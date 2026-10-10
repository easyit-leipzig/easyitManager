<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/bootstrap.php';
if(!easyit_license_database_configured($config)){http_response_code(503);header('Content-Type: application/json; charset=utf-8');echo json_encode(['success'=>false,'error'=>['code'=>'SERVER_NOT_CONFIGURED','message'=>'Der Lizenzserver ist noch nicht eingerichtet.']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
\EasyIT\LicenseServer\App\Application::run($config);
