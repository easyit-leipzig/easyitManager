<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/system/app/bootstrap.php';
$user=enterprise_require_auth('../../');
enterprise_require_capability($user,'cluster.view');

header('Content-Type: application/json; charset=utf-8');
echo json_encode(enterprise_cluster()->health(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
