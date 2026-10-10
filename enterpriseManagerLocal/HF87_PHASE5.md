# HF87 Phase 5 – DB-Engine / lokale Kundendaten

- Neues Produkt `db-engine` im zentralen Produktkatalog.
- Projektbezogene Lizenzierung über das bestehende Produkt-/Projektportal.
- Export nur mit aktivem `db-engine`-Entitlement und Capability `db-engine.export`.
- Export erzeugt ein eigenständiges DB-Engine-Projekt.
- Unterstützte Treiber: CSV, SQLite, MySQL/MariaDB, PostgreSQL, Oracle XE, MSSQL.
- DataForm-Runtime kann über `db_engine_path` eine exportierte Engine verwenden.
- Die Engine prüft bei jedem Aufbau der Datenbankverbindung einen vom Lizenzserver signierten projektbezogenen Token und `db-engine.runtime`.
- Kundendaten und Datenbankzugangsdaten verbleiben im exportierten Projekt auf dem Kundensystem.
