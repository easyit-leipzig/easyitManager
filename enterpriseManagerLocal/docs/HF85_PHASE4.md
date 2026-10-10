# HF85 Phase 4 – kontrolliertes DataForm-Löschen

Die lokale DataForm-Löschung ist ab Phase 4 an eine bestätigte zentrale Lizenzfreigabe gekoppelt.

`delete_dataform` führt deshalb zuerst `EnterpriseDataFormRegistryBridge::releaseForDelete()` aus. Nur wenn der Lizenzserver `status=released` bestätigt, wird `DataFormManager::delete()` aufgerufen.

Nach der zentralen Bestätigung werden lokal sofort `license_status=released` gesetzt und `license_token`, `license_token_key_id` sowie `license_token_issued_at` geleert. Zusätzlich wird der Runtime-Offline-Cache gelöscht und mit einer Release-Markierung gesperrt. Die Installations-Lizenzstate führt ebenfalls eine Release-Markierung. Dadurch kann ein bereits freigegebenes DataForm bei einem späteren Serverausfall nicht aus einem alten Runtime-Cache gestartet werden.

Fehlerverhalten:

- Lizenzserver/Netzwerk vor Release nicht erreichbar: keine lokale Löschung.
- Release erfolgreich, lokale Löschung fehlerhaft: lokale Zeile bleibt `released`; erneuter Löschversuch ist zulässig.
- Release bereits durchgeführt: Server bestätigt idempotent `already_released=true`; lokale Löschung kann fortgesetzt werden.

Auch lokal vorhandene DataForms mit früherem `registration_error` können kontrolliert gelöscht werden: der Server erzeugt dafür eine terminale `released`-Tombstone-Registrierung.
