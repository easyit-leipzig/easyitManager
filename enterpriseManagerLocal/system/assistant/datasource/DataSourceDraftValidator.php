<?php
declare(strict_types=1);

namespace EasyIT\Assistant\DataSource;

final class DataSourceDraftValidator
{
    public function __construct(private DataSourceRegistry $registry) {}

    /** @return array{errors:list<string>,warnings:list<string>} */
    public function validate(DataSourceDraft $draft, ?string $stepId = null): array
    {
        $d = $draft->toArray();
        $errors = [];
        $warnings = [];
        $driver = strtolower(trim((string) ($d['profile']['driver'] ?? '')));

        if ($stepId === null || in_array($stepId, ['profile', 'review'], true)) {
            if (trim((string) ($d['profile']['name'] ?? '')) === '') {
                $errors[] = 'Profilname fehlt.';
            }
            if (!$this->registry->has($driver)) {
                $errors[] = 'Nicht unterstützter Datenquellentyp: ' . ($driver !== '' ? $driver : 'leer');
            }
        }

        if ($stepId === null || in_array($stepId, ['connection', 'review'], true)) {
            $c = $d['connection'] ?? [];
            if (in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true)) {
                if (trim((string) ($c['host'] ?? '')) === '') { $errors[] = 'Host fehlt.'; }
                if ((int) ($c['port'] ?? 0) < 1 || (int) ($c['port'] ?? 0) > 65535) { $errors[] = 'Port muss zwischen 1 und 65535 liegen.'; }
                if (in_array($driver,['mysql','pgsql','mssql'],true) && trim((string) ($c['database'] ?? '')) === '') { $errors[] = 'Datenbankname fehlt.'; }
                if ($driver === 'pgsql') {
                    $schema = trim((string) ($c['schema'] ?? ''));
                    if ($schema === '') { $errors[] = 'PostgreSQL-Schema fehlt.'; }
                    elseif (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $schema) !== 1) { $errors[] = 'Ungültiger PostgreSQL-Schemaname.'; }
                }
                if ($driver === 'oracle' && trim((string) ($c['service'] ?? '')) === '') { $errors[] = 'Oracle Service Name fehlt.'; }
                if (trim((string) ($c['username'] ?? '')) === '') { $warnings[] = 'Kein Benutzername angegeben.'; }
            } elseif (in_array($driver, ['sqlite', 'csv'], true)) {
                if (trim((string) ($c['path'] ?? '')) === '') { $errors[] = 'Pfad fehlt.'; }
                if ($driver === 'csv') {
                    $delimiter = (string) ($c['delimiter'] ?? '|');
                    if (strlen($delimiter) !== 1) { $errors[] = 'CSV-Trennzeichen muss genau ein Zeichen sein.'; }
                    if ($delimiter !== '|') { $warnings[] = 'DataForm5 verwendet standardmäßig "|" als CSV-Trennzeichen.'; }
                }
            }
        }

        if ($stepId === null || in_array($stepId, ['source', 'review'], true)) {
            if (($d['test']['ok'] ?? false) !== true) {
                $errors[] = 'Die Datenquelle muss vor der Übernahme erfolgreich getestet werden.';
            }
            $sourceName = trim((string) ($d['selection']['sourceName'] ?? ''));
            if ($sourceName === '') {
                $errors[] = 'Tabelle, View bzw. CSV-Tabelle wurde nicht ausgewählt.';
            } elseif (($d['discovery'] ?? []) !== []) {
                $known = false;
                foreach ($d['discovery'] as $source) {
                    if ((string) ($source['name'] ?? '') === $sourceName) {
                        $known = true;
                        if (($source['meta']['valid'] ?? true) === false) {
                            $errors[] = 'Die ausgewählte CSV-Tabelle verletzt die DataForm5-CSV-Regeln: ' . $sourceName;
                        }
                        break;
                    }
                }
                if (!$known) {
                    $errors[] = 'Ausgewählte Quelle wurde bei der Erkennung nicht gefunden: ' . $sourceName;
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}
