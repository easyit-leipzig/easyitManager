<?php
declare(strict_types=1);
use DataForm5\Core\Configuration\Env;
return [
    'driver' => Env::get('SECRETS_DRIVER', 'file'),
    'path' => Env::get('SECRETS_PATH', 'storage/framework/secrets/secrets.json'),
    'active_key' => Env::get('SECRETS_ACTIVE_KEY', 'main'),
    'keys' => [
        'main' => Env::get('SECRETS_KEY', 'change-this-development-key-before-production-0001'),
        // Alte Schlüssel während einer Rotation vorübergehend ergänzen.
    ],
];
