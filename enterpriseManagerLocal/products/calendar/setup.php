<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/system/app/bootstrap.php';
require_once $root.'/system/ui/layout.php';
require_once $root.'/system/app/calendar_project.php';
require_once __DIR__.'/api/runtime.php';
require_once __DIR__.'/system/CalendarDataFormBridge.php';
$user=enterprise_require_auth('../../');
$id=(int)($_GET['project']??$_POST['project']??0);
enterprise_require_project_scope($user,$id,false);
$admin=enterprise_pdo();enterprise_upgrade($admin);$st=$admin->prepare('SELECT * FROM projects WHERE id=?');$st->execute([$id]);$project=$st->fetch(PDO::FETCH_ASSOC);
if(!$project||(string)$project['product_type']!=='calendar'){http_response_code(404);exit('Calendar-Projekt nicht gefunden.');}
enterprise_require_project_product($user,$id,'calendar',(string)$project['slug']);
$env=enterprise_env($root.'/DataForm5-Core/.env');$error='';$ok='';$warnings=[];$cfg=enterprise_calendar_config_load($project);
$_SESSION['easyit_calendar_project_id']=$id;

try{
    $ctx=easyit_calendar_project_context();$cpdo=enterprise_project_store_for_project($env,$project);
    // HF105: bestehende Calendar-Projekte werden beim ersten Setup-Aufruf selbst migriert.
    $integration=easyit_calendar_dataform_ensure($cpdo,$ctx);
    foreach((array)$integration['bindings'] as $logical=>$binding)$cfg['dataforms'][$logical]=(int)($binding['id']??0);
    if((int)($cfg['term_dialog']['dataform_id']??0)<1)$cfg['term_dialog']['dataform_id']=(int)($cfg['dataforms']['events']??0);
    $cfg['term_dialog']['use_dataform']=true;
    enterprise_calendar_config_save($project,$cfg);
    $warnings=(array)($integration['warnings']??[]);
}catch(Throwable $e){$error=$e->getMessage();$cpdo=null;$ctx=null;}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_check_csrf((string)($_POST['csrf']??''));enterprise_require_project_scope($user,$id,true);
        $action=(string)($_POST['action']??'save');
        if($action==='initialize_schema'){
            if(!$cpdo||!$ctx)throw new RuntimeException('Projekt-Datenquelle ist nicht verfügbar.');
            $integration=easyit_calendar_dataform_install($cpdo,$ctx);
            foreach((array)$integration['bindings'] as $logical=>$binding)$cfg['dataforms'][$logical]=(int)($binding['id']??0);
            $cfg['term_dialog']['dataform_id']=(int)($cfg['dataforms']['events']??0);$cfg['term_dialog']['use_dataform']=true;
            enterprise_calendar_config_save($project,$cfg);$warnings=(array)($integration['warnings']??[]);
            $ok='Calendar-Schema und DataForm-Basiskonfiguration wurden vollständig geprüft/initialisiert.';
        }else{
            $cfg['data_source']=['driver'=>(string)$project['database_driver'],'database'=>(string)$project['database_name']];
            $cfg['calendar']=['timezone'=>trim((string)($_POST['timezone']??'Europe/Berlin')),'week_start'=>(int)($_POST['week_start']??1),'workday_start'=>(string)($_POST['workday_start']??'08:00'),'workday_end'=>(string)($_POST['workday_end']??'18:00'),'slot_minutes'=>(int)($_POST['slot_minutes']??30),'default_view'=>(string)($_POST['default_view']??'week'),'weekends'=>isset($_POST['weekends'])];
            foreach(array_keys($cfg['mappings']) as $k)$cfg['mappings'][$k]=preg_replace('/[^A-Za-z0-9_.-]/','',trim((string)($_POST['map_'.$k]??$cfg['mappings'][$k])));
            $cfg['maintenance']=['soft_delete'=>isset($_POST['soft_delete']),'audit'=>isset($_POST['audit']),'attachments'=>isset($_POST['attachments'])];
            $cfg['term_dialog']=['dataform_id'=>max(0,(int)($_POST['term_dialog_dataform_id']??($cfg['dataforms']['events']??0))),'mode'=>'dialog','use_dataform'=>true,'close_after_save'=>isset($_POST['term_dialog_close_after_save'])];
            $cfg['delivery']=['include_csv_engine'=>(string)$project['database_driver']==='csv','include_help'=>isset($_POST['include_help']),'include_migrations'=>isset($_POST['include_migrations'])];
            enterprise_calendar_config_save($project,$cfg);$ok='Calendar/DataForm-Setup wurde gespeichert.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}
$status=enterprise_calendar_setup_status($project,$env);$cfg=enterprise_calendar_config_load($project);$schema=['ready'=>false,'tables'=>[]];$bindings=[];
try{if($cpdo&&$ctx){$schema=easyit_calendar_schema_status($cpdo,$ctx);$bindings=easyit_calendar_dataform_bindings($cpdo,$ctx);}}catch(Throwable $e){if(!$error)$error=$e->getMessage();}
function ck(bool $v):string{return $v?' checked':'';}
ob_start();
render_breadcrumbs([['label'=>'Projekte','href'=>'../../app/projects/index.php'],['label'=>(string)$project['name'],'href'=>'../../app/projects/view.php?id='.$id],['label'=>'Calendar + DataForm Setup','href'=>'']]);
?>
<section class="hero"><span class="badge">easyITCalendar + DataForm</span><h1>Gemeinsames Projekt-Setup</h1><p>Calendar ist die Visualisierung; Tabellen, Keys, Felder, Relationen, CRUD und der Termindialog werden vollständig von DataForm getragen.</p></section>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?><?php if($ok):?><div class="notice success"><?=e($ok)?></div><?php endif;?>
<?php if($warnings):?><div class="notice warning"><strong>Hinweise aus der Integration</strong><br><?=e(implode(' | ',$warnings))?></div><?php endif;?>
<section class="card"><h2>Integrationsstatus</h2><div class="status-list"><div><dt>Calendar-Schema</dt><dd><?=$schema['ready']?'READY':'FEHLT'?></dd></div><div><dt>DataForm-Bindings</dt><dd><?=count(array_filter($bindings,fn($b)=>(int)($b['id']??0)>0))?> / <?=count(easyit_calendar_dataform_specs($ctx))?></dd></div><div><dt>Termindialog</dt><dd><?=((int)($cfg['term_dialog']['dataform_id']??0)>0)?'DATAFORM #'.(int)$cfg['term_dialog']['dataform_id']:'FEHLT'?></dd></div></div></section>
<form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="project" value="<?=$id?>">
<section class="card"><h2>Datenquelle und Grundstruktur</h2><p>Calendar und DataForm verwenden dieselbe Projektdatenquelle. Beim Initialisieren werden Calendar-Tabellen, DataForm-Metadaten, Standardformulare und Basisrelationen gemeinsam provisioniert.</p><dl class="status-list"><div><dt>Treiber</dt><dd><code><?=e((string)$project['database_driver'])?></code></dd></div><div><dt>Datenbank/Store</dt><dd><code><?=e((string)$project['database_name'])?></code></dd></div></dl><div class="actions"><a class="button secondary" href="../../app/projects/edit.php?id=<?=$id?>">Datenquelle ändern</a><button class="button" type="submit" name="action" value="initialize_schema">Calendar + DataForm prüfen/initialisieren</button></div></section>
<section class="card"><h2>DataForm-Datenpflege</h2><p>Eine separate Calendar-Datenpflege existiert nicht mehr. Jeder Calendar-Datenbereich ist ein gebundenes DataForm und verwendet damit dieselbe CRUD-, Validierungs-, Feld- und Ereignislogik.</p><div class="actions">
<?php foreach(['events'=>'Termine','persons'=>'Personen','places'=>'Orte','resources'=>'Ressourcen','categories'=>'Kategorien','roles'=>'Rollen','participants'=>'Teilnehmer','attachments'=>'Anhänge'] as $logical=>$label): $df=(int)($bindings[$logical]['id']??0); ?>
<?php if($df>0):?><a class="button secondary" href="../dataform/records.php?project=<?=$id?>&amp;dataform=<?=$df?>"><?=e($label)?> pflegen</a><?php endif;?>
<?php endforeach;?></div><hr><label><input type="checkbox" name="soft_delete"<?=ck((bool)$cfg['maintenance']['soft_delete'])?>> Termine logisch deaktivieren</label><br><label><input type="checkbox" name="audit"<?=ck((bool)$cfg['maintenance']['audit'])?>> Änderungen protokollieren</label><br><label><input type="checkbox" name="attachments"<?=ck((bool)$cfg['maintenance']['attachments'])?>> Anlagen zulassen</label></section>
<section class="card"><h2>Tabellen, Keys, Felder und Relationen</h2><p>Alle technischen Einstellungen erfolgen im DataForm-Konfigurator des Calendar-Projekts. Dort stehen Tabellenverwaltung, Feldtypen, Pflichtfelder, Read-only, Suche/Filter, DataForm-Events, <code>addClass</code>/<code>addJavaScript</code> und Relationen zur Verfügung.</p><div class="actions"><a class="button" href="../dataform/runtime.php?project=<?=$id?>&amp;section=tables">Tabellen / Keys</a><a class="button secondary" href="../dataform/runtime.php?project=<?=$id?>&amp;section=dataforms">DataForms</a><a class="button secondary" href="../dataform/relations.php?project=<?=$id?>">Relationen</a></div></section>
<section class="card"><h2>Termindialog – verbindlich DataForm</h2><p>Neu, Öffnen und Bearbeiten eines Termins verwenden ausschließlich das an <code>events</code> gebundene DataForm als Einzeldatensatzansicht. Der frühere fest codierte Calendar-Dialog ist entfernt. Konfigurierbar sind Felder, Layout, Regeln, Events und Darstellung – die Datenbindung selbst bleibt eindeutig.</p><?php $term=(int)($bindings['events']['id']??0);?><input type="hidden" name="term_dialog_dataform_id" value="<?=$term?>"><dl class="status-list"><div><dt>Termin-DataForm</dt><dd><?=$term>0?'#'.$term.' · '.e((string)($bindings['events']['name']??'Calendar – Termine')):'FEHLT'?></dd></div></dl><label><input type="checkbox" name="term_dialog_close_after_save"<?=ck((bool)($cfg['term_dialog']['close_after_save']??true))?>> Dialog nach erfolgreichem Speichern schließen</label><?php if($term>0):?><div class="actions"><a class="button secondary" href="../dataform/runtime.php?project=<?=$id?>&amp;section=dataform&amp;dataform=<?=$term?>">Terminformular konfigurieren</a><a class="button secondary" href="../dataform/records.php?project=<?=$id?>&amp;dataform=<?=$term?>&amp;mode=create&amp;runtime_view=form">Terminformular testen</a></div><?php endif;?></section>
<section class="card"><h2>Tabellenzuordnungen</h2><?php foreach($cfg['mappings'] as $k=>$v):?><label style="display:block;margin:.7rem 0"><?=e(ucfirst($k))?><input name="map_<?=e($k)?>" value="<?=e((string)$v)?>"></label><?php endforeach;?><p><small>Nach Änderungen an physischen Zuordnungen anschließend „Calendar + DataForm prüfen/initialisieren“ ausführen.</small></p></section>
<section class="card"><h2>Kalender</h2><label>Zeitzone <input name="timezone" value="<?=e((string)$cfg['calendar']['timezone'])?>"></label> <label>Wochenbeginn <select name="week_start"><option value="1"<?=((int)$cfg['calendar']['week_start']===1?' selected':'')?>>Montag</option><option value="0"<?=((int)$cfg['calendar']['week_start']===0?' selected':'')?>>Sonntag</option></select></label><br><label>Arbeitsbeginn <input type="time" name="workday_start" value="<?=e((string)$cfg['calendar']['workday_start'])?>"></label> <label>Arbeitsende <input type="time" name="workday_end" value="<?=e((string)$cfg['calendar']['workday_end'])?>"></label> <label>Raster <select name="slot_minutes"><?php foreach([15,30,60] as $m):?><option value="<?=$m?>"<?=((int)$cfg['calendar']['slot_minutes']===$m?' selected':'')?>><?=$m?> min</option><?php endforeach;?></select></label><br><label>Standardansicht <select name="default_view"><?php foreach(['day'=>'Tag','week'=>'Woche','month'=>'Monat'] as $v=>$l):?><option value="<?=$v?>"<?=($cfg['calendar']['default_view']===$v?' selected':'')?>><?=$l?></option><?php endforeach;?></select></label> <label><input type="checkbox" name="weekends"<?=ck((bool)$cfg['calendar']['weekends'])?>> Wochenenden anzeigen</label></section>
<section class="card"><h2>Auslieferung</h2><?php if((string)$project['database_driver']==='csv'):?><p><strong>CSV-Engine ist Pflichtabhängigkeit</strong> und wird automatisch mit dem Calendar-Paket ausgeliefert.</p><?php endif;?><label><input type="checkbox" name="include_help"<?=ck((bool)$cfg['delivery']['include_help'])?>> Hilfe mitliefern</label><br><label><input type="checkbox" name="include_migrations"<?=ck((bool)$cfg['delivery']['include_migrations'])?>> Schema/Migrationen mitliefern</label></section>
<div class="actions"><button class="button" type="submit" name="action" value="save">Setup speichern</button><a class="button secondary" href="index.php?project=<?=$id?>">Calendar öffnen</a></div></form>
<?php $content=ob_get_clean();render_page(['title'=>'Calendar + DataForm Setup','active'=>'projects','content'=>$content,'user'=>$user,'app_nav'=>true,'base'=>'../../']);
