# HF137 – Ein-Seiten-Einrichtung des Lizenzservers

Die komplette Erst- und lokale Neueinrichtung des easyIT License Servers erfolgt auf `setup/` in einem einzigen Formular. Datenquelle, Zustand der Zieldatenbank und erster Superadministrator werden gemeinsam verarbeitet. `setup.php` verweist als Kurzadresse auf diese Seite.

## Vorhandene Ziel-/Demo-Datenbank

Eine vorhandene Datenbank wird nicht mehr automatisch gelöscht. Vor dem Abschluss muss ausdrücklich eine der beiden Varianten gewählt werden:

- **beibehalten** – vorhandene Daten bleiben erhalten; fehlende Tabellen und Spalten werden nicht-destruktiv ergänzt,
- **löschen und vollständig neu aufbauen** – alle Tabellen der gewählten Zieldatenbank bzw. des PostgreSQL-Schemas werden entfernt und das vollständige Schema wird neu erzeugt.

Der destruktive Neuaufbau verlangt zusätzlich eine eigene Bestätigung. Ohne Auswahl bzw. ohne Bestätigung wird die Installation abgebrochen.

## Superadministrator

Benutzername, Anzeigename und Kennwort des ersten Superadministrators befinden sich auf derselben Setup-Seite. Bei einem Neuaufbau wird der Superadministrator unmittelbar nach der Schemaerstellung angelegt. Beim Beibehalten einer Datenbank bleibt ein bereits vorhandener Administrator unverändert; fehlt er, kann er lokal im selben Durchlauf erzeugt werden.

## READY-Status

Der Server gilt als `READY`, wenn die Datenbank erreichbar ist, mindestens ein Admin vorhanden ist und der Setup-Lock geschrieben wurde. Der Health-Endpunkt liefert zusätzlich `payload.status=ready|setup_required` und `payload.ready=true|false`.
