<?php
declare(strict_types=1);

namespace DataForm\Database\Core;

use DataForm\Database\Contracts\DatabaseInterface;
use DataForm\Database\DatabaseFactory;

final class DatabaseManager
{
    /** @var array<string, DatabaseInterface> */
    private array $connections = [];

    /** @param array<string, array<string, mixed>> $configurations */
    public function __construct(
        private readonly array $configurations,
        private readonly string $defaultConnection = 'default'
    ) {
    }

    public function connection(?string $name = null): DatabaseInterface
    {
        $name ??= $this->defaultConnection;

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $configuration = $this->configurations[$name] ?? null;
        if (!is_array($configuration)) {
            throw new \InvalidArgumentException("Unbekannte Datenbankverbindung: {$name}");
        }

        return $this->connections[$name] = DatabaseFactory::create($configuration);
    }

    public function defaultConnectionName(): string
    {
        return $this->defaultConnection;
    }

    /** @return list<string> */
    public function configuredConnections(): array
    {
        return array_values(array_keys($this->configurations));
    }

    public function has(string $name): bool
    {
        return isset($this->configurations[$name]);
    }

    public function purge(string $name): void
    {
        if (!isset($this->connections[$name])) {
            return;
        }

        $this->connections[$name]->disconnect();
        unset($this->connections[$name]);
    }

    public function reconnect(?string $name = null): DatabaseInterface
    {
        $name ??= $this->defaultConnection;
        $this->purge($name);
        return $this->connection($name);
    }

    public function disconnectAll(): void
    {
        foreach ($this->connections as $connection) {
            $connection->disconnect();
        }
        $this->connections = [];
    }
}
