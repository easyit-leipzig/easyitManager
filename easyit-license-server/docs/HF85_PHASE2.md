# HF85 – Phase 2: Manuelle DataForm-Freigabe

Phase 2 baut direkt auf der in Phase 1 eingeführten Tabelle `license_dataforms` auf.

## Ablauf

1. Ein Enterprise-Manager registriert ein DataForm mit dauerhafter `dataform_uuid`.
2. Der zentrale Zustand bleibt `pending`; die Registrierung selbst belegt keinen neuen Lizenzplatz.
3. Im Superadmin erscheint das DataForm unter **DataForms**.
4. **Freigeben** ruft die zentrale Platzbelegung idempotent auf und setzt erst danach `license_dataforms.status=approved`.
5. **Ablehnen** setzt `status=rejected` und gibt einen eventuell aus dem Phase-1-Legacy-Runtime-Gate stammenden Platz wieder frei.
6. Jede Entscheidung erhöht die Lizenzgeneration und wird im Audit protokolliert.

## Übergang aus Phase 1

Während Phase 1 durfte der bestehende Runtime-/Designer-Claim als Schutz vor einer Aufweichung der Mengenbegrenzung weiterlaufen. Deshalb kann ein `pending` DataForm bereits in `license_dataform_usage` als `active` stehen. Die Phase-2-Freigabe erkennt diesen Zustand: `claimDataform()` ist idempotent und zählt keinen zweiten Platz. Eine Ablehnung gibt einen solchen Legacy-Claim frei.

## Statusrückmeldung

`POST /api/v1/dataforms/register` bleibt gleichzeitig der idempotente Statuskanal. Bei einer bereits vorhandenen Registrierung liefert der Server den aktuellen Status (`pending`, `approved` oder `rejected`) zurück. Damit ist kein unsignierter Status-Endpunkt notwendig.

## Noch nicht Bestandteil

- signierter DataForm-Lizenztoken,
- Token-Persistenz im Enterprise-Manager,
- Runtime-/Designer-Zwang auf `approved` plus gültigen Token,
- endgültige Entfernung des Legacy-`claimDataform` aus Runtime/Designer,
- Widerruf/Freigabe beim lokalen Löschen eines DataForms.

Diese Punkte gehören in HF85 Phase 3 und folgende Phasen.

## Gate

`php gates/HF85_PHASE2_STATIC_GATE.php`
