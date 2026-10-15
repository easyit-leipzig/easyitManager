<?php
declare(strict_types=1);

/** HF105: Calendar visualisiert; DataForm ist die gemeinsame Daten-/Formularschicht. */
require_once dirname(__DIR__,3).'/system/app/project_store.php';
require_once dirname(__DIR__,2).'/calendar/api/runtime.php';
require_once dirname(__DIR__,2).'/dataform/system/TableWorkspaceManager.php';
require_once dirname(__DIR__,2).'/dataform/system/DataFormManager.php';
require_once dirname(__DIR__,2).'/dataform/system/RelationManager.php';
require_once dirname(__DIR__,2).'/dataform/system/DataFormRecordStore.php';

/** HF164: A dedicated read-only tutor lookup source. The Calendar keeps
 * persons normalized; "Calendar – Tutoren" is therefore a filtered projection
 * of persons instead of a second, duplicated master-data table.
 */
function easyit_calendar_ensure_tutors_view(PDO $pdo,array $ctx): string {
    $driver=easyit_calendar_driver($ctx);
    $persons=easyit_calendar_table($ctx,'persons');
    $view='calendar_tutors';
    if($driver!=='mysql') return $view; // current Enterprise/XAMPP path; other engines remain unchanged
    $q=static fn(string $n): string=>'`'.str_replace('`','``',$n).'`';
    $hasPersonRoles=false;$hasRoles=false;
    try{
        $st=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $st->execute(['person_roles']);$hasPersonRoles=(int)$st->fetchColumn()>0;
        $st->execute(['roles']);$hasRoles=(int)$st->fetchColumn()>0;
    }catch(Throwable){}
    if($hasPersonRoles&&$hasRoles){
        $sql='CREATE OR REPLACE VIEW '.$q($view).' AS '
            .'SELECT DISTINCT p.id,p.display_name,p.email,p.calendar_color,p.activ,p.created_at,p.updated_at '
            .'FROM '.$q($persons).' p '
            .'JOIN '.$q('person_roles').' pr ON pr.person_id=p.id AND COALESCE(pr.activ,1)=1 '
            .'JOIN '.$q('roles').' r ON r.id=pr.role_id '
            ."WHERE r.role_key='tutor' AND COALESCE(p.activ,1)=1";
    }else{
        // Compatibility fallback for reduced Calendar schemas without person_roles:
        // every active person is selectable until role assignment is available.
        $sql='CREATE OR REPLACE VIEW '.$q($view).' AS '
            .'SELECT p.id,p.display_name,p.email,p.calendar_color,p.activ,p.created_at,p.updated_at '
            .'FROM '.$q($persons).' p WHERE COALESCE(p.activ,1)=1';
    }
    $pdo->exec($sql);
    return $view;
}

