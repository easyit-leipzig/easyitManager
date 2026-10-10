# HF87 Phase 4.1 – customer_key Schema-Order-Fix

## Fehlerbild

Bei einer bestehenden lokalen Administrationsdatenbank, insbesondere PostgreSQL, konnte der Enterprise-Manager beim Start melden:

`SQLSTATE[42703]: Undefined column: 7 FEHLER: Spalte »customer_key« existiert nicht`

## Ursache

`customer_key` wurde in HF87 nachträglich in `enterprise_licenses` eingeführt. PostgreSQL wandelt die im historischen MySQL-DDL eingebetteten `INDEX`-Klauseln in separate `CREATE INDEX`-Anweisungen um. Bei einer vorhandenen Alt-Tabelle ist `CREATE TABLE IF NOT EXISTS` ein No-op; der separat erzeugte Index auf `customer_key` wurde jedoch ausgeführt, bevor die Spalte durch `enterprise_upgrade_license_customer_key()` ergänzt werden konnte. SQLite hatte dieselbe Reihenfolgegefahr.

## Korrektur

Der Index `idx_license_customer_key` wird nicht mehr im initialen Tabellen-DDL bzw. in der SQLite-Indexliste angelegt. Zuerst wird `customer_key` durch die Schema-Upgrade-Routine geprüft, bei Bedarf ergänzt und unzweideutig aus Altbeständen zurückgefüllt. Erst danach erzeugt dieselbe Routine den Index.

Die Änderung ist idempotent und verändert keine bestehenden Lizenzdaten.

## Betroffene PDO-Ziele

Die Reihenfolge ist für MySQL/MariaDB, PostgreSQL, SQLite, Oracle, MSSQL und den CSV-Kompatibilitätsadapter geeignet.

## Lokale PostgreSQL-Installation

Nach Einspielen des Patches genügt das erneute Öffnen der Administrationsanmeldung. `enterprise_upgrade()` führt die fehlende Spaltenmigration automatisch aus. Ein manueller Neuaufbau der Administrationsdatenbank ist nicht erforderlich.
