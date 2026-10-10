# HF85 – Phase 1: lokale DataForm-Identität

## Verhalten

Beim Öffnen des DataForm-Managers wird das Projektschema erweitert:

- `dataforms.df_uuid`
- `dataforms.license_status`
- `dataforms.license_registered_at`

Bestehende DataForms erhalten genau einmal eine UUID v4. Bereits gültige UUIDs werden nicht neu erzeugt. Neue DataForms werden beim nächsten Synchronisationslauf ebenfalls mit einer UUID versehen.

Nicht registrierte DataForms werden über den Lizenzclient an `POST /api/v1/dataforms/register` übertragen. Nach erfolgreicher Registrierung wird lokal `license_status=pending` gespeichert.

Der DataForm-Bereich zeigt die DF-UUID und den lokalen Lizenzstatus an.

## Zustände in Phase 1

- `unlicensed`: noch nicht zentral registriert
- `pending`: zentral registriert; manuelle Freigabe folgt in Phase 2
- `registration_error`: Registrierung konnte nicht abgeschlossen werden und wird erneut versucht

Die Zustände `approved`, `released` und `revoked` sind bereits als Folgezustände reserviert.

## Unterstützte Projektspeicher

Die Identitätserweiterung ist für CSV sowie die vorhandenen SQL-Projektspeicher ausgelegt. Für CSV wurde die Schema-/Unique-Behandlung so erweitert, dass leere UUID-Werte während der Migration nicht als Duplikat eines neuen DataForms behandelt werden.

## Gate

`php tools/hf85-phase1-gate.php`
