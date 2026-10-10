<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Project;

final class ProjectDraftValidator
{
    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(ProjectDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];
        $name = trim((string) ($d['identity']['name'] ?? ''));
        $slug = trim((string) ($d['identity']['slug'] ?? ''));
        $driver = strtolower(trim((string) ($d['storage']['driver'] ?? '')));

        if ($stepId === null || in_array($stepId, ['identity', 'review', 'provision'], true)) {
            if ($name === '') { $errors[] = 'Projektname fehlt.'; }
            if ($slug === '') {
                $errors[] = 'Projektkennung fehlt.';
            } elseif (!preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $slug)) {
                $errors[] = 'Projektkennung darf nur Kleinbuchstaben, Ziffern, "_" und "-" enthalten und muss 2 bis 63 Zeichen lang sein.';
            }
        }

        if ($stepId === null || in_array($stepId, ['storage', 'datasource', 'structure', 'review', 'provision'], true)) {
            if (!in_array($driver, ['mysql', 'pgsql', 'sqlite', 'csv', 'oracle', 'mssql'], true)) {
                $errors[] = 'Nicht unterstützte Projektdatenhaltung: ' . ($driver !== '' ? $driver : 'leer');
            }
            $projectPath = (string) ($d['storage']['projectPath'] ?? '');
            if ($projectPath === '' || !str_starts_with(str_replace('\\', '/', $projectPath), 'projects/')) {
                $errors[] = 'Projektpfad muss innerhalb von projects/ liegen.';
            }
            if (str_contains($projectPath, '..')) {
                $errors[] = 'Projektpfad darf keine Pfadnavigation ".." enthalten.';
            }
            if (($d['dataSource']['driver'] ?? '') !== $driver) {
                $errors[] = 'Projekt-Datenhaltung und Datenquellenprofil verwenden unterschiedliche Treiber.';
            }
            if (trim((string) ($d['dataSource']['profileName'] ?? '')) === '') {
                $errors[] = 'Datenquellen-Profilname fehlt.';
            }
            if (in_array($driver, ['mysql','pgsql','mssql'], true) && trim((string) ($d['dataSource']['databaseName'] ?? '')) === '') {
                $errors[] = ($driver === 'pgsql' ? 'PostgreSQL' : ($driver === 'mssql' ? 'Microsoft SQL Server' : 'MySQL')) . '-Datenbankname fehlt.';
            }
            if ($driver === 'pgsql') {
                $schema = trim((string)($d['dataSource']['schemaName'] ?? ''));
                if ($schema === '') $errors[] = 'PostgreSQL-Schema fehlt.';
                elseif (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $schema) !== 1) $errors[] = 'Ungültiger PostgreSQL-Schemaname.';
            }
            if (in_array($driver, ['sqlite', 'csv'], true) && trim((string) ($d['dataSource']['localPath'] ?? '')) === '') {
                $errors[] = 'Lokaler Datenpfad fehlt.';
            }
            if (in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true) && empty($d['dataSource']['requiresConnectionConfiguration'])) {
                $warnings[] = 'Externe Datenquelle ist als bereits konfiguriert markiert; der Datenquellen-Assistent muss die Verbindung trotzdem testen.';
            }
        }

        if ($stepId === 'provision' && empty($d['provision']['confirmCreate'])) {
            $errors[] = 'Bitte "Projekt jetzt anlegen" ausdrücklich bestätigen.';
        }

        if ($stepId === 'review' && empty($d['provision']['created'])) {
            $errors[] = 'Das Projekt wurde noch nicht angelegt.';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }
}
