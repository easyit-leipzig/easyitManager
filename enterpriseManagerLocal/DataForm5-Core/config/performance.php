<?php
declare(strict_types=1);
return [
    'enabled' => filter_var($_ENV['PERFORMANCE_ENABLED'] ?? true, FILTER_VALIDATE_BOOL),
    'max_entries' => (int)($_ENV['PERFORMANCE_MAX_ENTRIES'] ?? 1000),
    'report_path' => dirname(__DIR__).'/storage/performance/latest.json',
    'thresholds' => [
        '*' => 1000,
        'http.request' => 500,
        'database.query' => 100,
        'view.render' => 100,
        'project.export' => 5000,
    ],
];
