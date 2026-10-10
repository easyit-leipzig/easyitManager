<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/system/app/bootstrap.php';
$user=enterprise_require_auth('../../'); enterprise_require_capability($user,'monitoring.view');
header('Content-Type: application/json; charset=utf-8');
try{echo json_encode(enterprise_monitoring()->snapshot(true),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $e){http_response_code(500);echo json_encode(['status'=>'error','message'=>'Monitoring konnte nicht gelesen werden.']);}
