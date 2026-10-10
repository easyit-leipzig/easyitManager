# HF85 – Phase 1: DataForm-Identität und zentrale Registrierung

Diese Phase bildet ausschließlich das Fundament der individuellen DataForm-Lizenzierung.

## Neu

- Zentrale Tabelle `license_dataforms`.
- Jedes DataForm wird über eine dauerhafte UUID v4 identifiziert.
- Neue API `POST /api/v1/dataforms/register`.
- Registrierung ist nur über eine signierte, aktivierte Installation möglich.
- Registrierung prüft Lizenz, DataForm-Modul und Surface-Policy.
- Neuer Zustand bei Erstregistrierung: `pending`.
- Eine reine Registrierung belegt noch keinen zusätzlichen Lizenzplatz.
- Kollisionen zwischen UUID und bestehender lokaler Zuordnung werden mit `DATAFORM_IDENTITY_CONFLICT` abgewiesen.

## Übergangsschutz

Der bisherige `claimDataform`-Mechanismus bleibt in Phase 1 als Legacy-Mengen-Gate aktiv. Er wird erst dann entfernt, wenn die manuelle Freigabe und die signierte DF-Token-Prüfung vollständig verfügbar sind. Damit wird durch ein Teilupdate die bisherige Mengenbegrenzung nicht abgeschaltet.

## Noch nicht Bestandteil

- Superadmin-Freigabe einzelner DataForms.
- Belegung/Freigabe eines Lizenzplatzes durch die neue Registry.
- signierter DF-Lizenztoken.
- Runtime-/Designer-Zwang auf einen freigegebenen DF-Token.
- Token-Widerruf beim Löschen.

## Gate

`php gates/HF85_PHASE1_STATIC_GATE.php`
