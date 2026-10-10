# HF88 – Storage-Neuorganisation Phase 2

Phase 2 bindet die lokalen Projektdatenspeicher CSV und SQLite an den in Phase 1 eingeführten stabilen Projekt-Storage.

## Zielpfade

- CSV: `storage/projects/<storage_key>/csv/`
- SQLite: `storage/projects/<storage_key>/sqlite/database.sqlite`
- PostgreSQL-Metadaten bleiben unter `storage/projects/<storage_key>/pgsql/`; die eigentliche PostgreSQL-Datenbank verbleibt im PostgreSQL-Server.

Neue CSV- und SQLite-Projekte werden ausschließlich in diesen Projektpfaden provisioniert. Der bisherige globale Speicher `storage/project-csv/<database>` beziehungsweise `storage/project-sqlite/<database>.sqlite` wird für neue Projekte nicht mehr verwendet.

## Abwärtskompatibilität

Für bereits registrierte Projekte besteht eine Legacy-Fallback-Erkennung. Solange im neuen Projektpfad noch kein Datenbestand vorhanden ist, kann der vorhandene alte CSV-/SQLite-Speicher weiter gelesen werden. Dadurch kann die Umstellung ohne Big-Bang-Migration erfolgen.

## Migration vorhandener Daten

`tools/storage-project-data-migrate.php` ist ein CLI-Werkzeug.

Dry-Run:

`php tools/storage-project-data-migrate.php`

Migration mit Kopie und Integritätsprüfung:

`php tools/storage-project-data-migrate.php --apply`

Erst nach erfolgreicher Prüfung kann der alte Speicher zusätzlich entfernt werden:

`php tools/storage-project-data-migrate.php --apply --remove-legacy`

CSV wird dateiweise kopiert und anschließend per SHA-256-Baumvergleich verifiziert. SQLite wird zunächst in eine temporäre Datei kopiert, per `PRAGMA integrity_check` und SHA-256 geprüft und anschließend atomar als `database.sqlite` aktiviert. Ohne `--remove-legacy` bleibt der alte Bestand als Sicherheitskopie erhalten.

## Projektanlage

Die Projektregistrierung wird nun zuerst mit Status `provisioning` erzeugt. Danach werden Projekt-ID, `storage_key` und Ordnerstruktur angelegt. Erst anschließend wird der physische Projektdatenspeicher erstellt und das Projektschema installiert. Nach erfolgreicher Verifikation wechselt das Projekt auf `active`.

## Data Sources

Der projektinterne CSV- beziehungsweise SQLite-Data-Source-Eintrag wird auf den neuen physischen Projektpfad aktualisiert. Für CSV lautet die interne Kombination `base_path=<Projektwurzel>` und `database=csv`; für SQLite wird direkt `.../sqlite/database.sqlite` referenziert.

## Noch nicht Bestandteil von Phase 2

Die Migration der DataForm-/Calendar-Dokumente nach `documents/`, die projektbezogene Neuordnung von Backup/Restore/Export sowie die Bereinigung der alten Installer-Pfadoptionen folgen in den nächsten Phasen.
