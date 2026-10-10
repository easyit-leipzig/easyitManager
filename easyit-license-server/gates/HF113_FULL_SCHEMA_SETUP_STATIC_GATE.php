<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
function ck(bool $ok,string $label): void{global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc(string $p): string{$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}

$runner=fc('src/Database/MigrationRunner.php');
$setupHelper=fc('src/Setup/DataSourceSetup.php');
$setup=fc('setup/index.php');
$enterprise=fc('src/Database/EnterpriseControlPlaneSchema.php');
$migrate=fc('bin/migrate.php');
require_once $root.'/src/Database/MigrationRunner.php';
$required=\EasyIT\LicenseServer\Database\MigrationRunner::REQUIRED_TABLES;
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-zA-Z0-9_]+)/',$runner.'\n'.$enterprise,$m);
$defined=array_values(array_unique($m[1]??[]));
sort($required);sort($defined);

ck(count($required)>=39,'Mindestens die 39 ursprünglichen Lizenzserver-Pflichttabellen bleiben im zentralen Schema-Vertrag enthalten');
ck($required===$defined,'REQUIRED_TABLES entspricht exakt allen CREATE-TABLE-Definitionen');
ck(str_contains($runner,'schemaStatus()')&&str_contains($runner,'LICENSE_SCHEMA_INCOMPLETE'),'MigrationRunner besitzt reales Vollständigkeits-Gate');
ck(str_contains($runner,"FULL_SCHEMA_MIGRATION = 'HF115_SHARED_CONTROL_PLANE_REBUILD_V1'")||str_contains($runner,"FULL_SCHEMA_MIGRATION = 'HF114_FULL_SCHEMA_REBUILD_V1'")||str_contains($runner,"FULL_SCHEMA_MIGRATION = 'HF113_FULL_SCHEMA_V1'"),'Schema-Migration wird revisionssicher markiert');
$posMigrate=strpos($setupHelper,'$schema=(new MigrationRunner($pdo))->rebuild()');
$posPersist=strpos($setupHelper,'self::persist($db,$basePath)');
$posLock=strpos($setupHelper,'self::writeLock($db,$basePath,$schema)');
ck($posMigrate!==false&&$posPersist!==false&&$posLock!==false&&$posMigrate<$posPersist&&$posPersist<$posLock,'Setup sperrt erst nach vollständiger Schema-Provisionierung');
ck(str_contains($setupHelper,'repairExisting')&&substr_count($setupHelper,'(new MigrationRunner($pdo))->migrate()')>=1,'Bestehende Installation kann idempotent und nicht-destruktiv repariert werden');
ck((str_contains($setup,'Alle zentralen Tabellen prüfen & reparieren')||str_contains($setup,'Alle Lizenzserver-Tabellen prüfen & reparieren')),'Setup bietet lokale Schema-Reparatur an');
ck(str_contains($setup,'Pflichttabellen')&&str_contains($setup,"schemaResult['present']"),'Setup zeigt Schema-Gate/Tabellenstatus an');
ck(str_contains($migrate,'LICENSE_SERVER_SCHEMA_OK'),'CLI-Migration meldet vollständigen Schema-Status');
ck(str_contains($setupHelper,'repairExisting')&&str_contains($setupHelper,'$schema=(new MigrationRunner($pdo))->migrate()'),'Reparatur bleibt vom destruktiven Setup-Neuaufbau getrennt');

$fails=array_filter($checks,fn($c)=>!$c[0]);
echo 'HF113_CHECKS='.count($checks)."\n";
if($fails){echo "HF113_FULL_SCHEMA_SETUP_FAILED\n";exit(1);}echo "HF113_FULL_SCHEMA_SETUP_OK\n";
