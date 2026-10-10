# easyIT License/DataForm Server – Nullstand / Neuinstallation

Dieser ZIP-Stand ist ausschließlich für eine vollständige Neuinstallation vorgesehen.
Er enthält Programmcode und Setup-Logik, aber keinerlei installationsspezifische Konfiguration oder Nutzdaten.

## 1. Zielverzeichnis

Den Ordner `easyit-license-server` beispielsweise nach

`D:\xampp\htdocs\easyit-license-server\`

entpacken.

## 2. Voraussetzungen

- PHP 8.2 oder neuer
- Extension `sodium`
- PDO
- mindestens einer der unterstützten PDO-Treiber: `pdo_mysql`, `pdo_pgsql` oder `pdo_sqlite`
- Schreibrecht für `config/`, `secure/` und bei SQLite den gewählten Datenbankordner

Nicht verfügbare PDO-Treiber werden im Setup nicht angeboten.

## 3. Setup starten

Im Browser öffnen:

`http://localhost/easyit-license-server/setup/`

Dort die zentrale Datenquelle festlegen.

### Setup-Vertrag

- Existiert die SQL-Datenbank nicht, versucht der Setup-Assistent sie vollständig neu anzulegen.
- Fehlen dem angegebenen Datenbankbenutzer die Rechte zum Anlegen der Datenbank, wird das Setup mit einer eindeutigen Berechtigungsmeldung abgebrochen.
- Existiert die Datenbank und enthält Tabellen, werden beim ausdrücklichen Neuaufbau alle Tabellen der gewählten Ziel-Datenbank bzw. des PostgreSQL-Schemas gelöscht und anschließend neu erzeugt.
- Existiert die Datenbank ohne Tabellen, wird das vollständige Schema angelegt.
- SQLite wird als neue bzw. geleerte Datenbankdatei vollständig provisioniert.
- Der Setup-Abschluss ist erst zulässig, wenn alle 52 zentralen Pflichttabellen real vorhanden sind.

Die 52 Tabellen umfassen Lizenzserver und gemeinsame Enterprise-Control-Plane.

## 4. Nach erfolgreichem Datenbank-Setup

Der Lizenzserver erzeugt automatisch sein Ed25519-Server-Schlüsselpaar. Danach wird die Setup-Sperre geschrieben.

Anschließend öffnen:

`http://localhost/easyit-license-server/admin/`

Wenn noch kein Superadmin existiert, erzeugt der Server lokal den einmaligen Token:

`secure/admin-setup.token`

Den Token über das Dateisystem lesen, den ersten Superadmin anlegen und anmelden. Nach erfolgreicher Anlage wird der Token gelöscht.

## 5. Enterprise-Manager erstmalig aktivieren

Im Lizenzserver-Dashboard:

`Enterprise-Manager erstmalig aktivieren` → `Bootstrap-Aktivierungsschlüssel erzeugen`

Dieser Schlüssel registriert nur die technische Installation. Er benötigt kein Kundenkonto und vergibt noch keine Kunden-/Produktlizenz.

## 6. Dateien, die im Nullstand absichtlich NICHT vorhanden sind

- `config/database.local.php`
- `secure/license-server-setup.lock`
- `secure/admin-setup.token`
- `secure/keys/server-private.key`
- `secure/keys/server-public.key`
- `secure/keys/server-keyring.json`
- lokale Datenbankdateien

Wenn eine dieser Dateien bereits vor dem ersten Setup vorhanden ist, wurde nicht mit diesem Nullstand begonnen oder der Stand wurde bereits initialisiert.
