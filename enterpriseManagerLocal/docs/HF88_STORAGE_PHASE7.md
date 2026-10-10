# HF88 – Storage Phase 7: Integrität und Selbstprüfung

Phase 7 schließt die strukturelle Neuorganisation des projektbezogenen Speichers mit einer expliziten Integritätsprüfung ab. Jeder Projektordner wird weiterhin ausschließlich über den unveränderlichen `storage_key` identifiziert.

## Verbindliche Projektstruktur

Unter `storage/projects/<storage_key>/` müssen die Datenbereiche `csv/`, `sqlite/`, `pgsql/` und `documents/` vorhanden sein. Zusätzlich werden die bereits eingeführten Arbeitsbereiche `backup/`, `export/` und `temp/` geprüft. Für Dokumente sind `documents/dataform/`, `documents/calendar/`, `documents/attachments/`, `documents/imports/` und `documents/generated/` verbindlich. PostgreSQL erhält den Metadaten-/Dumpbereich `pgsql/dumps/`.

`project.json` bindet den physischen Ordner an die Projektidentität. Der darin gespeicherte `storage_key` muss exakt dem Ordnernamen entsprechen; eine gültige `project_id` ist Pflicht.

## Integritätsprüfung

Nur prüfen:

`php tools/storage-project-integrity.php`

Fehlende Standardordner reparieren:

`php tools/storage-project-integrity.php --repair`

Die Reparatur erzeugt ausschließlich fehlende Verzeichnisse. Sie verändert keine Projektdaten und korrigiert bewusst keine widersprüchlichen Identitätsdaten in `project.json`; solche Konflikte bleiben harte Fehler.

## Gate

`php tools/storage-phase7-gate.php`

Phase 7 ist bestanden, wenn die kanonische Provisionierung alle Pflichtbereiche enthält und das Integritätswerkzeug die Struktur einschließlich Manifestbindung prüfen kann.
