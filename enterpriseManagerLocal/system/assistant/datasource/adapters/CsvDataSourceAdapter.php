<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource\Adapters;

use EasyIT\Assistant\DataSource\ConnectionTestResult;
use EasyIT\Assistant\DataSource\DataSourceAdapterInterface;

final class CsvDataSourceAdapter implements DataSourceAdapterInterface
{
    public function __construct(private ?string $projectRoot = null) {}

    public function getDriver(): string { return 'csv'; }
    public function getLabel(): string { return 'CSV-Engine'; }

    public function test(array $runtimeConfig): ConnectionTestResult
    {
        $path = $this->resolvePath((string) ($runtimeConfig['path'] ?? ''));
        if (!is_dir($path)) {
            return ConnectionTestResult::failure('CSV-Datenbankordner wurde nicht gefunden.', ['resolvedPath' => $path]);
        }
        if (!is_readable($path)) {
            return ConnectionTestResult::failure('CSV-Datenbankordner ist nicht lesbar.', ['resolvedPath' => $path]);
        }
        $sources = $this->discover($runtimeConfig);
        $invalid = array_values(array_filter($sources, static fn(array $source): bool => ($source['meta']['valid'] ?? true) === false));
        $warnings = [];
        if ($invalid !== []) {
            $warnings[] = count($invalid) . ' CSV-Tabelle(n) besitzen kein gültiges Pflichtfeld "id".';
        }
        return ConnectionTestResult::success('CSV-Datenbankordner ist zugreifbar.', [
            'resolvedPath' => $path,
            'tablesFound' => count($sources),
            'validTables' => count($sources) - count($invalid),
        ], $warnings);
    }

    public function discover(array $runtimeConfig): array
    {
        $path = $this->resolvePath((string) ($runtimeConfig['path'] ?? ''));
        $delimiter = (string) ($runtimeConfig['delimiter'] ?? '|');
        if (!is_dir($path)) {
            return [];
        }
        $files = glob(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '*.csv') ?: [];
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $out = [];
        foreach ($files as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $header = $this->header($file, $delimiter);
            $valid = in_array('id', $header, true);
            $out[] = [
                'name' => $name,
                'type' => 'table',
                'label' => $name . ($valid ? ' (CSV)' : ' (CSV – id fehlt)'),
                'meta' => [
                    'file' => basename($file),
                    'columns' => $header,
                    'valid' => $valid,
                    'requiredIdField' => 'id',
                ],
            ];
        }
        return $out;
    }

    private function header(string $file, string $delimiter): array
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) { return []; }
        try {
            $row = fgetcsv($handle, 0, $delimiter);
            if (!is_array($row)) { return []; }
            if (isset($row[0])) { $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) ?? (string) $row[0]; }
            return array_values(array_map(static fn($v): string => trim((string) $v), $row));
        } finally {
            fclose($handle);
        }
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if (preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path) === 1) {
            return $path;
        }
        $base = $this->projectRoot ?: dirname(__DIR__, 4);
        return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
