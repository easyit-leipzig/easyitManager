# HF88 – Storage Phase 3: Dokumentorganisation

Phase 3 verlagert projektbezogene DataForm-Dateien in die stabile Projektwurzel:

`storage/projects/<storage_key>/documents/dataform/`

Neue Dateiuploads werden dort gespeichert. Die Bereiche `documents/calendar`, `documents/attachments`, `documents/imports` und `documents/generated` werden bereits bei der Projekt-Provisionierung angelegt und stehen Modulen als feste, projektbezogene Dokumentbereiche zur Verfügung.

## Kompatibilität

Alte Media-Deskriptoren mit Pfaden unter `storage/dataform/uploads/project-<id>/...` bleiben lesbar. Wenn die alte Datei dort nicht mehr existiert, wird derselbe relative Pfad im neuen DataForm-Dokumentbereich des Projekts aufgelöst. Damit können Legacy-Dateien nach erfolgreicher Migration entfernt werden, ohne sämtliche bestehenden Datensätze umzuschreiben.

## Migration

Nur prüfen:

`php tools/storage-project-documents-migrate.php`

Kopieren und per SHA-256 verifizieren:

`php tools/storage-project-documents-migrate.php --apply`

Nach erfolgreicher Prüfung zusätzlich den alten Projekt-Uploadordner entfernen:

`php tools/storage-project-documents-migrate.php --apply --remove-legacy`

Das Migrationswerkzeug überschreibt keine abweichenden Zieldateien. Konflikte stoppen die Migration des betroffenen Projekts.

## Exportierte DataForm-Runtime

Projektpakete übernehmen den `storage_key` und exportieren Medien aus dem neuen Dokumentbereich unter demselben relativen Pfad. Legacy-Dateien werden während der Übergangsphase ebenfalls berücksichtigt. Die Stand-alone-Runtime schreibt neue Dateien ebenfalls in den projektorientierten Dokumentbereich, wenn ein `storage_key` im Paket vorhanden ist.
