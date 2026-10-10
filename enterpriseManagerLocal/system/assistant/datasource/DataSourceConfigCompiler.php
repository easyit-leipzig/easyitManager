<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

final class DataSourceConfigCompiler
{
    public function compile(DataSourceDraft $draft): array
    {
        $d = $draft->toArray();
        $driver = strtolower((string) $d['profile']['driver']);
        $c = $d['connection'];

        $connection = match ($driver) {
            'pgsql' => [
                'host' => $c['host'],
                'port' => (int) $c['port'],
                'database' => $c['database'],
                'schema' => trim((string)($c['schema'] ?? 'public')) ?: 'public',
                'username' => $c['username'],
                'passwordRef' => $c['passwordRef'],
                'charset' => $c['charset'] ?: 'UTF8',
            ],
            'mysql', 'mssql' => [
                'host' => $c['host'],
                'port' => (int) $c['port'],
                'database' => $c['database'],
                'username' => $c['username'],
                'passwordRef' => $c['passwordRef'],
                'charset' => $c['charset'] ?: ($driver === 'mssql' ? 'UTF-8' : 'utf8mb4'),
                'encrypt' => $driver === 'mssql' ? (bool)($c['encrypt'] ?? true) : null,
                'trustServerCertificate' => $driver === 'mssql' ? (bool)($c['trustServerCertificate'] ?? false) : null,
            ],
            'oracle' => [
                'host' => $c['host'],
                'port' => (int) $c['port'],
                'service' => $c['service'],
                'username' => $c['username'],
                'passwordRef' => $c['passwordRef'],
                'charset' => $c['charset'] ?: 'AL32UTF8',
            ],
            'sqlite' => ['path' => $c['path']],
            'csv' => [
                'path' => $c['path'],
                'delimiter' => $c['delimiter'] ?: '|',
                'header' => true,
                'idField' => 'id',
            ],
            default => [],
        };

        return [
            'schema' => 'easyit.datasource.assistant.v1',
            'profile' => (string) $d['profile']['name'],
            'driver' => $driver,
            'connection' => $connection,
            'source' => [
                'name' => (string) $d['selection']['sourceName'],
                'type' => (string) $d['selection']['sourceType'],
            ],
            'secretPolicy' => [
                'plaintextPasswordStored' => false,
                'passwordReferenceField' => in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true) ? 'connection.passwordRef' : null,
            ],
            'coreContract' => [
                'purpose' => 'DataForm5/Enterprise database abstraction',
                'interface' => 'EasyIT\\Assistant\\DataSource\\DataFormDatabaseInterface',
                'bridge' => 'EasyIT\\Assistant\\DataSource\\CoreDatabaseBridge',
                'operations' => ['connect', 'tables', 'query', 'insert', 'update', 'delete'],
            ],
        ];
    }
}
