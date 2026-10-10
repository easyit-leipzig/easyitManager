# HF88 – Storage Phase 6

Phase 6 stellt Neuinstallation, Datenbank-Assistent und Recovery auf den projektorientierten Storage um.

## Verbindliches Layout

Lokale Projektdaten werden nicht mehr über globale CSV-/SQLite-Basispfade konfiguriert. Nach der Registrierung erhält jedes Projekt einen unveränderlichen `storage_key`. Erst danach wird der Projektspeicher bereitgestellt:

- `storage/projects/<storage_key>/csv/`
- `storage/projects/<storage_key>/sqlite/database.sqlite`
- `storage/projects/<storage_key>/pgsql/`
- `storage/projects/<storage_key>/documents/`
- `storage/projects/<storage_key>/backup/`
- `storage/projects/<storage_key>/export/`
- `storage/projects/<storage_key>/temp/`

Der Installer schreibt `PROJECT_STORAGE_KEY` in die Core-Konfiguration. Die früheren Einstellungen `PROJECT_DB_CSV_BASE_PATH` und `PROJECT_DB_SQLITE_BASE_PATH` werden bei neuen Installationen nicht mehr erzeugt. Der Datenbank-Assistent entfernt diese Schlüssel bei einer erfolgreichen Neuinstallation/Reparatur aus einer vorhandenen `.env`.

## Provisionierungsreihenfolge

1. Administrationsspeicher und Admin-Schema bereitstellen.
2. Projekt mit Status `provisioning` registrieren.
3. Projekt-ID und `storage_key` festschreiben.
4. Projektordner vollständig provisionieren.
5. Projektdatenbank bzw. CSV-/SQLite-Speicher anlegen.
6. Projektschema und interne Datenquelle anlegen.
7. Projektstatus auf `active` setzen und `project.json` aktualisieren.

Fehler in der Projektprovisionierung setzen den Status auf `provisioning_failed`.

## Recovery

Neue Vollbackups verwenden `easyit-full-backup/2`. Da der vollständige Projektbaum bereits Bestandteil des Anwendungssnapshots ist, werden CSV und SQLite nicht zusätzlich in globale Legacy-Bereiche gesichert. Ein Factory Reset entfernt den kanonischen Projektbaum `storage/projects/` vollständig. Restore von Backupformat 1 bleibt als Lesekompatibilität erhalten.

## Gate

`php tools/storage-phase6-gate.php`
