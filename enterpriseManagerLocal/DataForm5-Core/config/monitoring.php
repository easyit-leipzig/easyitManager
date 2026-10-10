<?php
declare(strict_types=1);
return [
    'snapshot_path'=>'storage/framework/monitoring',
    'log_path'=>'storage/logs/monitoring',
    'thresholds'=>[
        'queue_pending_warning'=>100,
        'queue_failed_warning'=>1,
        'disk_free_percent_warning'=>10,
        'memory_percent_warning'=>90,
        'worker_stale_seconds'=>180,
        'scheduler_stale_seconds'=>180,
    ],
];
