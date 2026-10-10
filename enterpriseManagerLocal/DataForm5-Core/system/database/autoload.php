<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'DataForm\\Database\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $map = [
        'Contracts\\' => '01_interfaces/',
        'Pdo\\' => '03_adapters/shared/',
        'Csv\\' => '03_adapters/csv/',
        'MySql\\' => '03_adapters/mysql/',
        'Sqlite\\' => '03_adapters/sqlite/',
        'Oracle\\' => '03_adapters/oracle/',
        'SqlServer\\' => '03_adapters/sqlserver/',
        'Core\\' => '02_core/',
    ];

    foreach ($map as $namespace => $directory) {
        if (str_starts_with($relative, $namespace)) {
            $relative = substr($relative, strlen($namespace));
            $file = __DIR__ . '/' . $directory . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
            return;
        }
    }

    $file = __DIR__ . '/02_core/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once __DIR__ . '/03_adapters/postgresql/PostgreSqlAdapter.php';
require_once __DIR__ . '/03_adapters/sqlserver/SqlServerAdapter.php';
