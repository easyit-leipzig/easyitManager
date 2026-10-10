# HF85 Phase 4 – DataForm Release Lifecycle

Phase 4 schließt den Lebenszyklus einer individuell lizenzierten DataForm-Identität.

Ablauf beim Löschen:

1. Der Enterprise-Manager liest die bestehende `dataform_uuid`.
2. Vor jeder lokalen Löschung ruft er signiert `POST /api/v1/dataforms/release` auf.
3. Der Lizenzserver prüft Lizenz-/Installations-/Projekt-/DataForm-Bindung und Surface-Policy.
4. Ein eventuell aktiver Eintrag in `license_dataform_usage` wird auf `released` gesetzt.
5. `license_dataforms.status` wird dauerhaft auf `released` gesetzt; `released_at` und die Release-Request-ID werden protokolliert.
6. Aktive Runtime-Sitzungen genau dieser DataForm werden serverseitig widerrufen.
7. Erst nach bestätigter Serverfreigabe löscht der Enterprise-Manager lokales Tokenmaterial, sperrt den Offline-Cache und führt die lokale transaktionale DataForm-Löschung aus.

Die Freigabe ist idempotent. Scheitert die lokale Löschung nach erfolgreicher Serverfreigabe, bleibt die DataForm lokal als `released` zurück und kann erneut gelöscht werden. Ein alter Token ist zentral nicht mehr gültig und ein alter Offline-Cache darf nicht mehr verwendet werden.

`released` ist terminal. Dieselbe `dataform_uuid` darf nicht erneut registriert werden. Ein neu angelegtes DataForm erhält eine neue UUID und benötigt wieder eine eigene Freigabe.

Wenn die lokale UUID wegen eines früheren Registrierungsfehlers noch nie zentral gespeichert wurde, erzeugt der Release-Endpunkt atomar einen `released`-Tombstone. Damit bleibt die gelöschte UUID terminal und kann später nicht als neue DataForm-Identität registriert werden.
