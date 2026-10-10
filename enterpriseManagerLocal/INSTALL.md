# Installation

## 1. Entpacken

Das Verzeichnis `enterpriseManager` in den Webroot entpacken, z. B. unter XAMPP nach:

```text
D:\xampp\htdocs\enterpriseManager\
```

## 2. Voraussetzungen

- PHP 8.2 oder neuer
- Apache oder vergleichbarer Webserver
- Schreibzugriff auf `storage/`, `workspace/`, `packages/`, `modules/` und die Laufzeitverzeichnisse des `DataForm5-Core`
- Für SQL-Datenbanken der passende PDO-Treiber

## 3. Start

Im Browser die Projektwurzel öffnen:

```text
http://localhost/enterpriseManager/
```

Die erste Seite ist immer die Administrator-Anmeldung.

Wenn die Administrationskonfiguration fehlt, **Setup durchführen** wählen. Im Setup erscheinen nur Datenbanktypen, deren PDO-Treiber tatsächlich geladen ist. CSV ist immer verfügbar. Die Standardzeitzone ist `Europe/Berlin`.

Bei PostgreSQL sind **Datenbank und Schema getrennt anzugeben**. Die Wartungsdatenbank (standardmäßig `postgres`) wird nur zum Prüfen bzw. Anlegen der Zieldatenbank verwendet. Anschließend wird das konfigurierte Schema in der Zieldatenbank angelegt und geprüft. Der PostgreSQL-Benutzer benötigt dafür die Rechte zum Anlegen der Datenbank und/oder des Schemas.

## 4. Superadministrator

Nach eingerichteter Administrationsdatenbank gilt:

- Fehlt noch ein aktiver Superadministrator, wird auf der Startseite das Formular **Ersten Superadministrator anlegen** angezeigt.
- Ist bereits ein aktiver Superadministrator vorhanden, wird nur die normale Anmeldung angeboten.

## 5. Abschluss

Der Install-Lock wird erst nach erfolgreichem Abschluss des Installations-/Health-Gates gesetzt. Zugangsdaten und produktive Laufzeitdaten gehören nicht in das Auslieferungs-ZIP.
## MySQL-/PostgreSQL-Benutzerverwaltung

Nach der Anmeldung ist die technische Datenbank-Benutzerverwaltung unter **Benutzer & Rechte → DB-Benutzer** erreichbar. Sie verwendet die in `.env` hinterlegten Verbindungen des Administrations- bzw. Projektspeichers. Deshalb muss das konfigurierte Verwaltungskonto ausreichend privilegiert sein:

- MySQL/MariaDB: Benutzer anlegen/ändern/löschen und Rechte auf Zieldatenbanken vergeben.
- PostgreSQL: `CREATEROLE` bzw. gleichwertige Administrationsrechte sowie GRANT-Rechte auf die Ziel-Datenbank und das Ziel-Schema.

PostgreSQL-Zugriffsrechte werden nicht nur auf die Datenbank, sondern zusätzlich auf das ausgewählte Schema, dessen vorhandene Tabellen, Sequenzen und Funktionen sowie – soweit das Verwaltungskonto Eigentümer ist – auf Default Privileges angewendet.

## PostgreSQL-Speicher nach Löschen neu aufbauen

Wurde ausschließlich ein physischer Projektspeicher gelöscht, darf die Administrationsdatenbank nicht mit gelöscht werden. Die Projektregistrierung bleibt im Administrationsspeicher bestehen. In der Projektliste wird der fehlende Speicher erkannt und kann über **Speicher neu aufbauen** erneut erzeugt werden. Existiert dagegen die konfigurierte PostgreSQL-Administrationsdatenbank nicht mehr, wird kein Fatal Error mehr ausgegeben; die Oberfläche verweist kontrolliert auf **Setup durchführen** bzw. den Datenbank-Assistenten.

