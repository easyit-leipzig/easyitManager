<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$checks=[];
function ck121(bool $ok,string $label): void {global $checks;$checks[]=[$ok,$label];echo ($ok?'PASS':'FAIL')." - {$label}\n";}
function fc121(string $path): string {$s=@file_get_contents(dirname(__DIR__).'/'.$path);if($s===false)throw new RuntimeException('Datei fehlt: '.$path);return $s;}

$version=trim(fc121('VERSION'));
$config=fc121('system/app/calendar_project.php');
$runtime=fc121('products/calendar/api/runtime.php');
$admin=fc121('products/calendar/admin.php');
$api=fc121('products/calendar/api/admin_handler.php');
$handler=fc121('products/calendar/api/calendar_handler.php');

ck121(str_contains($version,'HF121-DYNAMIC-CALENDAR-MAPPING'),'HF121 Buildkennung');
ck121(str_contains($config,'enterprise_calendar_default_field_mapping')&&str_contains($config,"'location'=>['label'=>'Ort'")&&str_contains($config,"'target_table'=>'places'"),'Default-Mapping enthält erweiterbare Lookup-Zuordnungen');
ck121(str_contains($config,"'version'=>4"),'Calendar-Konfiguration ist auf Version 4 angehoben');
ck121(str_contains($admin,'+ Zuordnung hinzufügen')&&str_contains($admin,'Lookup / Relation')&&str_contains($admin,'mappingRowsState'),'Calendar-Zuordnungsoberfläche ist dynamisch erweiterbar');
ck121(str_contains($api,'save_mapping')&&str_contains($api,'target_table')&&str_contains($api,'Pflichtzuordnung fehlt'),'Admin-API validiert dynamische Felder und Lookup-Ziele');
ck121(str_contains($api,"mappings']['events"),'Gespeicherte Quelltabelle wird zur aktiven Calendar-Eventtabelle');
ck121(str_contains($runtime,'function easyit_calendar_field_mapping')&&str_contains($runtime,'function easyit_calendar_field_name'),'Runtime besitzt normalisierten dynamischen Mapping-Vertrag');
ck121(str_contains($handler,"'start','start'")&&str_contains($handler,"'title','thema'")&&str_contains($handler,"'location','location'"),'Calendar-Handler verwendet Zuordnungen statt feste Kernfeldnamen');

require_once $root.'/system/app/calendar_project.php';
require_once $root.'/products/calendar/api/runtime.php';
$project=['id'=>7,'database_driver'=>'csv','database_name'=>'calendar_x'];
$def=enterprise_calendar_default_config($project);
ck121(($def['field_mapping']['fields']['location']['target_table']??'')==='places'&&($def['field_mapping']['fields']['resource']['target_table']??'')==='resources','Neue Projekte erhalten Ort/Ressource als Lookup-Mapping');

$ctx=['env'=>[],'project'=>$project,'config'=>['field_mapping'=>[
  'source_table'=>'events','fields'=>[
    'id'=>['label'=>'ID','field'=>'id','kind'=>'field','required'=>true],
    'start'=>['label'=>'Beginn','field'=>'begin_at','kind'=>'field','required'=>true],
    'end'=>['label'=>'Ende','field'=>'finish_at','kind'=>'field','required'=>true],
    'title'=>['label'=>'Titel','field'=>'subject','kind'=>'field','required'=>true],
    'location'=>['label'=>'Ort','field'=>'place_id','kind'=>'lookup','target_table'=>'places','target_key'=>'id','target_label'=>'name'],
    'custom_flag'=>['label'=>'Sonderfeld','field'=>'flag','kind'=>'field'],
  ],
]]];
$m=easyit_calendar_field_mapping($ctx);
ck121(($m['fields']['start']['field']??'')==='begin_at'&&($m['fields']['location']['target_label']??'')==='name'&&isset($m['fields']['custom_flag']),'Explizite benutzerdefinierte Zuordnungen werden vollständig erhalten');
ck121(!isset($m['fields']['description'])&&!isset($m['fields']['category']),'Entfernte optionale Standardzuordnungen werden nicht automatisch wieder hinzugefügt');

$legacy=['env'=>[],'project'=>$project,'config'=>['field_mapping'=>['source_table'=>'events','id_field'=>'id','start_field'=>'starts_at','end_field'=>'ends_at','title_field'=>'caption','description_field'=>'','active_field'=>'enabled']]];
$lm=easyit_calendar_field_mapping($legacy);
ck121(($lm['fields']['start']['field']??'')==='starts_at'&&($lm['fields']['title']['field']??'')==='caption'&&($lm['fields']['active']['field']??'')==='enabled','Legacy-Mapping bleibt lesbar');

$failed=array_values(array_filter($checks,static fn(array $c):bool=>!$c[0]));
echo 'HF121 Dynamic Calendar Mapping gate: '.($failed?'FAIL':'PASS').' ('.(count($checks)-count($failed)).'/'.count($checks).")\n";
exit($failed?1:0);
