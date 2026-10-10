<?php
declare(strict_types=1);
use DataForm5\Core\Configuration\Env;
return [
    'driver'=>Env::get('OBSERVABILITY_DRIVER','file'),
    'file'=>Env::get('OBSERVABILITY_FILE','storage/observability/metrics.json'),
    'health_check'=>Env::get('OBSERVABILITY_HEALTH_CHECK',true),
    'default_tags'=>[
        'application'=>Env::get('APP_NAME','DataForm5-Core'),
        'environment'=>Env::get('APP_ENV','development'),
    ],
];
