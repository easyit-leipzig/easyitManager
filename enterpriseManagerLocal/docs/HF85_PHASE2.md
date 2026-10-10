# HF85 – Phase 2: Rückmeldung der manuellen DataForm-Freigabe

Lokale DataForms mit `license_status=pending` werden beim Synchronisationslauf erneut über den signierten Registrierungsaufruf abgefragt. Der Lizenzserver antwortet idempotent mit dem aktuellen zentralen Zustand.

Damit wechselt ein lokales DataForm nach der Superadmin-Entscheidung ohne Änderung seiner UUID auf:

- `approved` – manuell freigegeben und zentraler Lizenzplatz belegt,
- `rejected` – manuell abgelehnt.

`license_registered_at` bleibt beim Polling unverändert und wird nur bei der ersten erfolgreichen Registrierung gesetzt.

`approved` und `rejected` sind in Phase 2 lokal terminal. Die Token-Ausgabe und die Runtime-Durchsetzung folgen erst in Phase 3.

## Gate

`php tools/hf85-phase2-gate.php`
