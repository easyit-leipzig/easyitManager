<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Recovery;

final class ProjectRecoveryDiagnosticService
{
    public function __construct(private string $enterpriseRoot)
    {
        $this->enterpriseRoot = rtrim($enterpriseRoot, DIRECTORY_SEPARATOR);
    }

    public function diagnose(string $projectId, array $manifest = []): RecoveryReport
    {
        $report = new RecoveryReport($projectId);
        $project = $this->projectPath($projectId);
        if (!is_dir($project)) {
            $report->add(new RecoveryCheck('REC-001', 'Projektverzeichnis', RecoveryCheck::FAIL, 'Wiederhergestelltes Projektverzeichnis fehlt.'));
            return $report;
        }
        $report->add(new RecoveryCheck('REC-001', 'Projektverzeichnis', RecoveryCheck::PASS, 'Projektverzeichnis ist vorhanden.', ['path' => $project]));

        $requiredDirs = ['config', 'data', 'storage', 'logs', 'backups'];
        $missingDirs = array_values(array_filter($requiredDirs, static fn (string $dir): bool => !is_dir($project . DIRECTORY_SEPARATOR . $dir)));
        $report->add(new RecoveryCheck(
            'REC-002',
            'Standardverzeichnisse',
            $missingDirs === [] ? RecoveryCheck::PASS : RecoveryCheck::WARN,
            $missingDirs === [] ? 'Alle Standardverzeichnisse sind vorhanden.' : 'Einige Standardverzeichnisse fehlen.',
            ['missing' => $missingDirs],
            $missingDirs === [] ? null : 'Fehlende Laufzeitverzeichnisse beim nächsten Projektstart anlegen.'
        ));

        $projectConfig = $this->jsonFile($project . '/config/project.json');
        if ($projectConfig === null) {
            $report->add(new RecoveryCheck('REC-003', 'Projektkonfiguration', RecoveryCheck::FAIL, 'config/project.json fehlt oder ist ungültig.'));
        } else {
            $id = (string) ($projectConfig['project']['id'] ?? '');
            $report->add(new RecoveryCheck(
                'REC-003', 'Projektkonfiguration', $id === $projectId ? RecoveryCheck::PASS : RecoveryCheck::FAIL,
                $id === $projectId ? 'Projekt-ID der Konfiguration stimmt mit dem Restore-Ziel überein.' : 'Projekt-ID in project.json stimmt nicht mit dem Restore-Ziel überein.',
                ['configuredId' => $id, 'expectedId' => $projectId]
            ));
        }

        $datasource = $this->jsonFile($project . '/config/datasource.json');
        if ($datasource === null) {
            $report->add(new RecoveryCheck('REC-004', 'Datenquellenprofil', RecoveryCheck::FAIL, 'config/datasource.json fehlt oder ist ungültig.'));
            return $report;
        }
        $report->add(new RecoveryCheck('REC-004', 'Datenquellenprofil', RecoveryCheck::PASS, 'Datenquellenprofil ist lesbar.'));

        $driver = strtolower((string) ($datasource['profile']['driver'] ?? ''));
        $dbExpected = (bool) ($manifest['database']['requested'] ?? false);
        $dbIncluded = (bool) ($manifest['database']['included'] ?? false);
        if (in_array($driver, ['sqlite', 'csv'], true)) {
            $path = (string) ($datasource['connection']['path'] ?? '');
            $absolute = $this->absoluteEnterprisePath($path);
            $exists = $driver === 'sqlite' ? is_file($absolute) : is_dir($absolute);
            if ($dbExpected && $dbIncluded) {
                $report->add(new RecoveryCheck('REC-005', 'Lokale Projektdatenbank', $exists ? RecoveryCheck::PASS : RecoveryCheck::FAIL, $exists ? 'Lokale Projektdatenbank ist vorhanden.' : 'Lokale Projektdatenbank sollte enthalten sein, fehlt aber.', ['driver' => $driver, 'path' => $path]));
            } else {
                $report->add(new RecoveryCheck('REC-005', 'Lokale Projektdatenbank', $exists ? RecoveryCheck::PASS : RecoveryCheck::WARN, $exists ? 'Lokale Projektdatenbank ist vorhanden.' : 'Backup wurde ohne lokale Projektdatenbank wiederhergestellt.', ['driver' => $driver, 'path' => $path], $exists ? null : 'Datenbank separat wiederherstellen oder neu initialisieren.'));
            }
        } elseif (in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true)) {
            $dumpIncluded = (bool) ($manifest['database']['included'] ?? false);
            $report->add(new RecoveryCheck(
                'REC-005', 'Externe Projektdatenbank', $dumpIncluded ? RecoveryCheck::WARN : RecoveryCheck::SKIP,
                $dumpIncluded ? 'Ein externer Datenbank-Dump wurde gesichert; er wird aus Sicherheitsgründen nicht automatisch importiert.' : 'Externe Datenbank wurde nicht als physische Datenbank in das Projekt-ZIP eingebettet.',
                ['driver' => $driver],
                $dumpIncluded ? 'Dump prüfen und bei Bedarf kontrolliert in das Zielsystem importieren.' : 'Bei Bedarf vor dem Restore einen separaten Datenbank-Dump bereitstellen.'
            ));
        } else {
            $report->add(new RecoveryCheck('REC-005', 'Projektdatenbank', RecoveryCheck::WARN, 'Datenquellentreiber ist unbekannt.', ['driver' => $driver]));
        }

        $report->add(new RecoveryCheck('REC-006', 'Restore-Konflikt', RecoveryCheck::PASS, 'Restore wurde ohne Überschreiben eines vorhandenen Projekts abgeschlossen.'));
        return $report;
    }

    private function projectPath(string $projectId): string
    {
        return $this->enterpriseRoot . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR . $projectId;
    }

    private function jsonFile(string $file): ?array
    {
        if (!is_file($file)) { return null; }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function absoluteEnterprisePath(string $path): string
    {
        if ($path === '') { return ''; }
        if ($this->isAbsolute($path)) { return $path; }
        return $this->enterpriseRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
    }
}
