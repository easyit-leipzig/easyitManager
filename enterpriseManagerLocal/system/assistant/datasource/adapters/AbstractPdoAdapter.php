<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;

abstract class AbstractPdoAdapter
{
    protected function pdoDriverAvailable(string $driver): bool
    {
        return class_exists(\PDO::class) && in_array($driver, \PDO::getAvailableDrivers(), true);
    }

    protected function unavailable(string $driver, string $label): ConnectionTestResult
    {
        return ConnectionTestResult::failure(
            $label . '-Treiber ist in dieser PHP-Laufzeit nicht verfügbar.',
            ['pdoDriver' => $driver, 'availableDrivers' => class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : []]
        );
    }

    protected function options(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => 5,
        ];
    }
}