function easyit_calendar_dataform_specs(array $ctx): array {
    return [
        'events'=>['table'=>easyit_calendar_table($ctx,'events'),'name'=>'Calendar – Termine','view'=>'dialog'],
        'participants'=>['table'=>easyit_calendar_table($ctx,'participants'),'name'=>'Calendar – Teilnehmer','view'=>'table'],
        'persons'=>['table'=>easyit_calendar_table($ctx,'persons'),'name'=>'Calendar – Personen','view'=>'table'],
        'tutors'=>['table'=>'calendar_tutors','name'=>'Calendar – Tutoren','view'=>'table','readonly'=>true],
        'places'=>['table'=>easyit_calendar_table($ctx,'places'),'name'=>'Calendar – Orte','view'=>'table'],
        'resources'=>['table'=>easyit_calendar_table($ctx,'resources'),'name'=>'Calendar – Ressourcen','view'=>'table'],
        'categories'=>['table'=>easyit_calendar_table($ctx,'categories'),'name'=>'Calendar – Kategorien','view'=>'table'],
        'attachments'=>['table'=>easyit_calendar_table($ctx,'attachments'),'name'=>'Calendar – Anhänge','view'=>'table'],
        'blocks'=>['table'=>'blocked_ranges','name'=>'Calendar – Blockierungen','view'=>'table'],
        'roles'=>['table'=>'roles','name'=>'Calendar – Rollen','view'=>'table'],
    ];
}
function easyit_calendar_dataform_bindings(PDO $pdo,array $ctx): array {
    DataFormManager::ensureTableBindingSchema($pdo); $out=[];
    $stmt=$pdo->prepare("SELECT b.dataform_id,d.name,d.slug FROM dataform_table_bindings b JOIN dataforms d ON d.id=b.dataform_id WHERE b.source_kind='system' AND b.source_id=0 AND b.table_name=? LIMIT 1");
    foreach(easyit_calendar_dataform_specs($ctx) as $logical=>$spec){$stmt->execute([(string)$spec['table']]);$row=$stmt->fetch(PDO::FETCH_ASSOC);$out[$logical]=$row?['id'=>(int)$row['dataform_id'],'name'=>(string)$row['name'],'slug'=>(string)$row['slug'],'table'=>(string)$spec['table']]:['id'=>0,'name'=>(string)$spec['name'],'slug'=>'','table'=>(string)$spec['table']];}
    return $out;
}
function easyit_calendar_dataform_id(PDO $pdo,array $ctx,string $logical): int { $a=easyit_calendar_dataform_bindings($pdo,$ctx); return (int)($a[$logical]['id']??0); }
function easyit_calendar_df_field_id(PDO $pdo,int $dataformId,string $name): int {$st=$pdo->prepare('SELECT id FROM dataform_fields WHERE dataform_id=? AND name=? LIMIT 1');$st->execute([$dataformId,$name]);return (int)($st->fetchColumn()?:0);}
function easyit_calendar_df_field_profile(PDO $pdo,int $dataformId,string $name,array $profile): void {
    $id=easyit_calendar_df_field_id($pdo,$dataformId,$name);if($id<1)return;
    $st=$pdo->prepare('SELECT configuration_json FROM dataform_fields WHERE id=?');$st->execute([$id]);$cfg=json_decode((string)($st->fetchColumn()?:'{}'),true);if(!is_array($cfg))$cfg=[];$cfg=array_replace($cfg,(array)($profile['config']??[]));
    $pdo->prepare('UPDATE dataform_fields SET label=?,field_type=?,position=?,is_required=?,configuration_json=? WHERE id=?')->execute([(string)($profile['label']??$name),(string)($profile['type']??'text'),(int)($profile['position']??100),!empty($profile['required'])?1:0,json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);
}
function easyit_calendar_df_relation_exists(PDO $pdo,string $name): bool {try{$st=$pdo->prepare('SELECT COUNT(*) FROM dataform_relations WHERE name=?');$st->execute([$name]);return (int)$st->fetchColumn()>0;}catch(Throwable){return false;}}
function easyit_calendar_df_relation_by_name(PDO $pdo,string $name): ?array {
    try{$st=$pdo->prepare('SELECT * FROM dataform_relations WHERE name=? LIMIT 1');$st->execute([$name]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null;}catch(Throwable){return null;}
}

/** HF163: Core calendar lookups must remain valid even after a database/dataform
 * rebuild. Relation names alone are not sufficient because their old IDs can
 * point to former DataForms/fields. Rebind every core n:1 lookup to the current
 * field IDs and persist the same target information in the field configuration
 * as a runtime fallback.
 */
function easyit_calendar_force_lookup(PDO $pdo,string $relationName,int $sourceDf,int $targetDf,int $sourceFieldId,int $displayFieldId,bool $required=false): void {
    if($sourceDf<1||$targetDf<1||$sourceFieldId<1||$displayFieldId<1)return;
    $existing=easyit_calendar_df_relation_by_name($pdo,$relationName);
    if($existing) RelationManager::updateManyToOne($pdo,(int)$existing['id'],$relationName,$sourceDf,$targetDf,$sourceFieldId,$displayFieldId,$required,0,true);
    else RelationManager::createManyToOne($pdo,$relationName,$sourceDf,$targetDf,$sourceFieldId,$displayFieldId,$required,0,true);
    $st=$pdo->prepare('SELECT configuration_json FROM dataform_fields WHERE id=? AND dataform_id=? LIMIT 1');
    $st->execute([$sourceFieldId,$sourceDf]);
    $cfg=json_decode((string)($st->fetchColumn()?:'{}'),true); if(!is_array($cfg))$cfg=[];
    $cfg['lookup_target_dataform_id']=$targetDf;
    $cfg['lookup_target_key_field_id']=0;
    $cfg['lookup_target_display_field_id']=$displayFieldId;
    $pdo->prepare("UPDATE dataform_fields SET field_type='lookup',configuration_json=? WHERE id=? AND dataform_id=?")
        ->execute([json_encode($cfg,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$sourceFieldId,$sourceDf]);
}
function easyit_calendar_rebind_core_lookups(PDO $pdo,array $ctx,array $bindings): void {
    $events=(int)($bindings['events']['id']??0); $places=(int)($bindings['places']['id']??0); $resources=(int)($bindings['resources']['id']??0); $categories=(int)($bindings['categories']['id']??0);
    if($events<1)return;
    $location=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'location','location'));
    $resource=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'resource','resource_id'));
    $category=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'category','category_id'));
    $placeDisplay=easyit_calendar_df_field_id($pdo,$places,'name');
    $resourceDisplay=easyit_calendar_df_field_id($pdo,$resources,'name');
    $categoryDisplay=easyit_calendar_df_field_id($pdo,$categories,'name');
    easyit_calendar_force_lookup($pdo,'calendar_event_place',$events,$places,$location,$placeDisplay,false);
    easyit_calendar_force_lookup($pdo,'calendar_event_resource',$events,$resources,$resource,$resourceDisplay,false);
    easyit_calendar_force_lookup($pdo,'calendar_event_category',$events,$categories,$category,$categoryDisplay,false);
}
function easyit_calendar_df_for_table(PDO $pdo,string $table): int {
    try{
        $st=$pdo->prepare("SELECT b.dataform_id FROM dataform_table_bindings b JOIN dataforms d ON d.id=b.dataform_id WHERE b.table_name=? AND b.source_kind='system' AND b.source_id=0 LIMIT 1");
        $st->execute([$table]);return (int)($st->fetchColumn()?:0);
    }catch(Throwable){return 0;}
}
/**
 * HF123: Calendar field mappings are authoritative for dialog lookups. Every
 * mapping with kind=lookup is materialized as an active n:1 DataForm relation,
 * so the generic DataForm record editor renders a <select> automatically.
 */
