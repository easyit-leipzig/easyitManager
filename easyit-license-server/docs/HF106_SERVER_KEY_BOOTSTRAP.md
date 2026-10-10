# HF106 – Server-Key-Bootstrap

HF106 beseitigt die manuelle Erstversorgung des Ed25519-Vertrauensschlüssels.

## Verhalten

- Fehlen bei einer frischen Installation sowohl `server-private.key` als auch `server-public.key` und existiert noch kein Keyring, erzeugt der Lizenzserver beim ersten Start automatisch genau ein Ed25519-Schlüsselpaar.
- Der private Schlüssel verbleibt ausschließlich unter `secure/keys/`.
- Das Lizenzserver-Dashboard zeigt nur Server-Key-ID, SHA-256-Fingerprint und öffentlichen Schlüssel.
- `/api/v1/health` liefert wie bisher das signierte `server_trust`-Bundle. Der Enterprise-Manager kann daraus beim Bootstrap den aktiven öffentlichen Schlüssel übernehmen.
- Existiert nur eine der beiden Schlüsseldateien, wird **kein** neues Paar erzeugt. Der Server stoppt mit `SERVER_KEY_MATERIAL_INCOMPLETE`, damit vorhandenes Schlüsselmaterial nicht stillschweigend ersetzt wird.
- Bestehende Schlüssel und Keyrings werden nicht überschrieben.

## Aktivierungsschlüssel

Der Ed25519-Server-Schlüssel ist nicht der Aktivierungsschlüssel einer Lizenz. Aktivierungsschlüssel werden weiterhin in der Lizenzverwaltung erzeugt bzw. rotiert. Der Server speichert davon nur den Hash des geheimen Anteils.
