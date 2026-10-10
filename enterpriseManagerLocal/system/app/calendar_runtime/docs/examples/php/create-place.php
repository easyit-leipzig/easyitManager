<?php
declare(strict_types=1);
require __DIR__.'/_client.php';

$result=calendar_api('POST','places',json_decode('{"name": "Raum 101", "description": "Besprechungsraum", "street": "Musterstraße 1", "postal_code": "04109", "city": "Leipzig", "country": "DE", "room": "101", "activ": 1}', true, 512, JSON_THROW_ON_ERROR));
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
