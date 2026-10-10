<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/system/app/EnterpriseMssqlAdapter.php';

function b(string $name, bool $default): bool {
    $v = getenv($name);
    if ($v === false || $v === '') return $default;
    return in_array(strtolower(trim($v)), ['1','true','yes','on','ja'], true);
}

$config = [
    'host' => getenv('EASYIT_MSSQL_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('EASYIT_MSSQL_PORT') ?: 1433),
    'instance' => getenv('EASYIT_MSSQL_INSTANCE') ?: '',
    'database' => getenv('EASYIT_MSSQL_DATABASE') ?: '',
    'username' => getenv('EASYIT_MSSQL_USER') ?: '',
    'password' => getenv('EASYIT_MSSQL_PASSWORD') ?: '',
    'encrypt' => b('EASYIT_MSSQL_ENCRYPT', true),
    'trust_server_certificate' => b('EASYIT_MSSQL_TRUST_SERVER_CERTIFICATE', false),
];

echo 'PDO-Treiber: ' . implode(', ', PDO::getAvailableDrivers()) . PHP_EOL;
echo 'DSN (ohne Zugangsdaten): ' . EnterpriseMssqlAdapter::buildDsn($config) . PHP_EOL;

try {
    $pdo = EnterpriseMssqlAdapter::connect($config);
    echo 'PASS - Verbindung hergestellt' . PHP_EOL;
    echo 'Datenbank: ' . EnterpriseMssqlAdapter::databaseName($pdo) . PHP_EOL;
    echo 'SQL Server: ' . EnterpriseMssqlAdapter::serverVersion($pdo) . PHP_EOL;
    echo 'SELECT 1: ' . $pdo->query('SELECT 1')->fetchColumn() . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL - ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
