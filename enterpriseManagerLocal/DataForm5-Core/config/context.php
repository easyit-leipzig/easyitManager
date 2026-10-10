<?php
declare(strict_types=1);
return [
    'auto_activate' => filter_var($_ENV['CONTEXT_AUTO_ACTIVATE'] ?? false, FILTER_VALIDATE_BOOL),
    'default' => [
        'tenant_id' => $_ENV['CONTEXT_TENANT_ID'] ?? '',
        'project_id' => $_ENV['CONTEXT_PROJECT_ID'] ?? '',
        'project_name' => $_ENV['CONTEXT_PROJECT_NAME'] ?? '',
    ],
];
