<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck119(bool $ok,string $label): void {global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - $label\n";}
function fc119(string $p): string {$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
$version=trim(fc119('VERSION'));
$csv=fc119('system/app/EnterpriseCsvPdo.php');
$bridge=fc119('products/calendar/system/CalendarDataFormBridge.php');
$registry=fc119('system/licensing/EnterpriseDataFormRegistryBridge.php');
$runtime=fc119('products/dataform/runtime.php');
ck119((str_contains($version,'HF119-CALENDAR-EMBEDDED-DATAFORM-CSV-WILDCARD')||str_contains($version,'HF120-CALENDAR-EVENT-PLACE-RELATION')),'HF119 Buildkennung');
ck119(str_contains($csv,"FROM DATAFORM_TABLE_BINDINGS B")&&str_contains($csv,'selectDataformTableBindings'),'CSV emuliert DataForm-Binding-JOIN');
ck119(str_contains($csv,"preg_match('/^[A-Za-z_][A-Za-z0-9_]*\\.\\*$/',\$spec)"),'Alias-Wildcards wie b.* sind im CSV-SELECT erlaubt');
ck119(str_contains($bridge,"license_status='embedded_calendar'")&&str_contains($bridge,'easyit_calendar_dataform_mark_embedded'),'Calendar markiert interne DataForms als eingebettet');
ck119(str_contains($registry,"'embedded_calendar'"),'Zentrale DataForm-Registrierung überspringt Calendar-interne DataForms');
ck119(str_contains($runtime,"embedded_product'=>'calendar'")&&str_contains($runtime,'Calendar-intern'),'Calendar-Workspace fordert keine Einzel-DataForm-Lizenz mehr an');

require_once $root.'/system/app/EnterpriseCsvPdo.php';
require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
$base=sys_get_temp_dir().'/hf119_gate_'.bin2hex(random_bytes(5));
@mkdir($base,0777,true);
$pdo=new EnterpriseCsvPdo($base,'gate');
$pdo->ensureAdminSchema();
$pdo->exec('DELETE FROM dataform_table_bindings');
$pdo->exec('DELETE FROM dataforms');
$pdo->exec('DELETE FROM data_sources');
$pdo->prepare('INSERT INTO dataforms(name,slug,status,df_uuid,license_status) VALUES(?,?,?,?,?)')->execute(['Calendar – Termine','calendar-events','active','11111111-1111-4111-8111-111111111111','registration_error']);
$id=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO dataform_table_bindings(dataform_id,source_kind,source_id,table_name,binding_mode) VALUES(?,?,?,?,?)')->execute([$id,'system',0,'events','schema']);
$q=$pdo->prepare("SELECT b.*,d.name AS dataform_name,d.slug AS dataform_slug FROM dataform_table_bindings b JOIN dataforms d ON d.id=b.dataform_id WHERE b.source_kind='system' AND b.source_id=0 AND b.table_name=? LIMIT 1");
$q->execute(['events']);$row=$q->fetch(PDO::FETCH_ASSOC);
ck119(is_array($row)&&($row['dataform_name']??'')==='Calendar – Termine'&&($row['table_name']??'')==='events','CSV-Binding-JOIN funktioniert mit WHERE und b.*');
$q=$pdo->query('SELECT d.* FROM dataforms d ORDER BY id');$wild=$q->fetch(PDO::FETCH_ASSOC);
ck119(is_array($wild)&&($wild['slug']??'')==='calendar-events','CSV SELECT d.* liefert Datensatz ohne Identifier-Fehler');
$ctx=['config'=>['mappings'=>['events'=>'events','participants'=>'event_participants','persons'=>'persons','resources'=>'resources','categories'=>'categories','attachments'=>'event_attachments']]];
easyit_calendar_dataform_mark_embedded($pdo,$ctx);
$q=$pdo->prepare('SELECT license_status FROM dataforms WHERE id=?');$q->execute([$id]);
ck119((string)$q->fetchColumn()==='embedded_calendar','Bestehender registration_error wird zu embedded_calendar migriert');
$failed=array_values(array_filter($checks,fn($c)=>!$c[0]));
echo 'HF119 Gate: '.($failed?'FAIL':'PASS').' ('.(count($checks)-count($failed)).'/'.count($checks).")\n";
exit($failed?1:0);
