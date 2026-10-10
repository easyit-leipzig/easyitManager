# HF106 – Automatischer Lizenzserver-Vertrauensbootstrap

HF106 entfernt die Pflicht, beim ersten Einrichten des Enterprise-Managers die Server-Key-ID und den öffentlichen Ed25519-Schlüssel manuell zu beschaffen.

## Neuer Ablauf

1. Lizenzserver-URL eintragen.
2. `Server prüfen & Schlüssel laden` wählen – oder die Serverkonfiguration mit leerem Schlüsselfeld speichern.
3. Der Manager ruft `/api/v1/health` auf und liest den aktiven Schlüssel aus `server_trust`.
4. Key-ID und Ed25519-Public-Key werden validiert und anschließend in `storage/system/config/licensing.php` als Vertrauensanker gespeichert.
5. Danach kann der separate Aktivierungsschlüssel einer Lizenz eingegeben werden.

Für entfernte Server bleibt HTTPS mit Zertifikatsprüfung zwingend. Ein bereits gespeicherter Vertrauensanker wird nicht stillschweigend durch einen abweichenden neu entdeckten Schlüssel ersetzt.
