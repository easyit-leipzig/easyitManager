# easyIT License/DataForm API v1 – HF85 Phase 3

## Runtime-Grundsatz

Die Lizenz ist eine Obergrenze. Das serverseitige Runtime-Manifest enthält nur die Schnittmenge aus:

1. in der DataForm-Definition angeforderten Funktionen,
2. global freigegebenen Lizenz-Capabilities,
3. optionalen DataForm-spezifischen Einschränkungen.

Eine DataForm-spezifische Regel darf eine global verbotene Capability nicht erweitern.

## Lizenzpolicy

Aktivierungs- und Runtime-Antworten können enthalten:

```json
{
  "license_policy": {
    "max_dataforms": 10,
    "used_dataforms": 3,
    "available_dataforms": 7,
    "capability_policy": "explicit",
    "capabilities": [
      "dataset.read",
      "dataset.create",
      "dataset.update",
      "dataform.search",
      "dataform.filter"
    ],
    "disabled_capabilities": [
      "dataset.delete"
    ]
  }
}
```

`max_dataforms: null` bedeutet unbegrenzt. Gezählt wird jede aktive Kombination aus `project_id` und `dataform_id` genau einmal.

## Wesentliche Capability-Codes

CRUD:
- `dataset.read`
- `dataset.create`
- `dataset.update`
- `dataset.delete`

DataForm:
- `dataform.search`
- `dataform.filter`
- `dataform.pagination`

Relationen:
- `relation.read`
- `relation.createChild`
- `relation.attach`
- `relation.detach`

Scripting/Anpassung:
- `dataform.events`
- `dataform.custom_css`
- `dataform.custom_javascript`

Designer:
- `designer.validate`
- `designer.compile`
- `designer.publish`

Weitere Module:
- `export.execute`
- `api.access`

## POST /api/v1/activation/request

Aktiviert eine Installation. Die Antwort enthält zusätzlich `license_policy`.

## POST /api/v1/runtime/start

Request:

```json
{
  "project_id": "17",
  "dataform_id": "27",
  "df_license_token": "DFT1....",
  "client": {
    "product": "easyIT-enterprise",
    "version": "HF85",
    "runtime_protocol": "2"
  }
}
```

Ab HF85 Phase 3 reserviert die Runtime **keinen** DataForm-Slot mehr. Der Slot wird ausschließlich bei der manuellen Superadmin-Freigabe belegt. Runtime-Start akzeptiert nur ein zentral registriertes `approved` DataForm mit gültigem, signiertem `df_license_token`.

Das Manifest enthält nur lizenzierte Capabilities. Ohne `dataset.read` wird keine Runtime gestartet. Manifest-Version 3 bindet zusätzlich `dataform_uuid` und den SHA-256-Hash des DF-Tokens.

## POST /api/v1/runtime/action

Schreiboperationen werden nur erlaubt, wenn die entsprechende Capability im aktuellen Manifest vorhanden ist:

- `dataset.create`
- `dataset.update`
- `dataset.delete`
- Relationsaktionen entsprechend ihrer Capability.

## Designer

- `/api/v1/designer/validate` benötigt `designer.validate`
- `/api/v1/designer/compile` benötigt `designer.compile`
- `/api/v1/designer/publish` benötigt `designer.publish`

Validate, Compile, Publish und Revisionszugriff verlangen ab HF85 Phase 3 denselben signierten `df_license_token`. Der Designer reserviert selbst keinen DataForm-Slot mehr; die Platzbelegung erfolgt ausschließlich bei der manuellen Freigabe.

## Neue Fehlercodes

- `DATAFORM_LIMIT_REACHED`
- `CAPABILITY_NOT_LICENSED`
- `CAPABILITY_GLOBAL_DENY` (Admin-Verwaltung)

Alle normalen Client-Requests bleiben Ed25519-signiert; Serverantworten sind ebenfalls signiert.


## HF85 Phase 1 – DataForm registrieren

`POST /api/v1/dataforms/register` (signierte Installationsanfrage) registriert eine dauerhafte `dataform_uuid` zentral.
Die Registrierung startet immer mit `status=pending` und belegt in Phase 1 **keinen** DataForm-Lizenzplatz.

Payload (Auszug):

```json
{
  "project_id": "42",
  "local_dataform_id": "7",
  "dataform_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "name": "Kunden",
  "slug": "kunden"
}
```

Phase 2 ergänzt die manuelle Freigabe und Platzbelegung. Phase 3 ergänzt den signierten individuellen DF-Token und macht ihn für Runtime und Designer verbindlich.


## HF85 Phase 3 – individuellen DF-Token abrufen

`POST /api/v1/dataforms/token` ist eine signierte Installationsanfrage. Payload:

```json
{
  "project_id": "42",
  "dataform_id": "7"
}
```

Nur ein zentral registriertes `approved` DataForm erhält einen Token. Der Token ist an `license_id`, `installation_id`, `project_id`, `local_dataform_id` und `dataform_uuid` gebunden. Zusätzlich muss der zugehörige Eintrag in `license_dataform_usage` weiterhin `active` sein.

Wesentliche Fehlercodes: `DATAFORM_NOT_APPROVED`, `DATAFORM_INSTALLATION_MISMATCH`, `DATAFORM_TOKEN_MISSING`, `DATAFORM_TOKEN_INVALID`, `DATAFORM_TOKEN_UNTRUSTED`, `DATAFORM_TOKEN_BINDING_INVALID`, `DATAFORM_SLOT_NOT_ACTIVE`.

## HF85 Phase 4 – DataForm freigeben/löschen

`POST /api/v1/dataforms/release` ist eine signierte Installationsanfrage und muss **vor** der lokalen destruktiven DataForm-Löschung erfolgreich sein.

Beispiel-Payload:

```json
{
  "project_id": "17",
  "local_dataform_id": "5",
  "dataform_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "name": "Kunden",
  "slug": "customers",
  "_surface": {"origin":"https://kunde.example.de","host":"kunde.example.de"}
}
```

Erfolgsantwort enthält `status=released`, `slot_released`, `already_released`, `released_at` und die aktuelle `license_policy`. Die Operation ist idempotent. Nach `released` akzeptieren Runtime und Designer keinen alten DF-Token mehr.
