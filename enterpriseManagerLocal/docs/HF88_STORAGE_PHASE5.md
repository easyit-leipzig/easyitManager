# HF88 – Storage Phase 5: Zentralisierung der Laufzeitpfade

Phase 5 verbietet neue Schreibzugriffe auf die alten globalen Projektpfade. Die kanonischen Laufzeitpfade werden ausschließlich aus `projects.storage_key` abgeleitet.

## Kanonisch

- CSV: `storage/projects/<storage_key>/csv/`
- SQLite: `storage/projects/<storage_key>/sqlite/database.sqlite`
- Dokumente: `storage/projects/<storage_key>/documents/<modul>/`
- Export: `storage/projects/<storage_key>/export/`
- Löscharchive: `storage/system/archive/project-backups/<storage_key>/`

Legacy-Pfade werden nur noch von Migrations-/Kompatibilitätscode gelesen. Neue Anwenderpakete exportieren keine Dateien aus `storage/dataform/uploads` mehr. Sind dort noch Dateien vorhanden, wird der Export bewusst gestoppt und verlangt zuerst die Dokumentmigration.

`tools/storage-audit.php` prüft den aktiven Code auf verbliebene Legacy-Pfadreferenzen. Installer, Recovery und Migrationswerkzeuge werden in dieser Phase separat ausgewiesen, weil sie in Phase 6 umgestellt werden.
