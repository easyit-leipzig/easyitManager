# HF129 – Ein-Schritt-Erstinstallation

Die normale lokale Erstinstallation des Enterprise-Managers führt nach dem Absenden des Setup-Formulars automatisch die gesamte Inbetriebnahme aus:

1. Administrations- und Projektspeicher anlegen bzw. provisionieren.
2. Ersten lokalen Superadministrator aus den bereits eingegebenen Daten anlegen.
3. Den lokalen Lizenzserver als Geschwisterinstallation unter `/easyit-license-server` ermitteln.
4. Den signierten Health-Trust-Bundle abrufen und den aktiven Ed25519-Schlüssel kryptografisch prüfen.
5. Den Vertrauensanker persistent im Manager speichern.
6. Die lokale Installation über den loopback-only Endpoint `/api/v1/bootstrap/local-activate` aktivieren.
7. Den Bootstrap-Aktivierungsschlüssel ausschließlich serverintern erzeugen und unmittelbar verbrauchen; er wird nie an Browser oder Manager ausgegeben.
8. Den zuvor angelegten Superadministrator direkt in der laufenden Setup-Sitzung anmelden.
9. Direkt in das Enterprise-Dashboard weiterleiten.

Die bisherige manuelle Aktivierung bleibt ausschließlich als Recovery-Funktion erhalten.
