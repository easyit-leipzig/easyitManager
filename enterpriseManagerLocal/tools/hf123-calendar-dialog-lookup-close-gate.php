<?php
declare(strict_types=1);
$root=dirname(__DIR__);$checks=[];
function ck123(bool $ok,string $label): void {global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc123(string $p): string {$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
if(!defined('MB_CASE_TITLE')) define('MB_CASE_TITLE',0);
if(!defined('MB_CASE_UPPER')) define('MB_CASE_UPPER',1);
if(!defined('MB_CASE_LOWER')) define('MB_CASE_LOWER',2);
if(!function_exists('mb_strlen')) { function mb_strlen($s,$e=null){return strlen((string)$s);} }
if(!function_exists('mb_substr')) { function mb_substr($s,$st,$len=null,$e=null){return $len===null?substr((string)$s,$st):substr((string)$s,$st,$len);} }
if(!function_exists('mb_strtolower')) { function mb_strtolower($s,$e=null){return strtolower((string)$s);} }
if(!function_exists('mb_convert_case')) { function mb_convert_case($s,$mode,$e=null){return ucwords(strtolower((string)$s));} }

$version=trim(fc123('VERSION'));
$calendarJs=fc123('products/calendar/assets/js/calendar.js');
$bootstrapJs=fc123('products/calendar/assets/js/bootstrap.js');
$bridge=fc123('products/calendar/system/CalendarDataFormBridge.php');
$admin=fc123('products/calendar/api/admin_handler.php');
$records=fc123('products/dataform/records.php');
ck123(str_contains($version,'HF123-CALENDAR-DIALOG-LOOKUP-CLOSE'),'HF123 Buildkennung');
ck123(str_contains($calendarJs,"document.readyState==='loading'")&&str_contains($calendarJs,'closeDataFormTermDialog'),'X-Schließen wird auch nach bereits erfolgtem DOMContentLoaded gebunden');
ck123(str_contains($calendarJs,"closest('#closeDataFormLesson')")&&str_contains($calendarJs,"frame.src='about:blank'"),'Delegierter X-Handler schließt Dialog und entlädt iframe');
ck123(str_contains($bootstrapJs,'calendar.js?v=20260926-123'),'Calendar-JavaScript Cache-Buster ist angehoben');
ck123(str_contains($bridge,'easyit_calendar_sync_mapping_lookup_relations')&&str_contains($bridge,"(string)(\$def['kind']??'field')!=='lookup'"),'Dynamische Calendar-Lookups werden in DataForm-Relationen synchronisiert');
ck123(str_contains($admin,'easyit_calendar_dataform_ensure($p,$syncCtx)'),'Speichern der Calendar-Zuordnung synchronisiert Lookup-Relationen sofort');
ck123(str_contains($records,'$parentRelationsByLookupFieldId')&&str_contains($records,"<select name=\"'.\$esc(\$nameAttr).'\""),'DataForm-Datensatzformular rendert Relationsfelder als select');

require_once $root.'/system/app/EnterpriseCsvPdo.php';
require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
$base=sys_get_temp_dir().'/easyit_hf123_'.bin2hex(random_bytes(5));@mkdir($base,0777,true);
$pdo=new EnterpriseCsvPdo($base,'calendar_lookup');
$ctx=['env'=>[],'project'=>['database_driver'=>'csv','database_name'=>'calendar_lookup'],'config'=>[
 'mappings'=>['events'=>'events','participants'=>'event_participants','persons'=>'persons','places'=>'places','resources'=>'resources','categories'=>'categories','attachments'=>'event_attachments'],
 'field_mapping'=>['source_table'=>'events','fields'=>[
  'id'=>['label'=>'ID','field'=>'id','kind'=>'field','required'=>true],
  'start'=>['label'=>'Start','field'=>'start','kind'=>'field','required'=>true],
  'end'=>['label'=>'Ende','field'=>'end','kind'=>'field','required'=>true],
  'title'=>['label'=>'Titel','field'=>'thema','kind'=>'field','required'=>true],
  'location'=>['label'=>'Ort','field'=>'location','kind'=>'lookup','target_table'=>'places','target_key'=>'id','target_label'=>'name'],
  'resource'=>['label'=>'Ressource','field'=>'resource_id','kind'=>'lookup','target_table'=>'resources','target_key'=>'id','target_label'=>'name'],
  'category'=>['label'=>'Kategorie','field'=>'category_id','kind'=>'lookup','target_table'=>'categories','target_key'=>'id','target_label'=>'name'],
 ]]
]];
easyit_calendar_schema_install($pdo,$ctx);$integration=easyit_calendar_dataform_install($pdo,$ctx);
ck123(empty($integration['warnings']),'CSV Calendar/DataForm-Provisionierung ohne Lookup-Warnung');
$b=(array)$integration['bindings'];$places=(int)$b['places']['id'];
DataFormRecordStore::create($pdo,$places,['name'=>'Raum 101','activ'=>1]);
$relation=$pdo->query("SELECT * FROM dataform_relations WHERE name='calendar_event_place' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck123(is_array($relation)&&(string)$relation['relation_type']==='n:1'&&RelationManager::rendersAsLookup($relation),'events.location ist aktive n:1-Lookup-Relation');
$options=RelationManager::dataformLookupOptions($pdo,$relation?:[]);
ck123(count($options)===1&&($options[0]['caption']??'')==='Raum 101','Termin-Lookup liefert Orte als Auswahloptionen');
$cfg=RelationManager::relationConfiguration($relation?:[]);$cfg['render_as_lookup']=false;
$pdo->prepare('UPDATE dataform_relations SET configuration_json=? WHERE id=?')->execute([json_encode($cfg),(int)$relation['id']]);
easyit_calendar_dataform_ensure($pdo,$ctx);
$relation2=$pdo->query("SELECT * FROM dataform_relations WHERE name='calendar_event_place' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck123(is_array($relation2)&&RelationManager::rendersAsLookup($relation2),'Bestehende fehlerhafte Calendar-Relation wird wieder auf Lookup-Darstellung normalisiert');
$failed=array_values(array_filter($checks,static fn(array $c):bool=>!$c[0]));
echo 'HF123 Calendar dialog/lookup gate: '.($failed?'FAIL':'PASS').' ('.(count($checks)-count($failed)).'/'.count($checks).")\n";
exit($failed?1:0);
