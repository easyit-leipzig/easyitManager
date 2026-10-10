# HF152 – Dashboard License Server Installation Gate

## Ziel
Wird das Enterprise-Dashboard aufgerufen, bevor der Lizenzserver vollständig eingerichtet und mit dem Manager gekoppelt wurde, darf das Dashboard nicht dargestellt werden. Stattdessen wird unmittelbar die Installation geöffnet.

## Gate
`app/dashboard.php` prüft vor Authentifizierung und Rendering die persistente Lizenzserver-Konfiguration.

READY für den Dashboard-Aufruf bedeutet:
- `enabled = true`
- `server_url` ist gesetzt
- mindestens ein `trusted_server_keys`-Eintrag ist vorhanden

Ist eine Bedingung nicht erfüllt, erfolgt die Weiterleitung nach:

`../setup.php?required=license-server#license-server`

Die Installationsseite zeigt in diesem Fall zusätzlich den Hinweis, dass das Dashboard bis zur vollständigen Lizenzserver-Einrichtung gesperrt bleibt.
