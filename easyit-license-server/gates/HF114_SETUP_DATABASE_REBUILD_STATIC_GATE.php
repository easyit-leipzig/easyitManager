<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
function ck(bool $ok,string $label): void{global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc(string $p): string{$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
$runner=fc('src/Database/MigrationRunner.php');
$helper=fc('src/Setup/DataSourceSetup.php');
$conn=fc('src/Database/Connection.php');
$setup=fc('setup/index.php');
require_once $root.'/src/Database/MigrationRunner.php';
$required=\EasyIT\LicenseServer\Database\MigrationRunner::REQUIRED_TABLES;
ck(count($required)>=39,'Der bisherige Lizenzserver-Vollschema-Vertrag bleibt erhalten und darf erweitert werden');
ck(str_contains($runner,'public function rebuild()')&&str_contains($runner,'dropAllTables()'),'MigrationRunner besitzt expliziten Setup-Neuaufbau');
ck(str_contains($runner,"table_schema=DATABASE()")&&str_contains($runner,"table_type='BASE TABLE'")&&str_contains($runner,'SET FOREIGN_KEY_CHECKS=0'),'MySQL/MariaDB löscht alle vorhandenen Benutzertabellen vor Neuaufbau');
ck(str_contains($runner,"pg_tables WHERE schemaname=current_schema()")&&str_contains($runner," CASCADE"),'PostgreSQL löscht alle Tabellen des konfigurierten Schemas vor Neuaufbau');
ck(str_contains($runner,"sqlite_master WHERE type='table'")&&str_contains($runner,"name NOT LIKE 'sqlite_%'")&&str_contains($runner,'PRAGMA foreign_keys = OFF'),'SQLite löscht alle nicht-internen Tabellen vor Neuaufbau');
ck(str_contains($helper,'->rebuild()')&&str_contains($helper,"\$db['auto_create_database']=true"),'Setup erzwingt DB-Anlage und vollständigen Neuaufbau');
ck(str_contains($helper,'repairExisting')&&str_contains($helper,'->migrate()'),'Reparatur bleibt nicht-destruktiv und getrennt vom Setup');
ck(str_contains($conn,'DATABASE_BOOTSTRAP_FAILED')&&str_contains($setup,'CREATE DATABASE bzw. CREATEDB'),'Fehlende CREATE-DATABASE-Rechte erzeugen klare Setup-Meldung');
ck(str_contains($conn,'probeSetup')&&str_contains($helper,'Connection::probeSetup($db)'),'Verbindungstest prüft die Zieldatenbank separat');
ck(!str_contains($setup,'name="auto_create_database"')&&!str_contains($setup,'name="auto_create_schema"'),'DB-/Schema-Anlage ist im Setup nicht mehr optional abschaltbar');
ck(str_contains($setup,'alle vorhandenen Tabellen')&&str_contains($setup,'Datenbank vollständig neu aufbauen'),'Setup-Oberfläche weist eindeutig auf den destruktiven Neuaufbau hin');
$posRebuild=strpos($helper,'->rebuild()');$posPersist=strpos($helper,'self::persist($db,$basePath)');$posLock=strpos($helper,'self::writeLock($db,$basePath,$schema)');
ck($posRebuild!==false&&$posPersist!==false&&$posLock!==false&&$posRebuild<$posPersist&&$posPersist<$posLock,'Konfiguration und Setup-Lock werden erst nach erfolgreichem Neuaufbau finalisiert');
$fails=array_filter($checks,fn($c)=>!$c[0]);echo 'HF114_CHECKS='.count($checks)."\n";if($fails){echo "HF114_SETUP_DATABASE_REBUILD_FAILED\n";exit(1);}echo "HF114_SETUP_DATABASE_REBUILD_OK\n";