function easyit_calendar_sync_mapping_lookup_relations(PDO $pdo,array $ctx): array {
    $warnings=[];$synced=[];
    $mapping=easyit_calendar_field_mapping($ctx);
    $sourceTable=trim((string)($mapping['source_table']??easyit_calendar_table($ctx,'events')));
    $sourceDataform=easyit_calendar_df_for_table($pdo,$sourceTable);
    if($sourceDataform<1){$sourceDataform=easyit_calendar_dataform_id($pdo,$ctx,'events');}
    if($sourceDataform<1)return ['synced'=>[],'warnings'=>['Termin-DataForm für Lookup-Synchronisierung fehlt.']];
    $canonical=['location'=>'calendar_event_place','resource'=>'calendar_event_resource','category'=>'calendar_event_category'];
    foreach((array)($mapping['fields']??[]) as $logical=>$def){
        if(!is_array($def)||(string)($def['kind']??'field')!=='lookup')continue;
        $sourceFieldName=trim((string)($def['field']??''));$targetTable=trim((string)($def['target_table']??''));$targetKey=trim((string)($def['target_key']??''));$targetLabel=trim((string)($def['target_label']??''));
        if($sourceFieldName===''||$targetTable===''||$targetKey===''||$targetLabel==='')continue;
        $sourceFieldId=easyit_calendar_df_field_id($pdo,$sourceDataform,$sourceFieldName);
        if($sourceFieldId<1){$warnings[]='Lookup '.$logical.': Quellfeld '.$sourceFieldName.' ist im Termin-DataForm nicht gebunden.';continue;}
        $safeLogical=preg_replace('/[^a-z0-9_]+/','_',strtolower((string)$logical))?:'lookup';
        $relationName=$canonical[(string)$logical]??('calendar_mapping_'.$safeLogical);
        $existing=easyit_calendar_df_relation_by_name($pdo,$relationName);
        $targetDataform=easyit_calendar_df_for_table($pdo,$targetTable);
        try{
            if($targetDataform>0){
                $displayFieldId=easyit_calendar_df_field_id($pdo,$targetDataform,$targetLabel);
                $keyFieldId=$targetKey==='id'?0:easyit_calendar_df_field_id($pdo,$targetDataform,$targetKey);
                // If a non-ID physical key is not represented as DataForm field,
                // keep the mapping exact by using the base-table lookup adapter.
                if($displayFieldId>0 && ($targetKey==='id'||$keyFieldId>0)){
                    if($existing) RelationManager::updateManyToOne($pdo,(int)$existing['id'],$relationName,$sourceDataform,$targetDataform,$sourceFieldId,$displayFieldId,!empty($def['required']),$keyFieldId,true);
                    else RelationManager::createManyToOne($pdo,$relationName,$sourceDataform,$targetDataform,$sourceFieldId,$displayFieldId,!empty($def['required']),$keyFieldId,true);
                    $synced[]=$relationName;continue;
                }
            }
            if($existing) RelationManager::updateManyToOneBaseTable($pdo,(int)$existing['id'],$relationName,$sourceDataform,$sourceFieldId,$targetTable,$targetKey,$targetLabel,!empty($def['required']));
            else RelationManager::createManyToOneBaseTable($pdo,$relationName,$sourceDataform,$sourceFieldId,$targetTable,$targetKey,$targetLabel,!empty($def['required']));
            $synced[]=$relationName;
        }catch(Throwable $e){$warnings[]='Lookup '.$logical.': '.$e->getMessage();}
    }
    return ['synced'=>$synced,'warnings'=>$warnings];
}

