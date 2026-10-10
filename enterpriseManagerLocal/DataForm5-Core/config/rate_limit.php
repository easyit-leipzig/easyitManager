<?php
declare(strict_types=1);
return [
    'driver' => $_ENV['RATE_LIMIT_DRIVER'] ?? 'file',
    'path' => $_ENV['RATE_LIMIT_PATH'] ?? 'storage/framework/rate-limit',
    'prefix' => $_ENV['RATE_LIMIT_PREFIX'] ?? 'df5',
    'defaults' => [
        'api' => ['limit'=>60,'window_seconds'=>60],
        'login' => ['limit'=>5,'window_seconds'=>300],
        'project_export' => ['limit'=>20,'window_seconds'=>3600],
    ],
];
