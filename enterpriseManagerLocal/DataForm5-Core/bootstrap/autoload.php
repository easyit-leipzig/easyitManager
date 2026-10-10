<?php
declare(strict_types=1);

$namespaceMap = require __DIR__ . '/namespaces.php';

spl_autoload_register(static function (string $class) use ($namespaceMap): void {
    $prefix = 'DataForm5\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    foreach ($namespaceMap as $namespace => $directory) {
        if (!str_starts_with($relative, $namespace)) {
            continue;
        }

        $file = $directory
            . str_replace('\\', '/', substr($relative, strlen($namespace)))
            . '.php';

        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

/*
 * Legacy compatibility bridge:
 * the historical DataForm\Database namespace still has its own small loader.
 * It remains until the database namespace migration is handled in a dedicated
 * RC1.8 consolidation phase.
 */
require_once __DIR__ . '/../system/database/autoload.php';