/**
 * Calendar owns its generated DataForms as an embedded product layer.
 * They are not individual DataForm-license slots and therefore must never
 * enter the central DataForm registration queue.
 */
function easyit_calendar_dataform_mark_embedded(PDO $pdo,array $ctx): int {
    $changed=0;
    foreach(easyit_calendar_dataform_bindings($pdo,$ctx) as $binding){
        $id=(int)($binding['id']??0); if($id<1) continue;
        $st=$pdo->prepare("UPDATE dataforms
            SET license_status='embedded_calendar',
                license_registered_at=NULL,
                license_token=NULL,
                license_token_key_id=NULL,
                license_token_issued_at=NULL
            WHERE id=?");
        $st->execute([$id]); $changed += $st->rowCount();
    }
    return $changed;
}

function easyit_calendar_ensure_event_extensions(PDO $pdo,array $ctx): array {
    $table=easyit_calendar_table($ctx,'events');$warnings=[];$defs=[];
    $mapped=[
      'all_day'=>['integer',false,'literal','0'],
      'location'=>['bigint',true,'none',null],
      'resource'=>['bigint',true,'none',null],
      'category'=>['bigint',true,'none',null],
    ];
    foreach($mapped as $logical=>$def){$name=easyit_calendar_field_name($ctx,$logical,'');if($name!=='')$defs[$name]=$def;}
    try{
        if(method_exists($pdo,'columnNames')&&method_exists($pdo,'addDataColumn')){$cols=(array)$pdo->columnNames($table);foreach($defs as $name=>$def)if(!in_array($name,$cols,true))$pdo->addDataColumn($table,$name);return $warnings;}
        $rows=$pdo->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);$cols=[];foreach($rows as $row)$cols[]=(string)($row['Field']??$row['field']??$row['column_name']??'');
        foreach($defs as $name=>$def)if(!in_array($name,$cols,true))TableWorkspaceManager::addManagedColumn($pdo,$table,$name,$def[0],$def[1],$def[2],$def[3]);
    }catch(Throwable $e){$warnings[]='Terminerweiterungen: '.$e->getMessage();}
    return $warnings;
}

/**
 * HF120: legacy free-text values in events.location are converted into
 * real places records. Afterwards events.location stores places.id.
 */
function easyit_calendar_migrate_event_locations(PDO $pdo,array $ctx,array $bindings): array {
    $events=(int)($bindings['events']['id']??0);
    $places=(int)($bindings['places']['id']??0);
    if($events<1||$places<1)return ['created_places'=>0,'migrated_events'=>0];

    $placeIds=[];$placeByName=[];
    foreach(DataFormRecordStore::all($pdo,$places) as $row){
        $id=(int)($row['id']??0);$data=is_array($row['data']??null)?$row['data']:[];
        if($id>0)$placeIds[$id]=true;
        $name=trim((string)($data['name']??''));
        if($name!==''){$key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);$placeByName[$key]=$id;}
    }

    $created=0;$migrated=0;$now=date('Y-m-d H:i:s');
    foreach(DataFormRecordStore::all($pdo,$events) as $row){
        $eventId=(int)($row['id']??0);$data=is_array($row['data']??null)?$row['data']:[];
        $locationField=easyit_calendar_field_name($ctx,'location','location');$raw=trim((string)($data[$locationField]??''));
        if($eventId<1||$raw==='')continue;
        if(ctype_digit($raw)&&isset($placeIds[(int)$raw]))continue;

        $key=function_exists('mb_strtolower')?mb_strtolower($raw,'UTF-8'):strtolower($raw);
        $placeId=(int)($placeByName[$key]??0);
        if($placeId<1){
            $placeId=DataFormRecordStore::create($pdo,$places,[
                'name'=>$raw,'activ'=>1,'created_at'=>$now,'updated_at'=>$now,
            ]);
            $placeIds[$placeId]=true;$placeByName[$key]=$placeId;$created++;
        }
        DataFormRecordStore::update($pdo,$events,$eventId,[$locationField=>$placeId]);
        $migrated++;
    }
    return ['created_places'=>$created,'migrated_events'=>$migrated];
}

