<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck(bool $ok,string $label): void { global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL')." - {$label}\n"; }
function fc(string $file): string { $v=@file_get_contents($file); if($v===false) throw new RuntimeException('Datei fehlt: '.$file); return $v; }
$boot=fc($root.'/system/app/bootstrap.php');
$setup=fc($root.'/setup.php');
$db=fc($root.'/installer/database.php');
$env=fc($root.'/DataForm5-Core/.env');
$version=trim(fc($root.'/VERSION'));
ck(str_contains($version,'HF107-ADMIN-DB-EASYIT-LICENSE')||str_contains($version,'HF108-LICENSE-EXTENSION')||str_contains($version,'HF111-LICENSE-SERVER-TRUST-REPAIR')||str_contains($version,'HF112-INSTALLER-ADMIN-SCHEMA-GATE')||str_contains($version,'HF117-AUDIT-ACTOR-FK-REPAIR')||str_contains($version,'HF118-CSV-RELATIONS-EXPRESSION-REPAIR')||str_contains($version,'HF119-CALENDAR-EMBEDDED-DATAFORM-CSV-WILDCARD')||str_contains($version,'HF120-CALENDAR-EVENT-PLACE-RELATION'),'HF107 Buildkennung / Nachfolgestand');
ck(str_contains($boot,"return 'easyit_license';"),'Runtime kanonisiert Legacy-Admin-DB auf easyit_license');
ck(str_contains($boot,'enterprise_repair_legacy_admin_database_env'),'Bestehende .env wird gezielt repariert');
ck(str_contains($boot,";dbname='.\$database.';charset=utf8mb4"),'MySQL/MariaDB verwendet kanonischen Datenbanknamen');
ck(str_contains($boot,'EnterpriseMssqlPdo') && str_contains($boot,',$database,'),'MSSQL verwendet kanonischen Datenbanknamen');
ck(str_contains($setup,"'db_database'=>'easyit_license'"),'Setup-Default ist easyit_license');
ck(str_contains($setup,"'admin_db_schema'=>'public'"),'DB-Name und PostgreSQL-Schema sind getrennt');
ck(str_contains($db,"'easyit_license'"),'Datenbank-Assistent verwendet easyit_license als Fallback');
ck(str_contains($env,'ADMIN_DB_DATABASE=easyit_license'),'Ausgeliefertes Grundprofil verwendet easyit_license');
$runtimeOld=preg_match("/(?:ADMIN_DB_DATABASE[^\\n]*\\?\\?\\s*['\"]easyit_admin['\"]|dbname=.*ADMIN_DB_DATABASE)/",$boot)===1;
ck(!$runtimeOld,'Kein produktiver Runtime-Fallback auf easyit_admin');
$fails=array_filter($checks,fn($r)=>!$r[0]);
echo "RESULT ".(count($checks)-count($fails))."/".count($checks)." PASS\n";
exit($fails?1:0);
