<?php
declare(strict_types=1);

use DataForm5\Core\Configuration\Env;

return [
    'minimum_php' => Env::get('INSTALLER_MINIMUM_PHP', '8.2.0'),
    'lock_file' => dirname(__DIR__).'/storage/framework/installer/installed.json',
];
