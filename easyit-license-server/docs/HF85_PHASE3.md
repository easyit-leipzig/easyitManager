# HF85 Phase 3 – Individual DataForm License Token

Phase 3 schaltet die DataForm-Lizenzierung von der bisherigen Runtime-/Publish-Platzbelegung auf die in Phase 2 vorbereitete manuelle Freigabe um.

## Ablauf

1. DataForm wird mit unveränderlicher `dataform_uuid` registriert (`pending`).
2. Superadmin gibt das DataForm manuell frei; dabei wird genau ein Lizenzplatz belegt (`approved`).
3. Nur für `approved` wird ein signierter Token im Format `DFT1...` ausgegeben.
4. Der Token bindet Lizenz, Installation, Projekt, lokale DataForm-ID und `dataform_uuid`.
5. Runtime und Designer prüfen Signatur, Bindung, Registry-Status und den weiterhin aktiven Lizenzplatz.
6. Runtime und Designer führen selbst **kein** `claimDataform()` mehr aus.

## Sicherheitswirkung

Eine kopierte oder manipulierte Tokenzeichenfolge genügt nicht. Die Signatur muss vom aktuellen Server-Schlüssel stammen, die Identitätsdaten müssen exakt übereinstimmen und die zentrale Registry muss weiterhin `approved` sein. Ein nicht aktiver Lizenzplatz führt zu `DATAFORM_SLOT_NOT_ACTIVE`.

Die bei der Erstregistrierung hinterlegte Installation wird ab Phase 3 nicht mehr automatisch umgebunden. Ein anderer `installation_id` führt zu `DATAFORM_INSTALLATION_MISMATCH`.

## Runtime

Runtime-Protokoll 2 und Manifest-Version 3 sind tokengebunden. Das signierte Manifest enthält `dataform_uuid` und `dataform_token_hash`. Alte Offline-Caches ohne diese Bindung werden verworfen.

## Noch nicht Phase 3

Das Löschen eines DataForms mit zentralem Widerruf, Tokenentzug und Freigabe des Lizenzplatzes folgt in Phase 4.
