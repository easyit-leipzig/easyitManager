<?php
declare(strict_types=1);
return [
    'driver' => $_ENV['AUDIT_DRIVER'] ?? 'file',
    'file' => $_ENV['AUDIT_FILE'] ?? 'storage/logs/audit.jsonl',
    'key' => $_ENV['AUDIT_KEY'] ?? '',
];
