# HF159 – Vorhandene Demo-/Projektdatenbank

## Ziel

Bei der Erstinstallation darf eine bereits vorhandene SQL-Projektdatenbank nicht stillschweigend überschrieben oder ohne Entscheidung weiterverwendet werden.

## Ablauf

1. Beim Installationsabschluss prüft der Manager, ob die konfigurierte SQL-Projektdatenbank bereits existiert.
2. Existiert sie nicht, läuft die Installation wie bisher weiter.
3. Existiert sie, wird die Installation vor Änderungen angehalten.
4. Der Benutzer muss ausdrücklich eine der beiden Varianten wählen:
   - **Vorhandene Demo-DB verwenden** – die Datenbank bleibt bestehen; fehlende Schemaelemente werden durch die normalen idempotenten Projektmigrationen ergänzt.
   - **Demo-DB löschen und neu anlegen** – vor dem Löschen erscheint zusätzlich eine Browser-Sicherheitsabfrage. Nach Bestätigung wird die Datenbank gelöscht, das Löschen verifiziert und der Projektspeicher anschließend vollständig neu erstellt.
5. Die Freigabe ist mit einem kurzlebigen Session-Token an Treiber, Host, Port, Datenbank, Schema und Benutzer gebunden. Eine manipulierte oder veraltete Bestätigung wird nicht akzeptiert.

## Unterstützte SQL-Projektspeicher

MariaDB/MySQL, PostgreSQL, Microsoft SQL Server und Oracle XE. CSV und SQLite bleiben von dieser Datenbankabfrage unberührt.

## Sicherheitsregel

Ein destruktiver Neuaufbau erfolgt niemals allein aufgrund eines POST-Parameters. Zuerst muss der Server die vorhandene Datenbank erkannt und einen passenden Bestätigungstoken in der Sitzung erzeugt haben. Für die Löschvariante ist anschließend noch die sichtbare Sicherheitsabfrage zu bestätigen.
