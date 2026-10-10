<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;
use EasyIT\Assistant\DataSource\DataSourceAdapterInterface;

final class OracleDataSourceAdapter extends AbstractPdoAdapter implements DataSourceAdapterInterface
{
    public function getDriver(): string { return 'oracle'; }
    public function getLabel(): string { return 'Oracle'; }

    public function test(array $runtimeConfig): ConnectionTestResult
    {
        if (!$this->pdoDriverAvailable('oci')) {
            return $this->unavailable('oci', 'Oracle/PDO_OCI');
        }
        try {
            $pdo = $this->connect($runtimeConfig);
            $version = (string) $pdo->query("SELECT banner FROM v\$version WHERE ROWNUM = 1")->fetchColumn();
            return ConnectionTestResult::success('Oracle-Verbindung erfolgreich.', ['serverVersion' => $version]);
        } catch (\Throwable $e) {
            return ConnectionTestResult::failure('Oracle-Verbindung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function discover(array $runtimeConfig): array
    {
        $pdo = $this->connect($runtimeConfig);
        $sql = "SELECT object_name, object_type FROM user_objects WHERE object_type IN ('TABLE','VIEW') ORDER BY object_name";
        $out = [];
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $type = strtoupper((string) ($row['OBJECT_TYPE'] ?? $row['object_type'] ?? 'TABLE')) === 'VIEW' ? 'view' : 'table';
            $name = (string) ($row['OBJECT_NAME'] ?? $row['object_name'] ?? '');
            $out[] = ['name' => $name, 'type' => $type, 'label' => $name . ' (' . ($type === 'view' ? 'View' : 'Tabelle') . ')'];
        }
        return $out;
    }

    private function connect(array $c): \PDO
    {
        if (!$this->pdoDriverAvailable('oci')) {
            throw new \RuntimeException('PDO_OCI ist nicht verfügbar.');
        }
        $dsn = sprintf(
            'oci:dbname=//%s:%d/%s;charset=%s',
            (string) ($c['host'] ?? '127.0.0.1'),
            (int) ($c['port'] ?? 1521),
            (string) ($c['service'] ?? ''),
            (string) ($c['charset'] ?? 'AL32UTF8')
        );
        $options = $this->options();
        unset($options[\PDO::ATTR_TIMEOUT]);
        return new \PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), $options);
    }
}
