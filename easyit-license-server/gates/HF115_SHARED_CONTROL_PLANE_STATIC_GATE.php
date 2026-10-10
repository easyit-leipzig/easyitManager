<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
function ck115(bool $ok,string $label): void{global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc115(string $p): string{$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
require_once $root.'/src/Database/MigrationRunner.php';
require_once $root.'/src/Database/EnterpriseControlPlaneSchema.php';
$runner=fc115('src/Database/MigrationRunner.php');
$control=fc115('src/Database/EnterpriseControlPlaneSchema.php');
$helper=fc115('src/Setup/DataSourceSetup.php');
$setup=fc115('setup/index.php');
$required=\EasyIT\LicenseServer\Database\MigrationRunner::REQUIRED_TABLES;
$enterprise=\EasyIT\LicenseServer\Database\EnterpriseControlPlaneSchema::REQUIRED_TABLES;
$license=array_values(array_diff($required,$enterprise));
ck115(count($license)===39,'39 Lizenzserver-Pflichttabellen bleiben vollständig erhalten');
ck115(count($enterprise)===13,'13 Enterprise-Control-Plane-Tabellen sind Teil des gemeinsamen Schemas');
ck115(count($required)===52,'Gemeinsamer Tabellenvertrag umfasst 52 Pflichttabellen');
foreach(['projects','users','roles','user_roles','audit_log','enterprise_licenses'] as $t)ck115(in_array($t,$required,true),'Pflichttabelle '.$t.' wird zentral provisioniert');
ck115(str_contains($runner,'EnterpriseControlPlaneSchema::migrate($this->pdo)'),'MigrationRunner provisioniert die Enterprise-Control-Plane');
$posControl=strpos($runner,'EnterpriseControlPlaneSchema::migrate($this->pdo)');$posStatus=strpos($runner,'$status=$this->schemaStatus()');
ck115($posControl!==false&&$posStatus!==false&&$posControl<$posStatus,'52-Tabellen-Gate läuft erst nach beiden Schemafamilien');
ck115(str_contains($control,"product_type VARCHAR(60) NOT NULL DEFAULT 'dataform'")&&str_contains($control,'description TEXT NULL'),'projects enthält die vom Manager erwarteten Kernspalten');
ck115(str_contains($control,"['superadmin','Superadministrator']")&&str_contains($control,"['admin','Administrator']"),'Enterprise-Rollen werden initialisiert');
ck115(str_contains($control,'private static function mysql')&&str_contains($control,'private static function pgsql')&&str_contains($control,'private static function sqlite'),'Control-Plane-DDL existiert für MySQL, PostgreSQL und SQLite');
ck115(str_contains($helper,'->migrate()')&&str_contains($setup,'Alle zentralen Tabellen prüfen & reparieren'),'Nicht-destruktive Reparatur ergänzt fehlende gemeinsame Tabellen');
ck115(str_contains($runner,"HF115_SHARED_CONTROL_PLANE_REBUILD_V1"),'Neuaufbau besitzt eigene HF115-Schemarevision');
$fails=array_filter($checks,fn($c)=>!$c[0]);echo 'HF115_CHECKS='.count($checks)."\n";if($fails){echo "HF115_SHARED_CONTROL_PLANE_FAILED\n";exit(1);}echo "HF115_SHARED_CONTROL_PLANE_OK\n";
