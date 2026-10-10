<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function hf117_ck(bool $ok,string $label): void { global $checks; $checks[]=[$ok,$label]; echo ($ok?'PASS':'FAIL')." - {$label}\n"; }
$boot=(string)file_get_contents($root.'/system/app/bootstrap.php');
$create=(string)file_get_contents($root.'/app/projects/create.php');
$schema=(string)file_get_contents($root.'/installer/schema/admin/003_projects_audit.php');
$version=trim((string)file_get_contents($root.'/VERSION'));
hf117_ck((str_contains($version,'HF117-AUDIT-ACTOR-FK-REPAIR')||str_contains($version,'HF118-CSV-RELATIONS-EXPRESSION-REPAIR')||str_contains($version,'HF119-CALENDAR-EMBEDDED-DATAFORM-CSV-WILDCARD')||str_contains($version,'HF120-CALENDAR-EVENT-PLACE-RELATION')),'HF117 Buildkennung');
hf117_ck(str_contains($boot,'function enterprise_audit_local_user_id'),'Zentraler Audit-Aktor-Resolver vorhanden');
hf117_ck(str_contains($boot,'enterprise_is_customer($sessionUser)'),'Kundenkonto wird vom lokalen Benutzer-FK getrennt');
hf117_ck(str_contains($boot,"'scope'=>'customer'")&&str_contains($boot,"'remote_user_id'"),'Remote Kunden-Aktor bleibt im Audit-Kontext nachvollziehbar');
hf117_ck(str_contains($boot,"SELECT id FROM users WHERE id=? LIMIT 1"),'Lokaler Audit-Benutzer wird vor FK-Nutzung verifiziert');
hf117_ck(str_contains($boot,"'scope'=>'unmapped_local'")&&str_contains($boot,'return null;'),'Veraltete lokale Benutzer-ID wird FK-sicher zu NULL');
hf117_ck(str_contains($schema,'user_id BIGINT UNSIGNED NULL')&&str_contains($schema,'ON DELETE SET NULL'),'audit_log.user_id ist nullable und FK-kompatibel');
hf117_ck(str_contains($create,"enterprise_audit(\$pdo,(int)\$user['id'],'project.provision'"),'Projekt-Provisionierung nutzt den zentral reparierten Auditpfad');
$fails=count(array_filter($checks,static fn(array $r):bool=>!$r[0]));
echo 'RESULT '.(count($checks)-$fails).'/'.count($checks)." PASS\n";
exit($fails?1:0);
