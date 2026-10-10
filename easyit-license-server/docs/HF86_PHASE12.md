# HF86 Phase 12 – Real-DB-Endgate und Abschluss

Phase 12 fügt keine neue Lizenzfunktion hinzu. Sie ist der verbindliche Abschluss-Gate für HF86.

## Was geprüft wird

Der DB-Gate erzeugt einen **wegwerfbaren Test-Namespace** und löscht ihn anschließend wieder. MariaDB/MySQL verwendet eine temporäre Datenbank; PostgreSQL ein temporäres Schema. Die Produktivtabellen werden nicht als Testziel verwendet.

Geprüft werden unter anderem:

- Neuinstallation aller Lizenzserver-Tabellen,
- erneuter idempotenter Migrationslauf,
- Wiederherstellung der nach HF85/HF86 hinzugekommenen Spalten,
- MariaDB/MySQL-Kollation `utf8mb4_unicode_ci`,
- Transaktions-Rollback,
- DataForm-Limit, idempotenter Claim und Freigabe eines Platzes,
- Installationslimit einschließlich `blocked` als weiterhin platzbelegend,
- `blocked -> active -> retired` und terminaler Zustand,
- Widerruf aktiver Runtime-Sitzungen und Installationsschlüssel,
- Domain-/CIDR-Self-Lockout-Schutz,
- `credential_version` und Widerruf bestehender Account-Sitzungen,
- Datenbankpfade der Server-Key-Rotation,
- reale konkurrierende `SELECT ... FOR UPDATE`-Sperre mit zwei PDO-Verbindungen.

## Direkter DB-Gate

MariaDB/MySQL:

```bat
php gates\HF86_PHASE12_DB_GATE.php --dsn="mysql:host=127.0.0.1;port=3306;charset=utf8mb4" --user=root --password=""
```

PostgreSQL:

```bat
php gates\HF86_PHASE12_DB_GATE.php --dsn="pgsql:host=127.0.0.1;port=5432;dbname=postgres" --user=postgres --password="PASSWORT"
```

Der Datenbankbenutzer benötigt für den Gate temporär das Recht, bei MariaDB/MySQL eine Testdatenbank anzulegen/zu löschen beziehungsweise bei PostgreSQL ein Schema anzulegen/zu löschen.

## Gesamter Abschluss-Gate unter Windows

```powershell
.\bin\HF86_PHASE12_WINDOWS_ENDGATE.ps1 `
  -MariaDbDsn "mysql:host=127.0.0.1;port=3306;charset=utf8mb4" `
  -MariaDbUser "root" `
  -MariaDbPassword "" `
  -PgsqlDsn "pgsql:host=127.0.0.1;port=5432;dbname=postgres" `
  -PgsqlUser "postgres" `
  -PgsqlPassword "PASSWORT"
```

`bin/hf86-final-gate.php` führt zuerst sämtliche bisherigen Server-Gates aus und danach beide realen DB-Gates. Nur wenn alle Teilprüfungen erfolgreich sind, endet der Lauf mit:

`HF86 FINAL STATUS: PASSED`

## Abschlusskriterium

HF86 gilt funktional als implementiert, aber als **produktiv abgenommen** erst nach einem erfolgreichen Phase-12-Endgate auf mindestens MariaDB/MySQL und PostgreSQL. Ein rein statischer Lauf darf nicht als realer DB-PASS bezeichnet werden.
