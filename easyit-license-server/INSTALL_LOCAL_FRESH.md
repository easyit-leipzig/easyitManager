# HF113-Hinweis – empfohlener Installationsweg

Bei einer frischen Installation zuerst `/setup/` öffnen. Dort die Datenquelle definieren und **Datenbank vollständig neu aufbauen & Lizenzserver initialisieren** ausführen. HF115 erzeugt eine fehlende Datenbank automatisch; existiert die Zieldatenbank bereits, werden beim Setup alle vorhandenen Tabellen gelöscht. Anschließend werden der Lizenzserver und die gemeinsam genutzte Enterprise-Manager-Control-Plane vollständig neu provisioniert. Das Setup wird erst abgeschlossen, wenn alle Pflichttabellen real verifiziert wurden. Die nachfolgenden älteren CLI-Hinweise bleiben als Administrations-/Fallback-Referenz erhalten.

# Lokale Neuinstallation – HF110

1. Paket nach `htdocs/easyit-license-server/` entpacken.
2. Im Browser `http://localhost/easyit-license-server/setup/` öffnen.
3. Die zentrale Datenquelle auswählen. Der Assistent zeigt nur PDO-Treiber an, die in der verwendeten PHP-Installation tatsächlich verfügbar sind.
4. Verbindungsdaten eingeben und **Verbindung prüfen**.
5. **Datenquelle speichern & Lizenzserver initialisieren** ausführen.
6. Danach `http://localhost/easyit-license-server/admin/` öffnen und den ersten Superadmin anlegen.

Für XAMPP ist typischerweise **MySQL / MariaDB** mit Host `127.0.0.1`, Port `3306`, Datenbank `easyit_license` und dem lokal eingerichteten DB-Benutzer passend. Fehlt die Datenbank, wird sie zwingend automatisch angelegt und verwendet `utf8mb4_unicode_ci`. Besitzt der angegebene DB-Benutzer dafür kein `CREATE DATABASE`-Recht, bricht das Setup mit einer eindeutigen Berechtigungsmeldung ab.

Alternativ können PostgreSQL oder SQLite verwendet werden, sofern `pdo_pgsql` bzw. `pdo_sqlite` in PHP verfügbar sind. Bei PostgreSQL werden Datenbank und Schema getrennt konfiguriert.

Die Datenquellenkonfiguration wird lokal in `config/database.local.php` gespeichert. Diese Datei sowie private Schlüssel und die Setup-Sperre gehören nicht in ein Release-Paket.
