<?php
declare(strict_types=1);
require __DIR__.'/_client.php';

$result=calendar_api('PUT','events/17',json_decode('{"start": "2026-10-05T15:00:00", "end": "2026-10-05T16:00:00", "title": "Besprechung verschoben", "description": "Projektstatus", "location_id": 3}', true, 512, JSON_THROW_ON_ERROR));
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
