<?php
declare(strict_types=1);
namespace DataForm5\Installer\Core;

final class SystemInspector
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $minimumPhp = '8.2.0'
    ) {}

    public function inspect(): array
    {
        $checks = [];
        $checks[] = new RequirementResult(
            'php.version',
            version_compare(PHP_VERSION, $this->minimumPhp, '>='),
            true,
            'PHP ' . PHP_VERSION . '; mindestens ' . $this->minimumPhp
        );

        foreach (['json', 'openssl'] as $extension) {
            $checks[] = new RequirementResult(
                'extension.' . $extension,
                extension_loaded($extension),
                true,
                'PHP-Erweiterung ' . $extension
            );
        }

        foreach (['pdo_mysql', 'pdo_sqlite', 'pdo_pgsql', 'pdo_oci', 'pdo_sqlsrv'] as $extension) {
            $checks[] = new RequirementResult(
                'extension.' . $extension,
                extension_loaded($extension),
                false,
                'Datenbanktreiber ' . $extension . ' – erforderlich, wenn dieser Speicher im Setup gewählt wird'
            );
        }

        $checks[] = new RequirementResult(
            'extension.mbstring',
            extension_loaded('mbstring'),
            false,
            'Empfohlen für Unicode-Textfunktionen'
        );
        $checks[] = new RequirementResult(
            'extension.zip',
            extension_loaded('zip'),
            false,
            'Optional für ZIP-Pakete und Backups'
        );

        foreach (['storage', 'storage/framework', 'storage/logs'] as $path) {
            $absolute = $this->basePath . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $path);

            [$passed, $detail] = $this->directoryWriteProbe($absolute);
            $checks[] = new RequirementResult(
                'writable.' . $path,
                $passed,
                true,
                $detail
            );
        }

        $failedRequired = array_values(array_filter(
            $checks,
            static fn (RequirementResult $result): bool => $result->required && !$result->passed
        ));

        return [
            'ready' => $failedRequired === [],
            'php' => PHP_VERSION,
            'checks' => array_map(
                static fn (RequirementResult $result): array => $result->toArray(),
                $checks
            ),
            'failed_required' => count($failedRequired),
        ];
    }

    /**
     * Prüft die tatsächlich nutzbaren Schreibrechte des laufenden PHP-Prozesses.
     *
     * Unter Windows/XAMPP kann is_writable() nach ACL-Aenderungen oder bei
     * bestimmten Verzeichnisattributen ein irrefuehrendes Ergebnis liefern.
     * Für DataForm5 ist entscheidend, ob PHP im Zielverzeichnis wirklich ein
     * Unterverzeichnis und eine Datei anlegen, lesen und wieder entfernen kann.
     *
     * @return array{0:bool,1:string}
     */
    private function directoryWriteProbe(string $path): array
    {
        clearstatcache(true, $path);

        if (!is_dir($path)) {
            error_clear_last();
            if (!@mkdir($path, 0775, true) && !is_dir($path)) {
                $error = error_get_last();
                $detail = 'Verzeichnis konnte nicht angelegt werden: ' . $path;
                if (is_array($error) && !empty($error['message'])) {
                    $detail .= ' (' . $error['message'] . ')';
                }
                return [false, $detail];
            }
        }

        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }

        $probeDir = $path . DIRECTORY_SEPARATOR . '.easyit-write-probe-' . $suffix;
        $probeFile = $probeDir . DIRECTORY_SEPARATOR . 'probe.tmp';

        error_clear_last();
        if (!@mkdir($probeDir, 0700)) {
            $error = error_get_last();
            $detail = 'PHP konnte im Verzeichnis kein Test-Unterverzeichnis anlegen: ' . $path;
            if (is_array($error) && !empty($error['message'])) {
                $detail .= ' (' . $error['message'] . ')';
            }
            return [false, $detail];
        }

        $payload = 'easyIT write probe ' . gmdate('c');
        error_clear_last();
        $written = @file_put_contents($probeFile, $payload, LOCK_EX);
        if ($written === false) {
            $error = error_get_last();
            @rmdir($probeDir);
            $detail = 'Test-Unterverzeichnis anlegbar, Testdatei aber nicht schreibbar: ' . $path;
            if (is_array($error) && !empty($error['message'])) {
                $detail .= ' (' . $error['message'] . ')';
            }
            return [false, $detail];
        }

        $readBack = @file_get_contents($probeFile);
        if ($readBack !== $payload) {
            @unlink($probeFile);
            @rmdir($probeDir);
            return [false, 'Testdatei wurde geschrieben, konnte aber nicht korrekt gelesen werden: ' . $path];
        }

        if (!@unlink($probeFile)) {
            $error = error_get_last();
            @rmdir($probeDir);
            $detail = 'Testdatei wurde geschrieben, konnte aber nicht gelöscht werden: ' . $path;
            if (is_array($error) && !empty($error['message'])) {
                $detail .= ' (' . $error['message'] . ')';
            }
            return [false, $detail];
        }

        if (!@rmdir($probeDir)) {
            $error = error_get_last();
            $detail = 'Schreibtest erfolgreich, Test-Unterverzeichnis konnte aber nicht gelöscht werden: ' . $path;
            if (is_array($error) && !empty($error['message'])) {
                $detail .= ' (' . $error['message'] . ')';
            }
            return [false, $detail];
        }

        clearstatcache(true, $path);
        return [true, $path . ' – Schreib-/Löschtest erfolgreich'];
    }
}
