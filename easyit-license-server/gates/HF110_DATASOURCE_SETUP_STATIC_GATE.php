<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
function ck(bool $ok,string $label): void{global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc(string $p): string{$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
$setup=fc('setup/index.php');$helper=fc('src/Setup/DataSourceSetup.php');$conn=fc('src/Database/Connection.php');$bootstrap=fc('bootstrap.php');$admin=fc('admin/index.php');$rootIndex=fc('index.php');$cfg=fc('config/app.php');
ck(str_contains($helper,'PDO::getAvailableDrivers()'),'Nur installierte PDO-Treiber werden angeboten');
ck(str_contains($helper,"'mysql'=>")&&str_contains($helper,"'pgsql'=>")&&str_contains($helper,"'sqlite'=>"),'MySQL/PostgreSQL/SQLite sind als unterstützte Datenquellen definiert');
ck(str_contains($setup,'Verbindung prüfen')&&(str_contains($setup,'Datenquelle speichern & Lizenzserver initialisieren')||str_contains($setup,'Datenbank vollständig neu aufbauen & Lizenzserver initialisieren')),'Setup besitzt Test- und Initialisierungsaktion');
ck(str_contains($helper,'database.local.php')&&str_contains($bootstrap,'database.local.php'),'Lokale Datenquellenkonfiguration wird gespeichert und geladen');
ck(str_contains($conn,'utf8mb4_unicode_ci'),'MySQL/MariaDB verwendet utf8mb4_unicode_ci');
ck(str_contains($conn,'SET search_path TO')&&str_contains($conn,'CREATE SCHEMA'),'PostgreSQL-Schema wird unterstützt');
ck(str_contains($conn,'PRAGMA foreign_keys = ON')&&str_contains($helper,'sqlite_path'),'SQLite-Datenquelle wird unterstützt');
ck(str_contains($admin,"header('Location: ../setup/')")&&str_contains($rootIndex,"Öffnen Sie /setup/"),'Unkonfigurierter Server führt zur Einrichtung');
ck(str_contains($cfg,"'dsn' => getenv('EASYIT_LICENSE_DSN') ?: ''"),'Frischpaket enthält keine fest verdrahtete Datenbank');
ck(str_contains($helper,'license-server-setup.lock'),'Abgeschlossene Einrichtung wird gesperrt');
$fails=array_filter($checks,fn($c)=>!$c[0]);echo 'HF110_CHECKS='.count($checks)."\n";if($fails){echo 'HF110_DATASOURCE_SETUP_FAILED\n';exit(1);}echo 'HF110_DATASOURCE_SETUP_OK\n';
