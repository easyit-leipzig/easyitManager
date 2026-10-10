<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'name' => Env::get('APP_NAME', 'DataForm5-Core'),
    'environment' => Env::get('APP_ENV', 'development'),
    'debug' => Env::get('APP_DEBUG', true),
    'timezone' => Env::get('APP_TIMEZONE', 'Europe/Berlin'),
    'locale' => Env::get('APP_LOCALE', 'de_DE'),
    'url' => Env::get('APP_URL', 'http://localhost'),
];
