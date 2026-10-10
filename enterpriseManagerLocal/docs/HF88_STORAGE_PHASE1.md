# HF88 – Storage-Neuorganisation, Phase 1

Phase 1 führt die projektorientierte Storage-Identität ein, ohne bestehende CSV-/SQLite-Datenbestände bereits physisch zu verschieben.

## Zielstruktur

`storage/projects/<storage_key>/` enthält `csv/`, `sqlite/`, `pgsql/dumps/`, `documents/` (DataForm, Calendar, Attachments, Imports, Generated), `backup/`, `export/`, `temp/` und `project.json`.

## Storage-Key

Jedes Projekt erhält einen unveränderlichen Schlüssel nach dem Muster `prj_00000063_testprojekt-63`. Der sichtbare Projektname kann später geändert werden, ohne den Storage-Pfad umzubenennen.

## Bestehende Projekte

`enterprise_upgrade()` ergänzt `projects.storage_key`. Das Tool `tools/storage-project-layout-migrate.php` erzeugt anschließend die Projektordner und Manifeste für alle vorhandenen Projekte. Bestehende Daten in `storage/project-csv`, `storage/project-sqlite` und `storage/dataform/uploads` bleiben in Phase 1 unverändert.

## Nächste Phasen

Phase 2 verschiebt/integriert CSV und SQLite in den Projektordner und stellt die Store-API um. Phase 3 migriert Dokumente/Uploads. Phase 4 vereinheitlicht PostgreSQL-Metadaten, Backup, Restore und Distribution. Phase 5 entfernt die Legacy-Pfade nach erfolgreichem Gate.
