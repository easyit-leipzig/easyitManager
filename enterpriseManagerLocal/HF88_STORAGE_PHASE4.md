# HF88 – Storage-Neuorganisation Phase 4

Phase 4 trennt projektbezogene Arbeitsartefakte von systemweit aufzubewahrenden Sicherungs- und Restore-Dateien.

## Zielstruktur

- `storage/projects/<storage_key>/export/application-packages/` – exportierte Anwenderpakete des Projekts
- `storage/projects/<storage_key>/backup/` – reserviert für projektinterne Sicherungen
- `storage/projects/<storage_key>/temp/` – projektbezogene temporäre Arbeitsdaten
- `storage/system/archive/project-backups/<storage_key>/` – Projektsicherungen, die eine Projektlöschung überleben müssen
- `storage/system/temp/project-restore-previews/` – temporäre hochgeladene Restore-Archive
- `storage/system/archive/legacy-project-distribution-packages/` – nur für die Migration nicht eindeutig zuordenbarer Alt-Exporte

## Änderungen

- Löschsicherungen werden nicht mehr unter `storage/project-delete-backups` erzeugt.
- Restore-Vorschauen werden nicht mehr unter `storage/project-restore-previews` erzeugt.
- neue Anwenderpakete werden projektbezogen im jeweiligen `export/`-Bereich erzeugt.
- Projektsicherungen verwenden Backup-Format 5 und enthalten zusätzlich `documents/`.
- Restore akzeptiert Format 5 und stellt enthaltene Dokumente im neuen Projekt-Storage wieder her.
- restaurierte Projekte erhalten einen stabilen `storage_key` und die standardisierte Projektordnerstruktur.

## Migration alter Artefakte

Prüfen:

`php tools/storage-project-artifacts-migrate.php`

Kopieren und per SHA-256 verifizieren:

`php tools/storage-project-artifacts-migrate.php --apply`

Nach erfolgreicher Prüfung alte Bereiche entfernen:

`php tools/storage-project-artifacts-migrate.php --apply --remove-legacy`

Nicht eindeutig einem Projekt zuordenbare historische Distribution-Packages werden absichtlich nicht einem Projektordner zugeschrieben, sondern im Systemarchiv abgelegt.
