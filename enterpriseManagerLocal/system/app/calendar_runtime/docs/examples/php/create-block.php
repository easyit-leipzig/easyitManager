<?php
declare(strict_types=1);
require __DIR__.'/_client.php';

$result=calendar_api('POST','blocks',json_decode('{"start": "2026-10-06T08:00:00", "end": "2026-10-06T10:00:00", "category_id": 2, "person_id": 0, "reason": "Nicht verfügbar"}', true, 512, JSON_THROW_ON_ERROR));
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
