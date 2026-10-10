<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';

$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'developer.view');
if(!enterprise_developer_enabled($user)){http_response_code(404);exit;}

header('Content-Type: application/json; charset=utf-8');
$state=enterprise_developer_inspector()->snapshot();
echo json_encode([
    'runtime_ms'=>$state['runtime']['runtime_ms'],
    'memory_peak_bytes'=>$state['runtime']['memory_peak_bytes'],
    'modules'=>count($state['modules']),
    'services_resolved'=>$state['container']['resolved'],
    'services_total'=>$state['container']['total'],
    'queue'=>$state['queue']['stats'],
    'scheduler_tasks'=>count($state['scheduler']['tasks']),
],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
