# HF114 – Setup Database Rebuild Contract

Der Lizenzserver-Setup besitzt ab HF114 einen verbindlichen Neuaufbauvertrag.

1. Existiert die konfigurierte SQL-Datenbank nicht, versucht das Setup sie mit dem angegebenen DB-Benutzer anzulegen. Schlägt dies wegen fehlender CREATE-DATABASE-/CREATEDB-Rechte fehl, wird das Setup abgebrochen und eine eindeutige Berechtigungsmeldung ausgegeben.
2. Existiert die Datenbank bereits, werden beim Setup alle Benutzertabellen der Zieldatenbank (MySQL/MariaDB) bzw. des konfigurierten Schemas (PostgreSQL) gelöscht. Bei SQLite werden alle nicht-internen Tabellen der ausgewählten Datei gelöscht.
3. Ist die Datenbank vorhanden, aber leer, wird unmittelbar das vollständige Schema angelegt.
4. Anschließend werden alle 39 HF113/HF114-Pflichttabellen, Spalten, Indizes und Stammdaten vollständig neu provisioniert und real validiert.
5. Erst bei bestandenem Schema-Gate werden Datenquellenkonfiguration, Server-Key und Setup-Lock finalisiert.
6. Der spätere lokale Reparaturmodus bleibt nicht-destruktiv und ergänzt nur fehlende Struktur.

Der Verbindungstest ist nicht destruktiv: Eine fehlende Datenbank wird dabei nur erkannt und erst beim Initialisieren angelegt.
