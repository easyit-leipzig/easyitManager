<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;
use EasyIT\Assistant\DataSource\DataSourceAdapterInterface;

final class MySqlDataSourceAdapter extends AbstractPdoAdapter implements DataSourceAdapterInterface
{
    public function getDriver(): string { return 'mysql'; }
    public function getLabel(): string { return 'MySQL / MariaDB'; }

    public function test(array $runtimeConfig): ConnectionTestResult
    {
        if (!$this->pdoDriverAvailable('mysql')) {
            return $this->unavailable('mysql', 'MySQL/PDO');
        }
        try {
            $pdo = $this->connect($runtimeConfig);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            return ConnectionTestResult::success('MySQL/MariaDB-Verbindung erfolgreich.', ['serverVersion' => $version]);
        } catch (\Throwable $e) {
            return ConnectionTestResult::failure('MySQL/MariaDB-Verbindung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function discover(array $runtimeConfig): array
    {
        $pdo = $this->connect($runtimeConfig);
        $stmt = $pdo->query("SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME");
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $type = strtoupper((string) ($row['TABLE_TYPE'] ?? '')) === 'VIEW' ? 'view' : 'table';
            $name = (string) $row['TABLE_NAME'];
            $out[] = ['name' => $name, 'type' => $type, 'label' => $name . ' (' . ($type === 'view' ? 'View' : 'Tabelle') . ')'];
        }
        return $out;
    }

    private function connect(array $c): \PDO
    {
        if (!$this->pdoDriverAvailable('mysql')) {
            throw new \RuntimeException('PDO-MySQL ist nicht verfügbar.');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) ($c['host'] ?? '127.0.0.1'),
            (int) ($c['port'] ?? 3306),
            (string) ($c['database'] ?? ''),
            (string) ($c['charset'] ?? 'utf8mb4')
        );
        return new \PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), $this->options());
    }
}
