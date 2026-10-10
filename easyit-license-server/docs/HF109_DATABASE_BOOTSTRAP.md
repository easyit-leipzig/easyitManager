# HF109 – Datenbank-Bootstrap

Bei einer frischen Installation muss die konfigurierte Lizenzserver-Datenbank nicht mehr vorab manuell angelegt werden.

Standard lokal:

- DSN: `mysql:host=127.0.0.1;port=3306;dbname=easyit_license;charset=utf8mb4`
- Datenbank: `easyit_license`
- Kollation: `utf8mb4_unicode_ci`

Ablauf:

1. Der Lizenzserver versucht die normale Verbindung.
2. Meldet MySQL/MariaDB `1049 Unknown database`, verbindet sich der Server einmal ohne `dbname`.
3. `easyit_license` wird mit `utf8mb4 / utf8mb4_unicode_ci` angelegt.
4. Die normale Verbindung wird erneut aufgebaut.
5. Die vorhandene Migration Engine erzeugt/aktualisiert die Tabellen.

Eine bestehende Datenbank wird niemals gelöscht oder neu erstellt. Falls der konfigurierte DB-Benutzer keine CREATE-DATABASE-Berechtigung besitzt, erscheint eine verständliche Fehlermeldung statt eines ungefangenen PDO-Fatal-Errors.

PostgreSQL wird analog über die Maintenance-Datenbank `postgres` unterstützt.
