# HF118 – CSV Relations / SQL Expression Repair

HF118 korrigiert den CSV-Projektspeicher für den DataForm-Beziehungsdesigner.

## Fehlerbild

Bei Calendar-/DataForm-Projekten mit CSV-Engine erschienen u. a.:

- `Ungültiger CSV-SQL-Bezeichner: 0`
- `Ungültiger CSV-SQL-Bezeichner: COALESCE(MAX(position),0)+10`

## Ursache

1. `EnterpriseCsvPdo` beantwortete jede Abfrage auf `information_schema.TABLES` als `COUNT(*)`, auch wenn `TABLE_NAME`, `TABLE_TYPE` oder `TABLE_ROWS` angefordert wurden. Der Relationsdesigner erhielt dadurch den Zahlenwert `0` bzw. `1` statt eines Tabellennamens.
2. Die Aggregatsemantik unterstützte nur `COALESCE(MAX(...),0)+1`, während DataForm seine Feld-/Layoutpositionen in Zehnerschritten berechnet.

## Reparatur

- CSV emuliert `information_schema.TABLES` nun mit realen Tabellennamen, `BASE TABLE`, Tabellenzeilen und den von Workspaces verwendeten Aliasen.
- `COUNT(*)`-Existenzprüfungen bleiben kompatibel.
- Ungültige Introspektionswerte werden als nicht vorhandene Tabelle behandelt und nicht als SQL-Identifier geparst.
- `COALESCE(MAX(spalte),default)+increment` unterstützt beliebige numerische Defaults und Inkremente, insbesondere `+10`.
- Der DataForm-Relationsdesigner kann CSV-Basistabellen und deren Spalten wieder ermitteln.

Die Änderung betrifft ausschließlich die CSV-SQL-Kompatibilitätsschicht; MySQL/MariaDB, PostgreSQL, SQLite und andere Backends werden nicht verändert.
