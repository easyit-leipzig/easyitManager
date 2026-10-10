<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck112(bool $ok,string $label): void { global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL')." - {$label}\n"; }
$gate=file_get_contents($root.'/installer/admin-schema-gate.php')?:'';
$db=file_get_contents($root.'/installer/database.php')?:'';
$admin=file_get_contents($root.'/installer/admin.php')?:'';
$version=trim((string)@file_get_contents($root.'/VERSION'));
ck112(str_contains($gate,"'users'")&&str_contains($gate,"'roles'")&&str_contains($gate,"'enterprise_licenses'"),'Pflichttabellen sind explizit definiert');
ck112(str_contains($gate,'easyit_admin_schema_missing_tables'),'Realer Tabellenzustand wird geprüft');
ck112(str_contains($gate,'002_identity.php')&&str_contains($gate,'003_projects_audit.php'),'Gezielte Basisreparatur ist vorhanden');
ck112(!str_contains($gate,'INFORMATION_SCHEMA.TABLES'),'Gemeinsames Gate ist nicht an MySQL-information_schema gebunden');
ck112(str_contains($db,"require_once __DIR__ . '/admin-schema-gate.php'"),'Schritt 6 lädt das Admin-Schema-Gate');
ck112(str_contains($db,'easyit_admin_schema_assert_or_repair($registryPdo'),'Schritt 6 validiert das reale Schema vor Fortsetzung');
ck112(str_contains($admin,"require_once __DIR__ . '/admin-schema-gate.php'"),'Schritt 7 lädt dasselbe Gate');
ck112(str_contains($admin,'easyit_admin_schema_assert_or_repair($pdo'),'Schritt 7 kann abgebrochene Basisschemata reparieren');
ck112(!str_contains($admin,'function tableExists(PDO $pdo'),'MySQL-spezifische lokale tableExists-Prüfung wurde entfernt');
ck112(($version==='RC1.8-FC1-HF112-INSTALLER-ADMIN-SCHEMA-GATE'||str_contains($version,'HF117-AUDIT-ACTOR-FK-REPAIR')||str_contains($version,'HF118-CSV-RELATIONS-EXPRESSION-REPAIR')||str_contains($version,'HF119-CALENDAR-EMBEDDED-DATAFORM-CSV-WILDCARD')||str_contains($version,'HF120-CALENDAR-EVENT-PLACE-RELATION')),'HF112-Version gesetzt');
$fails=count(array_filter($checks,fn($x)=>!$x[0]));
echo "RESULT ".(count($checks)-$fails)."/".count($checks)." PASS\n";
exit($fails?1:0);
