# HF113 – Vollständige Schema-Provisionierung beim Lizenzserver-Setup

HF113 verschärft den Installationsvertrag des easyIT-Lizenzservers. Eine Datenquelle gilt erst dann als vollständig eingerichtet, wenn **alle Pflichttabellen des Lizenzservers real in der gewählten Datenquelle vorhanden sind**.

## Ablauf

1. Datenquelle auswählen.
2. Verbindung testen.
3. Datenbank/Schema bei Bedarf anlegen.
4. Vollständigen Tabellenvertrag idempotent provisionieren.
5. Ergänzende Spalten und Indizes nachziehen.
6. Module, Capabilities und Produktkatalog initialisieren.
7. Reale Tabellen gegen den zentralen Schema-Vertrag prüfen.
8. Erst bei 100 % vorhandenem Schema Server-Signaturschlüssel und Setup-Sperre finalisieren.

Die Setup-Sperre wird bei einem partiellen oder fehlerhaften Schema **nicht** geschrieben. Dadurch kann der Installationsvorgang nach Behebung eines DB-Rechte- oder Verbindungsfehlers wiederholt werden.

## Schema-Gate

Der aktuelle Vertrag umfasst 39 Pflichttabellen. Die maßgebliche Liste liegt ausschließlich in `MigrationRunner::REQUIRED_TABLES`. Der Gate-Test prüft zusätzlich, dass diese Liste exakt mit allen `CREATE TABLE IF NOT EXISTS`-Definitionen übereinstimmt.

## Reparatur vorhandener Installationen

Auf `localhost` bietet `/setup/` nach abgeschlossener Einrichtung zusätzlich **Alle Lizenzserver-Tabellen prüfen & reparieren** an. Dieser Vorgang ist idempotent und löscht keine vorhandenen Tabellen oder Daten. Fehlende Tabellen, Spalten und Seeds werden ergänzt.

## Unterstützte Datenquellen

Die vollständige Provisionierung gilt für alle vom HF110-Setup angebotenen Datenquellen: MySQL/MariaDB, PostgreSQL und SQLite, sofern der jeweilige PDO-Treiber installiert ist.
