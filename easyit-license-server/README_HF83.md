# HF83 – Customer Surface & Self-Service Portal

Erweiterungen:
- umfangreiche Kundenstammdaten
- erlaubte Domains und IP-/CIDR-Netze pro Kunde
- serverseitige Surface-Prüfung bei Runtime und Designer
- optionaler Kunden-Self-Service für Domains/IPs
- separates Kundenportal unter `/customer/`
- Kundenportal-Benutzer werden im Superadmin je Kunde angelegt
- Kunde kann eigene Stammdaten und Kennwort pflegen
- Kunde sieht eigene Lizenzen und belegte DataForms

## Wichtig nach dem Update
Bestehende Kunden haben `surface_domain_required=1`. Vor produktiven Runtime-Aufrufen muss deshalb mindestens eine erlaubte Domain hinterlegt werden oder die Domainpflicht im Kundenstamm explizit deaktiviert werden.

## Enterprise-Client
HF83 benötigt den ergänzten `LicenseApiClient.php`, der bei signierten Runtime-/Designer-Aufrufen den aktuellen Surface-Origin mitsendet. Bei Reverse-Proxy/CLI kann `surface_origin` in `config/licensing.php` fest gesetzt werden.
