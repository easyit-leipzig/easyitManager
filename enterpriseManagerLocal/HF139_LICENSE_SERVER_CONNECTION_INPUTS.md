# HF139 – Lizenzserver-Verbindung im Enterprise Manager

Der Enterprise Manager besitzt auf `setup.php` jetzt einen eigenen sichtbaren Abschnitt zur Lizenzserver-Verbindung.

## Änderungen

- editierbare vollständige Lizenzserver-URL
- Unterstützung lokaler abweichender Ports und Pfade
- separater Schritt **Verbindung prüfen und Adresse übernehmen**
- persistente Speicherung der Serveradresse unter `storage/system/config/licensing.php`
- Anzeige des verwendeten Health-Endpunkts
- READY / SETUP_REQUIRED / NICHT ERREICHBAR direkt im Manager
- Link zum Ein-Seiten-Setup des Lizenzservers
- Installation und Aktivierungsabschluss verwenden die vom Benutzer gewählte Serveradresse
- automatische Erstkopplung bleibt auf localhost / 127.0.0.1 / ::1 beschränkt

Lizenzserver-Datenbankkennwort und Lizenzserver-Superadmin werden nicht im Manager gespeichert. Diese Angaben bleiben ausschließlich im Setup des Lizenzservers.