function easyit_calendar_dataform_install(PDO $pdo,array $ctx): array {
    $root=dirname(__DIR__,3);$schema=enterprise_project_store_install_schema($pdo,$root.'/installer/schema/project');$calendarSchema=easyit_calendar_schema_install($pdo,$ctx);
    TableWorkspaceManager::ensureRegistry($pdo);DataFormManager::ensureTableBindingSchema($pdo);RelationManager::ensureSchema($pdo);$created=[];$warnings=[];
    try{easyit_calendar_ensure_tutors_view($pdo,$ctx);}catch(Throwable $e){$warnings[]='Tutoren-Ansicht: '.$e->getMessage();}
    foreach(easyit_calendar_dataform_specs($ctx) as $logical=>$spec){$table=(string)$spec['table'];
        try{$chk=$pdo->prepare('SELECT COUNT(*) FROM dataform_managed_tables WHERE table_name=?');$chk->execute([$table]);if((int)$chk->fetchColumn()===0)$pdo->prepare('INSERT INTO dataform_managed_tables(table_name) VALUES(?)')->execute([$table]);}catch(Throwable $e){$warnings[]='Registry '.$table.': '.$e->getMessage();}
        if($logical==='events')$warnings=array_merge($warnings,easyit_calendar_ensure_event_extensions($pdo,$ctx));
        try{$r=DataFormManager::createFromManagedTable($pdo,$table,(string)$spec['name']);$created[$logical]=$r;$id=(int)$r['dataform_id'];$ro=!empty($spec['readonly']);$pdo->prepare('UPDATE dataforms SET status=?,view_mode=?,allow_create=?,allow_edit=?,allow_delete=?,show_search=1,show_pagination=1 WHERE id=?')->execute(['active',(string)$spec['view'],$ro?0:1,$ro?0:1,$ro?0:1,$id]);}catch(Throwable $e){$warnings[]='DataForm '.$table.': '.$e->getMessage();}
    }
    try{DataFormManager::synchronizeAllBoundTableFields($pdo);}catch(Throwable $e){$warnings[]='Feldsynchronisierung: '.$e->getMessage();}
    $bindings=easyit_calendar_dataform_bindings($pdo,$ctx);
    $eventProfiles=[];
    $eventProfileByLogical=[
      'start'=>['label'=>'Beginn','type'=>'datetime','position'=>10,'required'=>true],
      'end'=>['label'=>'Ende','type'=>'datetime','position'=>20,'required'=>true],
      'all_day'=>['label'=>'Ganztägig','type'=>'checkbox','position'=>25,'config'=>['default_mode'=>'defined','default_value'=>'0']],
      'location'=>['label'=>'Ort','type'=>'lookup','position'=>27],
      'resource'=>['label'=>'Ressource','type'=>'lookup','position'=>28],
      'category'=>['label'=>'Kategorie','type'=>'lookup','position'=>29],
      'active'=>['label'=>'Aktiv','type'=>'checkbox','position'=>30],
      'title'=>['label'=>'Thema / Titel','type'=>'text','position'=>40,'required'=>true],
      'description'=>['label'=>'Beschreibung','type'=>'textarea','position'=>50],
      'status'=>['label'=>'Status','type'=>'select','position'=>60,'config'=>['options'=>['planned','confirmed','cancelled']]],
    ];
    foreach($eventProfileByLogical as $logical=>$profile){$field=easyit_calendar_field_name($ctx,$logical,'');if($field!=='')$eventProfiles[$field]=$profile;}
    foreach(['version_no'=>['label'=>'Version','type'=>'number','position'=>70,'config'=>['readonly'=>true]],'created_by'=>['label'=>'Erstellt von','type'=>'number','position'=>80,'config'=>['readonly'=>true]],'updated_by'=>['label'=>'Geändert von','type'=>'number','position'=>90,'config'=>['readonly'=>true]],'created_at'=>['label'=>'Erstellt am','type'=>'datetime','position'=>100,'config'=>['readonly'=>true]],'updated_at'=>['label'=>'Geändert am','type'=>'datetime','position'=>110,'config'=>['readonly'=>true]]] as $field=>$profile)$eventProfiles[$field]=$profile;
    $profiles=[
      'events'=>$eventProfiles,
      'places'=>['name'=>['label'=>'Ort','type'=>'text','position'=>10,'required'=>true],'street'=>['label'=>'Straße / Hausnummer','type'=>'text','position'=>20],'postal_code'=>['label'=>'PLZ','type'=>'text','position'=>30],'city'=>['label'=>'Ort / Stadt','type'=>'text','position'=>40],'room'=>['label'=>'Raum','type'=>'text','position'=>50],'description'=>['label'=>'Beschreibung','type'=>'textarea','position'=>60],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>70],'created_at'=>['label'=>'Erstellt am','type'=>'datetime','position'=>80,'config'=>['readonly'=>true]],'updated_at'=>['label'=>'Geändert am','type'=>'datetime','position'=>90,'config'=>['readonly'=>true]]],
      'persons'=>['display_name'=>['label'=>'Name','type'=>'text','position'=>10,'required'=>true],'email'=>['label'=>'E-Mail','type'=>'email','position'=>20],'calendar_color'=>['label'=>'Terminfarbe','type'=>'color','position'=>30,'config'=>['help_text'=>'Färbt die rechte untere Ecke der Termine dieses Schülers.']],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>40],'created_at'=>['label'=>'Erstellt am','type'=>'datetime','position'=>50,'config'=>['readonly'=>true]],'updated_at'=>['label'=>'Geändert am','type'=>'datetime','position'=>60,'config'=>['readonly'=>true]]],
      'tutors'=>['display_name'=>['label'=>'Tutor','type'=>'text','position'=>10,'required'=>true,'config'=>['readonly'=>true]],'email'=>['label'=>'E-Mail','type'=>'email','position'=>20,'config'=>['readonly'=>true]],'calendar_color'=>['label'=>'Terminfarbe','type'=>'color','position'=>30,'config'=>['readonly'=>true]],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>40,'config'=>['readonly'=>true]]],
      'resources'=>['name'=>['label'=>'Ressource','type'=>'text','position'=>10,'required'=>true],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>20]],
      'categories'=>['name'=>['label'=>'Kategorie','type'=>'text','position'=>10,'required'=>true],'category_color'=>['label'=>'Terminbalkenfarbe','type'=>'color','position'=>20,'config'=>['help_text'=>'Färbt den linken Balken aller Termine dieser Kategorie.']],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>30]],
      'roles'=>['name'=>['label'=>'Interner Name','type'=>'text','position'=>10,'required'=>true],'label'=>['label'=>'Bezeichnung','type'=>'text','position'=>20,'required'=>true],'role_key'=>['label'=>'Rollenschlüssel','type'=>'text','position'=>30,'required'=>true]],
      'participants'=>['event_id'=>['label'=>'Termin','type'=>'lookup','position'=>10,'required'=>true],'person_id'=>['label'=>'Person','type'=>'lookup','position'=>20,'required'=>true],'role_id'=>['label'=>'Rolle','type'=>'lookup','position'=>30,'required'=>true],'participation_status'=>['label'=>'Teilnahmestatus','type'=>'select','position'=>40,'config'=>['options'=>['planned','confirmed','cancelled']]],'is_primary'=>['label'=>'Primär','type'=>'checkbox','position'=>50]],
      'attachments'=>['event_id'=>['label'=>'Termin','type'=>'lookup','position'=>10,'required'=>true],'original_name'=>['label'=>'Dateiname','type'=>'text','position'=>20],'stored_name'=>['label'=>'Speichername','type'=>'text','position'=>30,'config'=>['readonly'=>true]],'storage_path'=>['label'=>'Speicherpfad','type'=>'text','position'=>40,'config'=>['readonly'=>true]],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>50],'created_at'=>['label'=>'Erstellt am','type'=>'datetime','position'=>60,'config'=>['readonly'=>true]]],
      'blocks'=>['start'=>['label'=>'Beginn','type'=>'datetime','position'=>10,'required'=>true],'end'=>['label'=>'Ende','type'=>'datetime','position'=>20,'required'=>true],'category_id'=>['label'=>'Kategorie','type'=>'lookup','position'=>30,'required'=>true],'person_id'=>['label'=>'Person','type'=>'lookup','position'=>35,'config'=>['help_text'=>'Leer = Blockierung gilt für alle Personen.']],'reason'=>['label'=>'Grund / Bemerkung','type'=>'textarea','position'=>40],'activ'=>['label'=>'Aktiv','type'=>'checkbox','position'=>50],'created_by'=>['label'=>'Erstellt von','type'=>'number','position'=>60,'config'=>['readonly'=>true]],'created_at'=>['label'=>'Erstellt am','type'=>'datetime','position'=>70,'config'=>['readonly'=>true]],'updated_at'=>['label'=>'Geändert am','type'=>'datetime','position'=>80,'config'=>['readonly'=>true]]],
    ];
    foreach($profiles as $logical=>$fields){$df=(int)($bindings[$logical]['id']??0);if($df<1)continue;foreach($fields as $name=>$profile)easyit_calendar_df_field_profile($pdo,$df,$name,$profile);}
    // Einheitliche Basisrollen auch für CSV/SQLite und bereits bestehende Projekte.
    try{
        $rolesDf=(int)($bindings['roles']['id']??0);if($rolesDf>0){
            $existingRoles=DataFormRecordStore::all($pdo,$rolesDf);$keys=[];foreach($existingRoles as $rr)$keys[]=(string)(($rr['data']['role_key']??''));
            foreach([['name'=>'admin','label'=>'Administrator','role_key'=>'admin'],['name'=>'tutor','label'=>'Tutor','role_key'=>'tutor'],['name'=>'student','label'=>'Student','role_key'=>'student']] as $role)if(!in_array($role['role_key'],$keys,true))DataFormRecordStore::create($pdo,$rolesDf,$role);
        }
    }catch(Throwable $e){$warnings[]='Basisrollen: '.$e->getMessage();}
    try{
        $categoriesDf=(int)($bindings['categories']['id']??0);
        if($categoriesDf>0){
            $existingCategories=DataFormRecordStore::all($pdo,$categoriesDf);
            if(count($existingCategories)===0)DataFormRecordStore::create($pdo,$categoriesDf,['name'=>'Blockiert','activ'=>1,'created_at'=>date('Y-m-d H:i:s'),'updated_at'=>date('Y-m-d H:i:s')]);
        }
    }catch(Throwable $e){$warnings[]='Standardkategorie: '.$e->getMessage();}
    try{
        $events=(int)($bindings['events']['id']??0);$parts=(int)($bindings['participants']['id']??0);$atts=(int)($bindings['attachments']['id']??0);$persons=(int)($bindings['persons']['id']??0);$places=(int)($bindings['places']['id']??0);$roles=(int)($bindings['roles']['id']??0);$resources=(int)($bindings['resources']['id']??0);$categories=(int)($bindings['categories']['id']??0);$blocks=(int)($bindings['blocks']['id']??0);
        $eventDisplay=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'title','thema'));$eventLocation=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'location','location'));$eventResource=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'resource','resource_id'));$eventCategory=easyit_calendar_df_field_id($pdo,$events,easyit_calendar_field_name($ctx,'category','category_id'));$placeDisplay=easyit_calendar_df_field_id($pdo,$places,'name');$resourceDisplay=easyit_calendar_df_field_id($pdo,$resources,'name');$categoryDisplay=easyit_calendar_df_field_id($pdo,$categories,'name');$partEvent=easyit_calendar_df_field_id($pdo,$parts,'event_id');$attEvent=easyit_calendar_df_field_id($pdo,$atts,'event_id');$partPerson=easyit_calendar_df_field_id($pdo,$parts,'person_id');$partRole=easyit_calendar_df_field_id($pdo,$parts,'role_id');$personDisplay=easyit_calendar_df_field_id($pdo,$persons,'display_name');$roleDisplay=easyit_calendar_df_field_id($pdo,$roles,'label');$blockCategory=easyit_calendar_df_field_id($pdo,$blocks,'category_id');$blockPerson=easyit_calendar_df_field_id($pdo,$blocks,'person_id');
        if($events&&$parts&&$eventDisplay&&$partEvent&&!easyit_calendar_df_relation_exists($pdo,'calendar_event_participants'))RelationManager::createOneToMany($pdo,'calendar_event_participants',$events,$parts,$eventDisplay,$partEvent,false,true,'event_id',true);
        if($events&&$atts&&$eventDisplay&&$attEvent&&!easyit_calendar_df_relation_exists($pdo,'calendar_event_attachments'))RelationManager::createOneToMany($pdo,'calendar_event_attachments',$events,$atts,$eventDisplay,$attEvent,false,true,'event_id',true);
        if($parts&&$persons&&$partPerson&&$personDisplay&&!easyit_calendar_df_relation_exists($pdo,'calendar_participant_person'))RelationManager::createManyToOne($pdo,'calendar_participant_person',$parts,$persons,$partPerson,$personDisplay,true);
        if($parts&&$roles&&$partRole&&$roleDisplay&&!easyit_calendar_df_relation_exists($pdo,'calendar_participant_role'))RelationManager::createManyToOne($pdo,'calendar_participant_role',$parts,$roles,$partRole,$roleDisplay,true);
        easyit_calendar_migrate_event_locations($pdo,$ctx,$bindings);
        easyit_calendar_force_lookup($pdo,'calendar_event_place',$events,$places,$eventLocation,$placeDisplay,false);
        easyit_calendar_force_lookup($pdo,'calendar_event_resource',$events,$resources,$eventResource,$resourceDisplay,false);
        easyit_calendar_force_lookup($pdo,'calendar_event_category',$events,$categories,$eventCategory,$categoryDisplay,false);
        if($blocks&&$categories&&$blockCategory&&$categoryDisplay&&!easyit_calendar_df_relation_exists($pdo,'calendar_block_category'))RelationManager::createManyToOne($pdo,'calendar_block_category',$blocks,$categories,$blockCategory,$categoryDisplay,true);
        if($blocks&&$persons&&$blockPerson&&$personDisplay&&!easyit_calendar_df_relation_exists($pdo,'calendar_block_person'))RelationManager::createManyToOne($pdo,'calendar_block_person',$blocks,$persons,$blockPerson,$personDisplay,false);
    }catch(Throwable $e){$warnings[]='Basisrelationen: '.$e->getMessage();}
    try{$lookupSync=easyit_calendar_sync_mapping_lookup_relations($pdo,$ctx);foreach((array)($lookupSync['warnings']??[]) as $w)$warnings[]=$w;}catch(Throwable $e){$warnings[]='Calendar-Lookups: '.$e->getMessage();}
    try{easyit_calendar_dataform_mark_embedded($pdo,$ctx);}catch(Throwable $e){$warnings[]='Calendar-Lizenzstatus: '.$e->getMessage();}
    return ['schema'=>$schema,'calendar_schema'=>$calendarSchema,'created'=>$created,'bindings'=>easyit_calendar_dataform_bindings($pdo,$ctx),'warnings'=>$warnings];
}

