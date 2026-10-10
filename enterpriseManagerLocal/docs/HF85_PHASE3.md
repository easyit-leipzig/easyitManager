# HF85 Phase 3 – Enterprise-Integration des DF-Tokens

Der Enterprise-Manager speichert für freigegebene DataForms den signierten DF-Token einschließlich Server-Schlüssel-ID und Ausstellungszeit.

`approved` wird weiter mit dem Lizenzserver synchronisiert, bis der Token lokal vorliegt. Runtime und Designer holen den aktuellen Token über den signierten Endpunkt `/api/v1/dataforms/token` und senden ihn bei geschützten Operationen mit.

Runtime-Protokoll 2 akzeptiert offline nur noch signierte Manifest-Version-3-Caches mit `dataform_uuid` und `dataform_token_hash`. Eine explizite serverseitige `DATAFORM_*`-Ablehnung sperrt die lokale Offline-Autorisierung ebenso wie bestehende Lizenz-/Installationsablehnungen.
