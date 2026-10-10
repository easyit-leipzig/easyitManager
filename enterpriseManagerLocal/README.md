# easyIT Enterprise Manager RC1.8-FC1-HF76

Bereinigtes Laufzeitpaket des easyIT Enterprise Managers mit integriertem DataForm5.

## Startverhalten

- `index.php` startet immer auf der Administrator-Anmeldung.
- Ist der Administrationsspeicher noch nicht eingerichtet, wird **Setup durchführen** angeboten.
- Ist der Administrationsspeicher eingerichtet, aber noch kein aktiver Superadministrator vorhanden, kann das erste Superadministratorkonto direkt auf der Startseite angelegt werden.
- Existiert bereits ein aktiver Superadministrator, wird ausschließlich die Anmeldung angezeigt.

## Datenquellen

Administrations- und Projektspeicher werden unabhängig gewählt. Datenbanktypen werden nur angeboten, wenn der dafür notwendige PDO-Treiber in der aktuellen PHP-Laufzeit vorhanden ist. CSV benötigt keinen PDO-Treiber und bleibt unabhängig davon verfügbar.

Für PostgreSQL wird jede Verbindung ausdrücklich als **Datenbank-/Schema-Kombination** konfiguriert. Setup und Datenbank-Assistent legen fehlende PostgreSQL-Datenbanken und Schemas an, verifizieren beide Objekte und speichern `ADMIN_DB_DATABASE` + `ADMIN_DB_SCHEMA` bzw. `PROJECT_DB_DATABASE` + `PROJECT_DB_SCHEMA`.

Standardzeitzone im Setup ist `Europe/Berlin`.

## Datenbank-Benutzerverwaltung

Für konfigurierte **MySQL/MariaDB- und PostgreSQL-Server** steht unter **Benutzer & Rechte → DB-Benutzer** eine getrennte technische Benutzerverwaltung zur Verfügung. Administrations- und Projektspeicher können unabhängig ausgewählt werden. Datenbankkonten lassen sich anlegen, sperren/freischalten, mit einem neuen Kennwort versehen und löschen. Rechte werden zielbezogen als **kein Zugriff**, **nur lesen**, **lesen/schreiben** oder **Vollzugriff** vergeben. Bei PostgreSQL ist die Berechtigung immer an die Kombination **Datenbank + Schema** gebunden. Das aktuell verwendete Laufzeitkonto und bekannte Systemkonten sind gegen Änderung und Löschen geschützt.

Die Oberfläche vergibt bewusst keine globalen MySQL-Administratorrechte und keine PostgreSQL-SUPERUSER-/CREATEROLE-Rechte. Das zur Verwaltung verwendete Konto muss selbst die serverseitigen Rechte `CREATE USER`/`ALTER USER` bzw. `CREATEROLE` sowie die erforderlichen GRANT-Rechte besitzen.

## Paketbereinigung

Dieses Paket enthält keine Test-Suites, Testreports, alten Hotfix-/Phasenstände, historischen Release-Notizen, Build-Gates, Beispiel-Backups, Beispiel-Exporte, Cache-Dateien oder Laufzeit-Logs. Die aktuelle SDK-Referenz und die zur Anwendung gehörenden HTML-Hilfen bleiben enthalten.

Installation: siehe `INSTALL.md`.
## Projektverwaltung und Neuaufbau

Nach der Administrator-Anmeldung öffnet sich für Administratoren direkt die Projektliste mit vollständigen CRUD-Aktionen einschließlich **Projekt löschen**. Wird ein physischer Projektspeicher absichtlich entfernt, bleibt die Projektregistrierung erhalten; die Projektliste kennzeichnet den fehlenden Speicher und bietet **Speicher neu aufbauen** an. Bei PostgreSQL erfolgt dieser Neuaufbau über die konfigurierte Maintenance-Datenbank und erzeugt die fehlende Datenbank-/Schema-Kombination erneut. Ein vorhandener Projektspeicher wird dabei nicht überschrieben.