/** Fast path for normal runtime requests; full installer runs only when bindings are missing. */
function easyit_calendar_dataform_ensure(PDO $pdo,array $ctx): array {
    try{
        easyit_calendar_ensure_tutors_view($pdo,$ctx);
        $bindings=easyit_calendar_dataform_bindings($pdo,$ctx);
        if($bindings && count(array_filter($bindings,static fn(array $b):bool=>(int)($b['id']??0)>0))===count(easyit_calendar_dataform_specs($ctx))){
            $events=(int)($bindings['events']['id']??0);
            $persons=(int)($bindings['persons']['id']??0);$categories=(int)($bindings['categories']['id']??0);$blocks=(int)($bindings['blocks']['id']??0);$extended=$events>0 && easyit_calendar_df_field_id($pdo,$events,'all_day')>0 && easyit_calendar_df_field_id($pdo,$events,'location')>0 && easyit_calendar_df_field_id($pdo,$events,'resource_id')>0 && easyit_calendar_df_field_id($pdo,$events,'category_id')>0 && $persons>0 && easyit_calendar_df_field_id($pdo,$persons,'calendar_color')>0 && $categories>0 && easyit_calendar_df_field_id($pdo,$categories,'category_color')>0 && $blocks>0 && easyit_calendar_df_field_id($pdo,$blocks,'person_id')>0;
            $relationsReady=easyit_calendar_df_relation_exists($pdo,'calendar_event_participants')&&easyit_calendar_df_relation_exists($pdo,'calendar_event_attachments')&&easyit_calendar_df_relation_exists($pdo,'calendar_participant_person')&&easyit_calendar_df_relation_exists($pdo,'calendar_participant_role')&&easyit_calendar_df_relation_exists($pdo,'calendar_event_place')&&easyit_calendar_df_relation_exists($pdo,'calendar_event_resource')&&easyit_calendar_df_relation_exists($pdo,'calendar_event_category')&&easyit_calendar_df_relation_exists($pdo,'calendar_block_category')&&easyit_calendar_df_relation_exists($pdo,'calendar_block_person');
            if($extended&&$relationsReady){
                // HF163: IDs may have changed after a reused/recreated project DB.
                // Rebind the three term-dialog lookups before records.php is opened.
                easyit_calendar_rebind_core_lookups($pdo,$ctx,$bindings);
                $lookupSync=easyit_calendar_sync_mapping_lookup_relations($pdo,$ctx);
                easyit_calendar_dataform_mark_embedded($pdo,$ctx);
                return ['schema'=>[],'calendar_schema'=>['driver'=>easyit_calendar_driver($ctx),'created'=>[]],'created'=>[],'bindings'=>easyit_calendar_dataform_bindings($pdo,$ctx),'warnings'=>(array)($lookupSync['warnings']??[])];
            }
        }
    }catch(Throwable){ }
    return easyit_calendar_dataform_install($pdo,$ctx);
}
