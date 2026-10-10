<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck120(bool $ok,string $label): void {global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc120(string $path): string {$s=@file_get_contents(dirname(__DIR__).'/'.$path);if($s===false)throw new RuntimeException('Datei fehlt: '.$path);return $s;}

// The CI image intentionally has no mbstring. Production XAMPP has it, but the
// gate supplies the tiny subset needed by DataForm slug/label normalization.
if(!defined('MB_CASE_TITLE')) define('MB_CASE_TITLE',0);
if(!defined('MB_CASE_UPPER')) define('MB_CASE_UPPER',1);
if(!defined('MB_CASE_LOWER')) define('MB_CASE_LOWER',2);
if(!function_exists('mb_strlen')) { function mb_strlen($s,$e=null){return strlen((string)$s);} }
if(!function_exists('mb_substr')) { function mb_substr($s,$st,$len=null,$e=null){return $len===null?substr((string)$s,$st):substr((string)$s,$st,$len);} }
if(!function_exists('mb_strtolower')) { function mb_strtolower($s,$e=null){return strtolower((string)$s);} }
if(!function_exists('mb_convert_case')) { function mb_convert_case($s,$mode,$e=null){return ucwords(strtolower((string)$s));} }

$version=trim(fc120('VERSION'));
$config=fc120('system/app/calendar_project.php');
$runtime=fc120('products/calendar/api/runtime.php');
$bridge=fc120('products/calendar/system/CalendarDataFormBridge.php');
$setup=fc120('products/calendar/setup.php');
ck120(str_contains($version,'HF120-CALENDAR-EVENT-PLACE-RELATION'),'HF120 Buildkennung');
ck120(str_contains($config,"'places'=>'places'")&&str_contains($config,"'places'=>0")&&str_contains($config,'>=8'),'Calendar-Konfiguration führt places als achten eingebetteten Datenbereich');
ck120(str_contains($runtime,"\$t['places']")&&str_contains($runtime,"\$q('location').\" BIGINT\""),'Neue Calendar-Schemata enthalten places und numerische events.location');
ck120(str_contains($bridge,"'name'=>'Calendar – Orte'")&&str_contains($bridge,"'calendar_event_place'")&&str_contains($bridge,'easyit_calendar_migrate_event_locations'),'Calendar/DataForm-Bridge provisioniert Orts-DataForm, Lookup und Legacy-Migration');
ck120(str_contains($setup,"'places'=>'Orte'")&&str_contains($setup,'?> / 8</dd>'),'Calendar-Setup bietet Orte-Datenpflege und acht Bindings');

require_once $root.'/system/app/EnterpriseCsvPdo.php';
require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
$base=sys_get_temp_dir().'/easyit_hf120_'.bin2hex(random_bytes(5));
@mkdir($base,0777,true);
$pdo=new EnterpriseCsvPdo($base,'calendar_places');
$ctx=['env'=>[],'project'=>['database_driver'=>'csv','database_name'=>'calendar_places'],'config'=>['mappings'=>[
    'events'=>'events','participants'=>'event_participants','persons'=>'persons','places'=>'places',
    'resources'=>'resources','categories'=>'categories','attachments'=>'event_attachments',
]]];

$schema=easyit_calendar_schema_install($pdo,$ctx);
ck120($pdo->tableExists('places')&&in_array('name',$pdo->columnNames('places'),true),'CSV-Schema erzeugt die Tabelle places mit Namensfeld');
$pdo->prepare('INSERT INTO events (start,end,location,activ,thema,event_status,version_no) VALUES (?,?,?,?,?,?,?)')
    ->execute(['2026-09-25 10:00:00','2026-09-25 11:00:00','Raum 101',1,'Legacy-Ort','planned',1]);

$integration=easyit_calendar_dataform_install($pdo,$ctx);
$bindings=(array)$integration['bindings'];
$warnings=(array)$integration['warnings'];
ck120(count(array_filter($bindings,static fn(array $b):bool=>(int)($b['id']??0)>0))===8 && count($warnings)===0,'Acht Calendar-DataForms werden auf CSV fehlerfrei provisioniert');

$rel=$pdo->query("SELECT * FROM dataform_relations WHERE name='calendar_event_place' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck120(is_array($rel)&&(string)($rel['relation_type']??'')==='n:1'&&(int)($rel['source_dataform_id']??0)===(int)$bindings['events']['id']&&(int)($rel['target_dataform_id']??0)===(int)$bindings['places']['id'],'calendar_event_place ist n:1 von events auf places');

$field=$pdo->prepare('SELECT name,field_type FROM dataform_fields WHERE id=?');$field->execute([(int)($rel['source_field_id']??0)]);$source=$field->fetch(PDO::FETCH_ASSOC);
$field->execute([(int)($rel['target_display_field_id']??0)]);$display=$field->fetch(PDO::FETCH_ASSOC);
ck120(($source['name']??'')==='location'&&($source['field_type']??'')==='number'&&($display['name']??'')==='name','events.location ist das Lookup-Feld; places.name ist die Anzeige');

$placeRows=DataFormRecordStore::all($pdo,(int)$bindings['places']['id']);
$eventRows=DataFormRecordStore::all($pdo,(int)$bindings['events']['id']);
$placeId=(int)($placeRows[0]['id']??0);
$eventLocation=(int)($eventRows[0]['data']['location']??0);
ck120(count($placeRows)===1&&($placeRows[0]['data']['name']??'')==='Raum 101'&&$placeId>0&&$eventLocation===$placeId,'Legacy-Freitextort wird als places-Datensatz übernommen und events.location auf die ID migriert');

$placeLicense=$pdo->prepare('SELECT license_status FROM dataforms WHERE id=?');$placeLicense->execute([(int)$bindings['places']['id']]);
ck120((string)$placeLicense->fetchColumn()==='embedded_calendar','Calendar – Orte ist Calendar-intern lizenziert');

$health=RelationManager::relationHealth($pdo,$rel?:[]);
ck120(!empty($health['valid']),'Ortsrelation ist laut RelationManager gesund');

$failed=array_values(array_filter($checks,static fn(array $c):bool=>!$c[0]));
echo 'HF120 Calendar event/place gate: '.($failed?'FAIL':'PASS').' ('.(count($checks)-count($failed)).'/'.count($checks).")\n";
exit($failed?1:0);
