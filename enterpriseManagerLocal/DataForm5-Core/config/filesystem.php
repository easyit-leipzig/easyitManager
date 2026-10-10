<?php
declare(strict_types=1);

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => 'storage/app',
        ],
    ],
    'temporary_path' => 'storage/framework/tmp',
    'backup_path' => 'storage/backups',
    'atomic_writes' => true,
];
