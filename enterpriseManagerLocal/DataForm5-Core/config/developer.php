<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'enabled' => filter_var(Env::get('DEVELOPER_MODE', 'false'), FILTER_VALIDATE_BOOL),
    'overlay' => filter_var(Env::get('DEVELOPER_OVERLAY', 'true'), FILTER_VALIDATE_BOOL),
    'allowed_roles' => array_values(array_filter(array_map('trim', explode(',', Env::get('DEVELOPER_ALLOWED_ROLES', 'admin'))))),
    'max_events' => (int)Env::get('DEVELOPER_MAX_EVENTS', '100'),
    'profiler_max_records' => (int)Env::get('DEVELOPER_PROFILER_MAX_RECORDS', '500'),
];
