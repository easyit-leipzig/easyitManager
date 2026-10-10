<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;
use EasyIT\Assistant\DataSource\DataSourceAdapterInterface;

final class SQLiteDataSourceAdapter extends AbstractPdoAdapter implements DataSourceAdapterInterface
{
    public function __construct(private ?string $projectRoot = null) {}

    public function getDriver(): string { return 'sqlite'; }
    public function getLabel(): string { return 'SQLite'; }

    public function test(array $runtimeConfig): ConnectionTestResult
    {
        if (!$this->pdoDriverAvailable('sqlite')) {
            return $this->unavailable('sqlite', 'SQLite/PDO');
        }
        try {
            $path = $this->resolvePath((string) ($runtimeConfig['path'] ?? ''));
            if ($path !== ':memory:' && !is_file($path)) {
                return ConnectionTestResult::failure('SQLite-Datei wurde nicht gefunden.', ['resolvedPath' => $path]);
            }
            $pdo = $this->connect(['path' => $path]);
            $version = (string) $pdo->query('SELECT sqlite_version()')->fetchColumn();
            return ConnectionTestResult::success('SQLite-Verbindung erfolgreich.', ['resolvedPath' => $path, 'sqliteVersion' => $version]);
        } catch (\Throwable $e) {
            return ConnectionTestResult::failure('SQLite-Verbindung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function discover(array $runtimeConfig): array
    {
        $path = $this->resolvePath((string) ($runtimeConfig['path'] ?? ''));
        $pdo = $this->connect(['path' => $path]);
        $stmt = $pdo->query("SELECT name, type FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $type = (string) $row['type'];
            $name = (string) $row['name'];
            $out[] = ['name' => $name, 'type' => $type, 'label' => $name . ' (' . ($type === 'view' ? 'View' : 'Tabelle') . ')'];
        }
        return $out;
    }

    private function connect(array $c): \PDO
    {
        if (!$this->pdoDriverAvailable('sqlite')) {
            throw new \RuntimeException('PDO-SQLite ist nicht verfügbar.');
        }
        return new \PDO('sqlite:' . (string) ($c['path'] ?? ''), null, null, $this->options());
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === ':memory:' || $path === '') {
            return $path;
        }
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1) {
            return $path;
        }
        $base = $this->projectRoot ?: dirname(__DIR__, 4);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
