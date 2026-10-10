<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'session' => [
        'driver' => Env::get('SESSION_DRIVER', 'native'),
        'name' => Env::get('SESSION_NAME', 'DATAFORM5SESSID'),
    ],
    'password' => [
        'bcrypt_cost' => (int)Env::get('BCRYPT_COST', 12),
    ],
    'csrf' => ['enabled' => true],
];
