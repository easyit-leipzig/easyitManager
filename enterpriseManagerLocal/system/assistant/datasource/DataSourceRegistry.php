<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

final class DataSourceRegistry
{
    /** @var array<string, DataSourceAdapterInterface> */
    private array $adapters = [];

    /** @param iterable<DataSourceAdapterInterface> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->register($adapter);
        }
    }

    public function register(DataSourceAdapterInterface $adapter): void
    {
        $driver = strtolower(trim($adapter->getDriver()));
        if ($driver === '') {
            throw new \InvalidArgumentException('Data source driver must not be empty.');
        }
        $this->adapters[$driver] = $adapter;
    }

    public function has(string $driver): bool
    {
        return isset($this->adapters[strtolower($driver)]);
    }

    public function get(string $driver): DataSourceAdapterInterface
    {
        $driver = strtolower($driver);
        if (!$this->has($driver)) {
            throw new \OutOfBoundsException('Unbekannter Datenquellentyp: ' . $driver);
        }
        return $this->adapters[$driver];
    }

    /** @return array<string,string> */
    public function options(): array
    {
        $result = [];
        foreach ($this->adapters as $driver => $adapter) {
            if (!$this->runtimeAvailable($driver)) {
                continue;
            }
            $result[$driver] = $adapter->getLabel();
        }
        return $result;
    }

    private function runtimeAvailable(string $driver): bool
    {
        $driver = strtolower(trim($driver));
        if ($driver === 'csv') {
            return true;
        }
        $pdoDriver = match ($driver) {
            'mysql' => 'mysql',
            'pgsql' => 'pgsql',
            'sqlite' => 'sqlite',
            'oracle' => 'oci',
            'mssql' => 'sqlsrv',
            default => null,
        };
        if ($pdoDriver === null || !class_exists(\PDO::class)) {
            return false;
        }
        return in_array($pdoDriver, \PDO::getAvailableDrivers(), true);
    }
}
