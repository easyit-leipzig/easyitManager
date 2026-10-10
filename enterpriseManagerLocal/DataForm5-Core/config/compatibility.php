<?php
declare(strict_types=1);
return [
    'current' => '1.0.0',
    'minimum' => '0.1.0',
    'upgrade_steps' => [
        ['version'=>'0.41.0-dev','name'=>'installer-lock-check','description'=>'Installations-Lock und Storage prüfen.'],
        ['version'=>'0.42.0-dev','name'=>'production-gate-check','description'=>'Produktionskonfiguration und Sicherheitsheader prüfen.'],
        ['version'=>'0.43.0-dev','name'=>'compatibility-baseline','description'=>'Kompatibilitätsstatus und Deprecations registrieren.'],
    ],
    'deprecations' => [
        'legacy.direct_database_factory' => ['since'=>'0.43.0-dev','replacement'=>'DatabaseManager::connection()','remove_in'=>'1.0.0'],
    ],
];
