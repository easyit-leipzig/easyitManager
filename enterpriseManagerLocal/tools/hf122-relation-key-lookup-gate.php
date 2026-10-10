<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck122(bool $ok,string $label): void {global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc122(string $p): string {$s=@file_get_contents(dirname(__DIR__).'/'.$p);if($s===false)throw new RuntimeException('Datei fehlt: '.$p);return $s;}
if(!defined('MB_CASE_TITLE')) define('MB_CASE_TITLE',0);
if(!defined('MB_CASE_UPPER')) define('MB_CASE_UPPER',1);
if(!defined('MB_CASE_LOWER')) define('MB_CASE_LOWER',2);
if(!function_exists('mb_strlen')) { function mb_strlen($s,$e=null){return strlen((string)$s);} }
if(!function_exists('mb_substr')) { function mb_substr($s,$st,$len=null,$e=null){return $len===null?substr((string)$s,$st):substr((string)$s,$st,$len);} }
if(!function_exists('mb_strtolower')) { function mb_strtolower($s,$e=null){return strtolower((string)$s);} }
if(!function_exists('mb_convert_case')) { function mb_convert_case($s,$mode,$e=null){return ucwords(strtolower((string)$s));} }

$version=trim(fc122('VERSION'));
$designer=fc122('products/dataform/relations.php');
$manager=fc122('products/dataform/system/RelationManager.php');
$records=fc122('products/dataform/records.php');
ck122(str_contains($version,'HF122-RELATION-KEY-LOOKUP'),'HF122 Buildkennung');
ck122(str_contains($designer,'lookup_target_key_field_id')&&str_contains($designer,'Tabelle → Feld'),'Beziehungsdesigner bietet explizites Tabelle→Feld-Schlüsselfeld');
ck122(str_contains($designer,'render_as_lookup')&&str_contains($designer,'als Lookup darstellen'),'Abhängiges Feld kann als Lookup im Formular definiert werden');
ck122(str_contains($manager,"'target_key_field_id'")&&str_contains($manager,'dataformLookupOptions'),'RelationManager persistiert und nutzt frei wählbaren Zielschlüssel');
ck122(str_contains($records,'dataformLookupOptions')&&str_contains($records,'dataformLookupValueExists'),'Termin-/Datensatzformular nutzt Relation als Lookup mit frei wählbarem Schlüssel');

require_once $root.'/system/app/EnterpriseCsvPdo.php';
require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
$base=sys_get_temp_dir().'/easyit_hf122_'.bin2hex(random_bytes(5));
@mkdir($base,0777,true);
$pdo=new EnterpriseCsvPdo($base,'calendar_lookup');
$ctx=['env'=>[],'project'=>['database_driver'=>'csv','database_name'=>'calendar_lookup'],'config'=>['mappings'=>[
    'events'=>'events','participants'=>'event_participants','persons'=>'persons','places'=>'places',
    'resources'=>'resources','categories'=>'categories','attachments'=>'event_attachments',
]]];
easyit_calendar_schema_install($pdo,$ctx);
$integration=easyit_calendar_dataform_install($pdo,$ctx);
$b=(array)$integration['bindings'];
$eventId=(int)$b['events']['id'];$placeId=(int)$b['places']['id'];
$eventLocation=easyit_calendar_df_field_id($pdo,$eventId,'location');
$placeName=easyit_calendar_df_field_id($pdo,$placeId,'name');
$placeCity=easyit_calendar_df_field_id($pdo,$placeId,'city');
$pdo->prepare('INSERT INTO places (name,city,activ) VALUES (?,?,?)')->execute(['Raum Alpha','Leipzig',1]);
$pdo->prepare('INSERT INTO places (name,city,activ) VALUES (?,?,?)')->execute(['Raum Beta','Dresden',1]);
$rel=$pdo->query("SELECT * FROM dataform_relations WHERE name='calendar_event_place' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ck122(is_array($rel)&&$eventLocation>0&&$placeName>0,'Calendar-Ortsrelation und benötigte Felder vorhanden');
RelationManager::updateManyToOne($pdo,(int)$rel['id'],'calendar_event_place',$eventId,$placeId,$eventLocation,$placeCity,false,$placeName,true);
$rel=$pdo->query("SELECT * FROM dataform_relations WHERE name='calendar_event_place' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$cfg=RelationManager::relationConfiguration($rel?:[]);
ck122((int)($cfg['target_key_field_id']??0)===$placeName && !empty($cfg['render_as_lookup']),'Zielschlüsselfeld und Lookup-Darstellung werden gespeichert');
$options=RelationManager::dataformLookupOptions($pdo,$rel?:[]);
$values=array_column($options,'id');$captions=array_column($options,'caption');
ck122(in_array('Raum Alpha',$values,true)&&in_array('Leipzig',$captions,true),'Lookup speichert frei gewählten Zielschlüssel und zeigt unabhängiges Anzeigefeld');
ck122(RelationManager::dataformLookupValueExists($pdo,$rel?:[],'Raum Beta'),'Lookup-Validierung akzeptiert vorhandenen benutzerdefinierten Schlüssel');
ck122(RelationManager::dataformLookupCaption($pdo,$rel?:[],'Raum Beta')==='Dresden','Lookup-Auflösung liefert konfigurierten sichtbaren Wert');
$health=RelationManager::relationHealth($pdo,$rel?:[]);
ck122(!empty($health['valid']),'Relation mit explizitem Zielschlüsselfeld ist gesund');

$failed=array_values(array_filter($checks,static fn(array $c):bool=>!$c[0]));
echo 'HF122 Relation key/lookup gate: '.($failed?'FAIL':'PASS').' ('.(count($checks)-count($failed)).'/'.count($checks).")\n";
exit($failed?1:0);
