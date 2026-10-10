<?php
declare(strict_types=1);
require __DIR__.'/_client.php';

$result=calendar_api('PUT','block-categories/2',json_decode('{"name": "Intern geändert", "description": "Interne Sperrzeit", "activ": 1}', true, 512, JSON_THROW_ON_ERROR));
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
