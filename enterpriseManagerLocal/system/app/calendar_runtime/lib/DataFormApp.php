<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function df_form_meta(PDO $pdo,int $id): array {
    $s=$pdo->prepare('SELECT * FROM dataforms WHERE id=? LIMIT 1'); $s->execute([$id]); $r=$s->fetch();
    if(!$r) throw new RuntimeException('DataForm wurde nicht gefunden.'); return $r;
}
function df_fields(PDO $pdo,int $id): array {
    $s=$pdo->prepare('SELECT id,name,label,field_type,is_required,configuration_json,position FROM dataform_fields WHERE dataform_id=? ORDER BY position,id');
    $s->execute([$id]); $rows=[];
    foreach($s->fetchAll() as $r){ $cfg=[]; if(trim((string)($r['configuration_json']??''))!==''){ $x=json_decode((string)$r['configuration_json'],true); if(is_array($x))$cfg=$x; } $r['cfg']=$cfg; $rows[]=$r; }
    return $rows;
}
function df_binding(PDO $pdo,int $id): ?array {
    try { $s=$pdo->prepare("SELECT * FROM dataform_table_bindings WHERE dataform_id=? AND source_kind='system' AND source_id=0 LIMIT 1"); $s->execute([$id]); $r=$s->fetch(); return $r?:null; }
    catch(Throwable){ return null; }
}
function df_column_map(PDO $pdo,int $formId,string $table,array $fields): array {
    $meta=[]; foreach($pdo->query('SHOW FULL COLUMNS FROM '.df_ident($table))->fetchAll() as $c)$meta[strtolower((string)$c['Field'])]=$c;
    $map=[];
    foreach($fields as $f){ $col=(string)$f['name']; $tb=$f['cfg']['table_binding']??null; if(is_array($tb)&&!empty($tb['column']))$col=(string)$tb['column'];
        if(isset($meta[strtolower($col)]))$map[(string)$f['name']]=['column'=>$col,'type'=>strtolower((string)$meta[strtolower($col)]['Type'])]; }
    return $map;
}
function df_raw_records(PDO $pdo,int $formId,array $fields): array {
    $binding=df_binding($pdo,$formId);
    if($binding){ $table=(string)$binding['table_name']; $map=df_column_map($pdo,$formId,$table,$fields); $rows=$pdo->query('SELECT * FROM '.df_ident($table).' ORDER BY `id`')->fetchAll(); $out=[];
        foreach($rows as $row){ $data=[]; foreach($map as $name=>$m)$data[$name]=$row[$m['column']]??null; $out[]=['id'=>(int)$row['id'],'data'=>$data,'updated_at'=>$row['updated_at']??null]; } return $out; }
    $s=$pdo->prepare('SELECT id,data_json,updated_at FROM dataform_records WHERE dataform_id=? ORDER BY id'); $s->execute([$formId]); $out=[];
    foreach($s->fetchAll() as $r)$out[]=['id'=>(int)$r['id'],'data'=>(json_decode((string)$r['data_json'],true)?:[]),'updated_at'=>$r['updated_at']??null]; return $out;
}
function df_find_record(PDO $pdo,int $formId,int $id,array $fields): ?array { foreach(df_raw_records($pdo,$formId,$fields) as $r)if((int)$r['id']===$id)return $r; return null; }
function df_write_record(PDO $pdo,int $formId,array $fields,array $data,?int $id=null): int {
    $binding=df_binding($pdo,$formId);
    if(!$binding){ $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); if($id){$s=$pdo->prepare('UPDATE dataform_records SET data_json=? WHERE id=? AND dataform_id=?');$s->execute([$json,$id,$formId]);return $id;} $s=$pdo->prepare('INSERT INTO dataform_records(dataform_id,data_json) VALUES(?,?)');$s->execute([$formId,$json]);return (int)$pdo->lastInsertId(); }
    $table=(string)$binding['table_name']; $map=df_column_map($pdo,$formId,$table,$fields); $names=[];$vals=[]; foreach($map as $name=>$m)if(array_key_exists($name,$data)){$names[]=(string)$m['column'];$vals[]=$data[$name];}
    if($id){ if($names){$sets=array_map(fn($n)=>df_ident($n).'=?',$names);$vals[]=$id;$s=$pdo->prepare('UPDATE '.df_ident($table).' SET '.implode(',',$sets).' WHERE `id`=?');$s->execute($vals);} return $id; }
    if(!$names){$pdo->exec('INSERT INTO '.df_ident($table).' () VALUES ()');} else {$qs=implode(',',array_fill(0,count($names),'?'));$s=$pdo->prepare('INSERT INTO '.df_ident($table).' ('.implode(',',array_map('df_ident',$names)).') VALUES('.$qs.')');$s->execute($vals);} return (int)$pdo->lastInsertId();
}
function df_delete_record(PDO $pdo,int $formId,int $id): void { $b=df_binding($pdo,$formId); if($b){$s=$pdo->prepare('DELETE FROM '.df_ident((string)$b['table_name']).' WHERE id=?');$s->execute([$id]);}else{$s=$pdo->prepare('DELETE FROM dataform_records WHERE id=? AND dataform_id=?');$s->execute([$id,$formId]);} }
function df_bulk_delete(PDO $pdo,int $formId,array $ids): void { foreach(array_unique(array_filter(array_map('intval',$ids))) as $id)df_delete_record($pdo,$formId,$id); }

function df_recordset_event_handlers($pdo,int $formId,string $recordsetKey='main'): array {
    $keys=[
        'before_current_change','after_current_change',
        'before_field_change','after_field_change',
        'before_new','after_new',
        'before_validate','after_validate',
        'before_save','after_save',
        'before_insert','after_insert',
        'before_update','after_update',
        'before_delete','after_delete'
    ];
    $handlers=array_fill_keys($keys,'');
    try{
        $stmt=$pdo->prepare(
            'SELECT event_key,handler_code FROM dataform_recordset_event_handlers '.
            'WHERE dataform_id=? AND recordset_key=?'
        );
        $stmt->execute([$formId,$recordsetKey]);
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
            $event=(string)($row['event_key']??'');
            if(array_key_exists($event,$handlers)){
                $handlers[$event]=(string)($row['handler_code']??'');
            }
        }
    }catch(Throwable $e){
        // Ein Export ohne konfigurierte DS-Handler bleibt lauffähig.
        // CRUD-Code wird niemals aus event_handlers_json des DataForms bezogen.
    }
    return $handlers;
}

function df_action_context_after_save(array $form,array $fields,int $recordId,array $values,array $old,string $operation,?array $parentContext,string $viewMode,int $per): array {
    $changes=[];$dirty=[];$fieldState=[];
    foreach($fields as $f){$name=(string)$f['name'];$value=$values[$name]??null;$before=$old[$name]??null;$changed=json_encode($before,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)!==json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($changed){$dirty[]=$name;$changes[$name]=['old'=>$before,'new'=>$value];}$fieldState[$name]=['field_id'=>(int)$f['id'],'name'=>$name,'label'=>(string)$f['label'],'type'=>(string)$f['field_type'],'value'=>$value,'original_value'=>$before,'dirty'=>$changed,'required'=>(int)$f['is_required']===1,'readonly'=>!empty($f['cfg']['readonly']),'visible'=>(string)$f['field_type']!=='hidden'&&empty($f['cfg']['hidden']),'valid'=>true,'errors'=>[]];}
    $parent=null;if($parentContext!==null)$parent=['relation_id'=>(int)$parentContext['relation_id'],'dataform_id'=>(int)$parentContext['parent_form_id'],'dataform'=>(string)$parentContext['parent_form_name'],'record_id'=>(int)$parentContext['parent_record_id'],'foreign_key'=>(string)$parentContext['lookup_field_name'],'foreign_key_value'=>(int)$parentContext['parent_record_id'],'binding'=>['inherited'=>true,'readonly'=>!array_key_exists('bound_field_readonly',$parentContext)||!empty($parentContext['bound_field_readonly'])]];
    return ['schema'=>'easyit.dataform.action-context','schema_version'=>'1.0','object_name'=>'dataformContext','action'=>['name'=>'save','phase'=>'after','trigger'=>'user','timestamp'=>date(DATE_ATOM)],'project'=>['id'=>(int)(df_config()['project_id']??1),'name'=>(string)(df_config()['project_name']??'Projekt')],'dataform'=>['id'=>(int)$form['id'],'name'=>(string)$form['name'],'slug'=>(string)($form['slug']??''),'status'=>(string)($form['status']??''),'view_mode'=>$viewMode,'fulltext_search'=>(int)($form['show_search']??1)===1,'filter'=>(int)($form['show_filter']??1)===1,'storage_mode'=>df_binding(df_pdo(),(int)$form['id'])?'physical_table':'generic'],'context'=>['mode'=>$operation==='create'?'create':'edit','is_new_record'=>$operation==='create','is_dirty'=>count($dirty)>0,'preview'=>false,'current_record'=>['id'=>$recordId,'page'=>max(1,(int)($_GET['page']??1))],'parent'=>$parent,'relation'=>$parent===null?null:['relation_id'=>$parent['relation_id'],'type'=>'1:n','foreign_key'=>$parent['foreign_key'],'foreign_key_value'=>$parent['foreign_key_value'],'binding'=>$parent['binding']]],'record'=>['id'=>$recordId,'values'=>$values,'original_values'=>$old,'changes'=>$changes,'dirty_fields'=>$dirty],'fields'=>$fieldState,'validation'=>['valid'=>true,'errors'=>[],'warnings'=>[]],'ui'=>['view_mode'=>$viewMode,'page'=>max(1,(int)($_GET['page']??1)),'records_per_page'=>$per,'search'=>((int)($form['show_search']??1)===1?(string)($_GET['q']??''):''),'fulltext_search_enabled'=>(int)($form['show_search']??1)===1,'filter_enabled'=>(int)($form['show_filter']??1)===1,'active_record_id'=>$recordId,'dialog_open'=>$viewMode==='dialog'],'event'=>['cancel'=>false,'message'=>null,'data'=>new stdClass()],'result'=>['success'=>true,'operation'=>$operation,'record_id'=>$recordId,'affected_rows'=>1]];
}

function df_app_slug(string $value,string $fallback): string { $value=trim($value);if($value==='')$value=$fallback;$value=preg_replace('/[^A-Za-z0-9_-]+/','-',$value)??$value;$value=trim($value,'-_');if($value==='')$value=$fallback;return strtolower(substr($value,0,64)); }
function df_form_file_map(PDO $pdo): array { $rows=$pdo->query('SELECT id,name,slug FROM dataforms ORDER BY id')->fetchAll();$used=[];$map=[];foreach($rows as $r){$id=(int)$r['id'];$base=df_app_slug((string)($r['slug']??$r['name']??''),'dataform-'.$id);$file=$base;$n=2;while(isset($used[$file])){$file=$base.'-'.$n;$n++;}$used[$file]=true;$map[$id]=$file.'.php';}return $map; }
function df_child_relations(PDO $pdo,int $parentFormId): array { try{$s=$pdo->prepare("SELECT r.id,r.name,r.source_dataform_id,r.target_dataform_id,r.lookup_field_id,d.name child_dataform_name FROM dataform_relations r JOIN dataforms d ON d.id=r.target_dataform_id WHERE r.source_dataform_id=? AND r.relation_type='1:n' AND r.is_enabled=1 AND r.lookup_field_id IS NOT NULL ORDER BY r.name,r.id");$s->execute([$parentFormId]);$out=[];foreach($s->fetchAll() as $r){$fields=df_fields($pdo,(int)$r['target_dataform_id']);$lookup=null;foreach($fields as $f)if((int)$f['id']===(int)$r['lookup_field_id']){$lookup=$f;break;}if(!$lookup)continue;$r['lookup_field_name']=(string)$lookup['name'];$r['lookup_field_label']=(string)$lookup['label'];$r['fields']=$fields;$out[]=$r;}return $out;}catch(Throwable){return [];} }
function df_parent_context(PDO $pdo,int $childFormId): ?array {
    $relationId=max(0,(int)($_GET['parent_relation']??$_POST['parent_relation']??0));
    $parentRecord=max(0,(int)($_GET['parent_record']??$_POST['parent_record']??0));
    if($relationId<1||$parentRecord<1)return null;
    try{
        $s=$pdo->prepare("SELECT r.id,r.name,r.source_dataform_id,r.target_dataform_id,r.lookup_field_id,r.target_display_field_id,r.configuration_json,p.name parent_dataform_name FROM dataform_relations r JOIN dataforms p ON p.id=r.source_dataform_id WHERE r.id=? AND r.target_dataform_id=? AND r.relation_type='1:n' AND r.is_enabled=1 AND r.lookup_field_id IS NOT NULL LIMIT 1");
        $s->execute([$relationId,$childFormId]);
        $r=$s->fetch();if(!$r)return null;
        $fields=df_fields($pdo,$childFormId);$lookup=null;
        foreach($fields as $f)if((int)$f['id']===(int)$r['lookup_field_id']){$lookup=$f;break;}
        if(!$lookup)return null;
        $parentFields=df_fields($pdo,(int)$r['source_dataform_id']);
        $parent=df_find_record($pdo,(int)$r['source_dataform_id'],$parentRecord,$parentFields);if(!$parent)return null;
        $displayFieldId=(int)($r['target_display_field_id']??0);
        $displayFieldName='';
        if($displayFieldId>0){foreach($parentFields as $pf){if((int)$pf['id']===$displayFieldId){$displayFieldName=(string)$pf['name'];break;}}}
        $caption='#'.$parentRecord;
        if($displayFieldName!==''){$candidate=trim((string)($parent['data'][$displayFieldName]??''));if($candidate!=='')$caption=$candidate;}
        $options=[];
        foreach(df_raw_records($pdo,(int)$r['source_dataform_id'],$parentFields) as $pr){$pid=(int)$pr['id'];$pcaption='#'.$pid;if($displayFieldName!==''){$candidate=trim((string)($pr['data'][$displayFieldName]??''));if($candidate!=='')$pcaption=$candidate;}$options[]=['id'=>$pid,'caption'=>$pcaption];}
        $cfg=json_decode((string)($r['configuration_json']??''),true);if(!is_array($cfg))$cfg=[];
        return [
            'relation_id'=>$relationId,
            'parent_form_id'=>(int)$r['source_dataform_id'],
            'parent_form_name'=>(string)$r['parent_dataform_name'],
            'parent_record_id'=>$parentRecord,
            'parent_caption'=>$caption,
            'lookup_field_id'=>(int)$r['lookup_field_id'],
            'lookup_field_name'=>(string)$lookup['name'],
            'lookup_field_label'=>(string)$lookup['label'],
            'bound_field_readonly'=>!array_key_exists('bound_field_readonly',$cfg)||!empty($cfg['bound_field_readonly']),
            'parent_options'=>$options,
        ];
    }catch(Throwable){return null;}
}
function df_child_records(PDO $pdo,array $relation,int $parentRecordId): array { $childId=(int)$relation['target_dataform_id'];$fields=(array)$relation['fields'];$lookup=(string)$relation['lookup_field_name'];return array_values(array_filter(df_raw_records($pdo,$childId,$fields),static fn(array $r):bool=>(string)($r['data'][$lookup]??'')===(string)$parentRecordId)); }
function df_child_collections_html(PDO $pdo,int $parentFormId,int $parentRecordId,string $base='../'): string { $relations=df_child_relations($pdo,$parentFormId);if(!$relations)return '';$files=df_form_file_map($pdo);$html='';foreach($relations as $rel){$childId=(int)$rel['target_dataform_id'];$rows=df_child_records($pdo,$rel,$parentRecordId);$fields=array_values(array_filter((array)$rel['fields'],fn(array $f):bool=>(int)$f['id']!==(int)$rel['lookup_field_id']&&(string)$f['field_type']!=='hidden'&&empty($f['cfg']['hidden'])));$childFile=(string)($files[$childId]??('dataform-'.$childId.'.php'));$ctx='parent_relation='.(int)$rel['id'].'&amp;parent_record='.$parentRecordId;$html.='<section class="card child-collection" data-child-relation="'.(int)$rel['id'].'"><div class="child-heading"><div><h2>'.df_e((string)$rel['child_dataform_name']).'</h2><p>'.count($rows).' Kinddatensatz'.(count($rows)===1?'':'-sätze').' für Eltern-Datensatz #'.$parentRecordId.'</p></div><a class="icon-action" href="'.df_e($childFile).'?'.$ctx.'#new-record" title="Kinddatensatz anlegen"><img src="'.$base.'assets/img/neu.png" alt=""></a></div>';if($rows){$html.='<div class="table-wrap child-table"><table><thead><tr><th>ID</th>';foreach($fields as $f)$html.='<th>'.df_e((string)$f['label']).'</th>';$html.='<th>Aktionen</th></tr></thead><tbody>';foreach($rows as $r){$rid=(int)$r['id'];$html.='<tr><td>'.$rid.'</td>';foreach($fields as $f)$html.='<td>'.df_display_cell($base,$childId,$rid,$f,$r['data'][(string)$f['name']]??'').'</td>';$html.='<td class="actions"><a href="'.df_e($childFile).'?'.$ctx.'&amp;record='.$rid.'&amp;active_record='.$rid.'" title="Kinddatensatz anzeigen"><img src="'.$base.'assets/img/anzeigen.png" alt=""></a><a href="'.df_e($childFile).'?'.$ctx.'&amp;active_record='.$rid.'#edit-'.$rid.'" title="Kinddatensatz bearbeiten"><img src="'.$base.'assets/img/bearbeiten.png" alt=""></a></td></tr>';}$html.='</tbody></table></div>';}else{$html.='<div class="empty-children">Noch keine Kinddatensätze vorhanden.</div>';}$html.='</section>';}return $html; }

function df_options(array $field): array { $o=$field['cfg']['options']??[]; if(is_string($o))$o=preg_split('/\R+/',$o)?:[]; return array_values(array_filter(array_map(fn($v)=>is_scalar($v)?trim((string)$v):'',is_array($o)?$o:[]),fn($v)=>$v!=='')); }
function df_is_lookup_relation(PDO $pdo,array $field,int $formId): bool {
    try{$s=$pdo->prepare("SELECT * FROM dataform_relations WHERE relation_type='n:1' AND source_dataform_id=? AND source_field_id=? ORDER BY id LIMIT 1");$s->execute([$formId,(int)$field['id']]);$r=$s->fetch();return is_array($r)&&(string)($r['is_enabled']??'1')!=='0';}catch(Throwable){return false;}
}
function df_lookup_options(PDO $pdo,array $field,int $formId): array {
    try{$s=$pdo->prepare("SELECT * FROM dataform_relations WHERE relation_type='n:1' AND source_dataform_id=? AND source_field_id=? ORDER BY id LIMIT 1");$s->execute([$formId,(int)$field['id']]);$r=$s->fetch();}catch(Throwable){return [];}
    if(!$r||(string)($r['is_enabled']??'1')==='0')return [];
    $other=(int)($r['target_dataform_id']??0);if($other<1)return [];
    $displayName='';if((int)($r['target_display_field_id']??0)>0){$q=$pdo->prepare('SELECT name FROM dataform_fields WHERE id=? LIMIT 1');$q->execute([(int)$r['target_display_field_id']]);$displayName=(string)($q->fetchColumn()?:'');}
    $fields=df_fields($pdo,$other);$opts=[];foreach(df_raw_records($pdo,$other,$fields) as $rec){$label='#'.$rec['id'];if($displayName!==''&&trim((string)($rec['data'][$displayName]??''))!=='')$label=(string)$rec['data'][$displayName];$opts[]=['value'=>(string)$rec['id'],'label'=>$label];}return $opts;
}
function df_derived_options(PDO $pdo,array $field,int $recordId,array $current): array {
    $d=$field['cfg']['derived_multienum']??null; if(!is_array($d))return [];
    $table=(string)($d['source_table']??'');$vc=(string)($d['value_column']??'');$lc=(string)($d['label_column']??''); if($table===''||$vc===''||$lc==='')return [];
    try{$sql='SELECT '.df_ident($vc).' v,'.df_ident($lc).' l FROM '.df_ident($table);$args=[];$mode=(string)($d['filter_mode']??'none');$fc=(string)($d['filter_source_column']??'');$fv=null;
        if($mode==='current_record_id'&&$recordId>0)$fv=(string)$recordId; elseif($mode==='field'){ $fn=(string)($d['filter_field_name']??''); if($fn!=='')$fv=(string)($current[$fn]??''); } elseif($mode==='parent_record_id')$fv=(string)($_GET['parent_record']??$_GET['parent']??'');
        if($fv!==null&&$fv!==''&&$fc!==''){$sql.=' WHERE '.df_ident($fc).'=?';$args[]=$fv;} $sql.=' ORDER BY '.df_ident($lc).' LIMIT '.max(1,min(1000,(int)($d['max_options']??250)));$s=$pdo->prepare($sql);$s->execute($args);$out=[];foreach($s->fetchAll() as $r)$out[]=['value'=>(string)$r['v'],'label'=>(string)$r['l']];return $out;
    }catch(Throwable){return [];}
}
function df_json_array(mixed $v): array { if(is_array($v))return array_values(array_map('strval',$v)); if(!is_string($v)||trim($v)==='')return []; $x=json_decode($v,true); return is_array($x)?array_values(array_map('strval',$x)):[]; }
function df_csv_array(mixed $v): array { if(is_array($v))return array_values(array_map('strval',$v)); return array_values(array_filter(array_map('trim',explode(',',(string)$v)),fn($x)=>$x!=='')); }
function df_compute(string $tpl,array $data): string { return preg_replace_callback('/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/',fn($m)=>(string)($data[$m[1]]??''),$tpl)??$tpl; }
function df_store_upload(array $field,array $file): string {
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return '';
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Datei-Upload fehlgeschlagen.');
    $cfg=$field['cfg'];$ts=is_array($cfg['type_settings']??null)?$cfg['type_settings']:[];$max=max(1024,(int)($ts['max_bytes']??10485760));if((int)$file['size']>$max)throw new RuntimeException('Datei ist zu groß.');
    $bytes=file_get_contents((string)$file['tmp_name']);if($bytes===false)throw new RuntimeException('Datei konnte nicht gelesen werden.');$mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes)?:'application/octet-stream';
    if((string)$field['field_type']==='image'&&!in_array($mime,['image/jpeg','image/png','image/webp','image/gif'],true))throw new RuntimeException('Ungültiges Bildformat.');
    $meta=['version'=>2,'storage'=>(string)($ts['storage_driver']??'filesystem'),'name'=>basename((string)$file['name']),'mime'=>$mime,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'stored_at'=>gmdate('c')];
    if($meta['storage']==='database'){$meta['data_base64']=base64_encode($bytes);}else{$ext=match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','application/pdf'=>'pdf','text/plain'=>'txt','text/csv'=>'csv','application/json'=>'json','application/zip'=>'zip',default=>'bin'};$dir=df_dataform_documents_dir().'/dataform-'.(int)($_POST['dataform_id']??0).'/'.preg_replace('/[^A-Za-z0-9_-]/','_',((string)$field['name']));if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Upload-Verzeichnis kann nicht erstellt werden.');$path=$dir.'/'.bin2hex(random_bytes(16)).'.'.$ext;if(file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Datei konnte nicht gespeichert werden.');$meta['path']=ltrim(str_replace('\\','/',substr($path,strlen(DF_APP_ROOT))),'/');}
    return json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
function df_prepare_data(PDO $pdo,int $formId,array $fields,array $existing=[],int $recordId=0): array {
    $posted=$_POST['fields']??[]; if(!is_array($posted))$posted=[];$data=$existing;
    foreach($fields as $f){$n=(string)$f['name'];$t=(string)$f['field_type'];$required=(int)$f['is_required']===1;$v=$posted[$n]??null;
        if(in_array($t,['file','image'],true)){ $file=['error'=>UPLOAD_ERR_NO_FILE]; if(isset($_FILES['fields']['name'][$n]))$file=['name'=>$_FILES['fields']['name'][$n],'type'=>$_FILES['fields']['type'][$n]??'','tmp_name'=>$_FILES['fields']['tmp_name'][$n]??'','error'=>(int)($_FILES['fields']['error'][$n]??UPLOAD_ERR_NO_FILE),'size'=>(int)($_FILES['fields']['size'][$n]??0)]; if(!empty($_POST['remove_media'][$n])){$data[$n]='';}elseif(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){$data[$n]=df_store_upload($f,$file);} continue; }
        if($t==='hidden'){continue;} if(in_array($t,['boolean','checkbox'],true)){$data[$n]=isset($posted[$n])?'1':'0';continue;} if($t==='password'){if(trim((string)$v)!=='')$data[$n]=password_hash((string)$v,PASSWORD_DEFAULT);continue;}
        if(in_array($t,['multiselect','multi_lookup'],true))$v=json_encode(array_values(array_map('strval',is_array($v)?$v:[])),JSON_UNESCAPED_UNICODE); elseif($t==='tags')$v=json_encode(array_values(array_filter(array_map('trim',explode(',',(string)$v)))),JSON_UNESCAPED_UNICODE); elseif($t==='derived_multienum')$v=implode(',',array_values(array_map('strval',is_array($v)?$v:[]))); elseif($t==='link')$v=json_encode(is_array($v)?$v:['url'=>(string)$v],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); elseif($t==='coordinates')$v=json_encode(is_array($v)?$v:[],JSON_UNESCAPED_UNICODE); elseif($t==='json'&&trim((string)$v)!==''){json_decode((string)$v,true,512,JSON_THROW_ON_ERROR);}
        if($required && !in_array($t,['boolean','checkbox'],true) && ((is_scalar($v)&&trim((string)$v)==='')||$v===null))throw new RuntimeException('Pflichtfeld „'.(string)$f['label'].'“ fehlt.');
        if($t==='email'&&trim((string)$v)!==''&&filter_var((string)$v,FILTER_VALIDATE_EMAIL)===false)throw new RuntimeException('Ungültige E-Mail-Adresse.');if($t==='url'&&trim((string)$v)!==''&&filter_var((string)$v,FILTER_VALIDATE_URL)===false)throw new RuntimeException('Ungültige URL.');if($t==='integer'&&trim((string)$v)!==''&&filter_var((string)$v,FILTER_VALIDATE_INT)===false)throw new RuntimeException('Ungültige Ganzzahl.');if(in_array($t,['number','decimal','currency','percentage'],true)&&trim((string)$v)!==''&&!is_numeric((string)$v))throw new RuntimeException('Ungültiger Zahlenwert.');$data[$n]=is_scalar($v)?(string)$v:$v;
    }
    foreach($fields as $f)if((string)$f['field_type']==='computed'){$tpl=(string)($f['cfg']['type_settings']['template']??$f['cfg']['template']??'');$data[(string)$f['name']]=df_compute($tpl,$data);} return $data;
}
function df_media_meta(string $v): ?array { $x=json_decode($v,true); return is_array($x)&&isset($x['storage'],$x['name'],$x['mime'])?$x:null; }
function df_display_value(array $field,mixed $value): string { $t=(string)$field['field_type'];$v=(string)$value;if(in_array($t,['boolean','checkbox'],true))return in_array(strtolower($v),['1','true','yes','ja','on'],true)?'Ja':'Nein';if($t==='password')return $v!==''?'••••••••':'';if(in_array($t,['multiselect','tags','multi_lookup'],true))return implode(', ',df_json_array($v));if($t==='derived_multienum')return implode(', ',df_csv_array($v));if($t==='json'){ $x=json_decode($v,true);return is_array($x)?(json_encode($x,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)?:$v):$v;}if($t==='link'){ $x=json_decode($v,true);return is_array($x)?(string)($x['label']??$x['url']??''):$v;}if($t==='coordinates'){ $x=json_decode($v,true);return is_array($x)?trim((string)($x['lat']??'').', '.(string)($x['lng']??''),', '):$v;}if(in_array($t,['file','image'],true)){ $m=df_media_meta($v);return $m?(string)$m['name']:'';}return $v; }
function df_render_input(PDO $pdo,array $f,int $formId,array $data,int $recordId=0,?array $parentContext=null): string {
    $n=(string)$f['name'];$t=(string)$f['field_type'];$label=(string)$f['label'];$v=$data[$n]??'';$req=(int)$f['is_required']===1?' required':'';$name='fields['.df_e($n).']';$id='f-'.df_e($recordId.'-'.$n);
    if($parentContext!==null && (int)$f['id']===(int)$parentContext['lookup_field_id']){
        $parentId=(int)$parentContext['parent_record_id'];
        $readonly=!array_key_exists('bound_field_readonly',$parentContext)||!empty($parentContext['bound_field_readonly']);
        $selected=(string)$v!==''?(string)$v:(string)$parentId;
        if($readonly)$selected=(string)$parentId;
        $o='<label for="'.$id.'">'.df_e($label).'</label>';
        if($readonly){
            $o.='<input type="hidden" name="'.$name.'" value="'.$parentId.'">';
            $o.='<select id="'.$id.'" class="parent-preselected" disabled aria-label="'.df_e($label).' – gekoppelter Eltern-Datensatz">';
        }else{
            $o.='<select id="'.$id.'" name="'.$name.'" class="parent-preselected" aria-label="'.df_e($label).' – gekoppelter Eltern-Datensatz">';
            $o.='<option value="">Eltern-Datensatz wählen</option>';
        }
        $found=false;
        foreach((array)($parentContext['parent_options']??[]) as $option){$oid=(int)($option['id']??0);$ocaption=$oid===$parentId?'#'.$parentId:(string)($option['caption']??('#'.$oid));if((string)$oid===$selected)$found=true;$o.='<option value="'.$oid.'"'.((string)$oid===$selected?' selected':'').'>'.df_e($ocaption).'</option>';}
        if(!$found&&$parentId>0)$o.='<option value="'.$parentId.'" selected>#'.$parentId.'</option>';
        $o.='</select><small class="parent-preselected-note">Eltern-ID '.$parentId.($readonly?' · schreibgeschützt':' · vorbelegt, änderbar').'</small>';
        return $o;
    }
    if($t==='hidden')return '<input type="hidden" name="'.$name.'" value="'.df_e($v).'">';
    if($t==='computed')return '<label for="'.$id.'">'.df_e($label).'</label><input id="'.$id.'" value="'.df_e($v).'" readonly>';
    if(in_array($t,['boolean','checkbox'],true))return '<label class="check"><input id="'.$id.'" type="checkbox" name="'.$name.'" value="1" '.(in_array(strtolower((string)$v),['1','true','yes','ja','on'],true)?'checked':'').'> '.df_e($label).'</label>';
    if(in_array($t,['textarea','richtext','markdown','json'],true))return '<label for="'.$id.'">'.df_e($label).'</label><textarea id="'.$id.'" name="'.$name.'"'.$req.'>'.df_e($v).'</textarea>';
    if($t==='select'){ $o='<label for="'.$id.'">'.df_e($label).'</label><select id="'.$id.'" name="'.$name.'"'.$req.'><option value=""></option>';foreach(df_options($f) as $x)$o.='<option value="'.df_e($x).'"'.((string)$v===$x?' selected':'').'>'.df_e($x).'</option>';return $o.'</select>'; }
    if(in_array($t,['lookup','multi_lookup'],true)||df_is_lookup_relation($pdo,$f,$formId)){ $multiple=$t==='multi_lookup';$sel=$multiple?df_json_array($v):[(string)$v];$o='<label for="'.$id.'">'.df_e($label).'</label><select id="'.$id.'" name="'.$name.($multiple?'[]':'').'"'.($multiple?' multiple':'').$req.'><option value=""></option>';foreach(df_lookup_options($pdo,$f,$formId) as $x)$o.='<option value="'.df_e($x['value']).'"'.(in_array((string)$x['value'],$sel,true)?' selected':'').'>'.df_e($x['label']).'</option>';return $o.'</select>'; }
    if($t==='multiselect'){ $sel=df_json_array($v);$o='<label for="'.$id.'">'.df_e($label).'</label><select id="'.$id.'" name="'.$name.'[]" multiple>';foreach(df_options($f) as $x)$o.='<option value="'.df_e($x).'"'.(in_array($x,$sel,true)?' selected':'').'>'.df_e($x).'</option>';return $o.'</select>'; }
    if($t==='derived_multienum'){ $sel=df_csv_array($v);$o='<label for="'.$id.'">'.df_e($label).'</label><select id="'.$id.'" name="'.$name.'[]" multiple>';foreach(df_derived_options($pdo,$f,$recordId,$data) as $x)$o.='<option value="'.df_e($x['value']).'"'.(in_array((string)$x['value'],$sel,true)?' selected':'').'>'.df_e($x['label']).'</option>';return $o.'</select>'; }
    if($t==='tags')return '<label for="'.$id.'">'.df_e($label).'</label><input id="'.$id.'" name="'.$name.'" value="'.df_e(implode(', ',df_json_array($v))).'"'.$req.'>';
    if($t==='link'){ $x=json_decode((string)$v,true);if(!is_array($x))$x=[];return '<fieldset><legend>'.df_e($label).'</legend><input type="url" name="'.$name.'[url]" placeholder="URL" value="'.df_e($x['url']??'').'"><input name="'.$name.'[label]" placeholder="Linktext" value="'.df_e($x['label']??'').'"><select name="'.$name.'[target]"><option value="_self">gleiches Fenster</option><option value="_blank"'.(($x['target']??'')==='_blank'?' selected':'').'>neues Fenster</option></select></fieldset>'; }
    if($t==='coordinates'){ $x=json_decode((string)$v,true);if(!is_array($x))$x=[];return '<fieldset><legend>'.df_e($label).'</legend><input type="number" step="any" name="'.$name.'[lat]" placeholder="Breitengrad" value="'.df_e($x['lat']??'').'"><input type="number" step="any" name="'.$name.'[lng]" placeholder="Längengrad" value="'.df_e($x['lng']??'').'"></fieldset>'; }
    if(in_array($t,['file','image'],true)){ $m=df_media_meta((string)$v);$o='<label for="'.$id.'">'.df_e($label).'</label><input id="'.$id.'" type="file" name="'.$name.'"'.($t==='image'?' accept="image/jpeg,image/png,image/webp,image/gif"':'').'>';if($m)$o.='<div class="media-current"><a href="../media.php?dataform='.$formId.'&record='.$recordId.'&field='.rawurlencode($n).'" target="_blank">'.df_e($m['name']).'</a> <label><input type="checkbox" name="remove_media['.df_e($n).']" value="1"> entfernen</label></div>';return $o; }
    $htmlType=match($t){'integer','number','decimal','currency','percentage'=>'number','date'=>'date','time'=>'time','datetime'=>'datetime-local','email'=>'email','phone'=>'tel','url'=>'url','color'=>'color','password'=>'password',default=>'text'};$step=in_array($t,['number','decimal','currency','percentage'],true)?' step="any"':'';return '<label for="'.$id.'">'.df_e($label).'</label><input id="'.$id.'" type="'.$htmlType.'" name="'.$name.'" value="'.($t==='password'?'':df_e($v)).'"'.$step.$req.'>';
}
function df_button(string $img,string $title,string $text=''): string { return '<span class="button-wrap" title="'.df_e($title).'" aria-label="'.df_e($title).'"><img class="button-img" src="../assets/img/'.df_e($img).'.png" alt="">'.($text!==''?'<span class="sr-only">'.df_e($text).'</span>':'').'</span>'; }
function df_render_dataform(int $formId): void {
    $pdo=df_pdo();
    $form=df_form_meta($pdo,$formId);
    $fields=df_fields($pdo,$formId);
    $message='';$error='';$lastAction='';$actionContext=null;
    $active=max(0,(int)($_GET['active_record']??$_POST['active_record']??0));
    $parentContext=df_parent_context($pdo,$formId);
    $childRelations=df_child_relations($pdo,$formId);
    $viewMode=in_array((string)($form['view_mode']??'table'),['form','table','dialog'],true)?(string)$form['view_mode']:'table';$requestedView=(string)($_GET['runtime_view']??'');if(in_array($requestedView,['form','table','dialog'],true))$viewMode=$requestedView;
    $defaultPer=max(1,min(200,(int)($form['default_per_page']??20)));
    $showSearch=(int)($form['show_search']??1)===1;
    $showFilter=(int)($form['show_filter']??1)===1;
    $showPagination=(int)($form['show_pagination']??1)===1;
    $allowCreate=(int)($form['allow_create']??1)===1;
    $allowEdit=(int)($form['allow_edit']??1)===1;
    $allowDelete=(int)($form['allow_delete']??1)===1;
    $dialogSize=in_array((string)($form['dialog_size']??'large'),['small','medium','large','fullscreen'],true)?(string)$form['dialog_size']:'large';
    $cssClass=trim((string)($form['css_class']??''));
    $addCss=(string)($form['additional_css']??'');
    $events=json_decode((string)($form['event_handlers_json']??''),true);if(!is_array($events))$events=[];
    $recordEvents=df_recordset_event_handlers($pdo,$formId,'main');

    if((string)($_GET['child_fragment']??'')==='1'){
        header('Content-Type: text/html; charset=UTF-8');
        $parentId=max(0,(int)($_GET['parent_record']??0));
        echo $parentId>0?df_child_collections_html($pdo,$formId,$parentId,'../'):'';
        return;
    }

    try{
        if($_SERVER['REQUEST_METHOD']==='POST'){
            df_csrf_check();
            $action=(string)($_POST['action']??'');
            if($action==='create'){
                if(!$allowCreate)throw new RuntimeException('Das Anlegen ist deaktiviert.');
                $old=[];$data=df_prepare_data($pdo,$formId,$fields,$old,0);
                if($parentContext!==null&&(!array_key_exists('bound_field_readonly',$parentContext)||!empty($parentContext['bound_field_readonly'])))$data[(string)$parentContext['lookup_field_name']]=(string)$parentContext['parent_record_id'];
                $active=df_write_record($pdo,$formId,$fields,$data);
                $actionContext=df_action_context_after_save($form,$fields,$active,$data,$old,'create',$parentContext,$viewMode,$defaultPer);
                $message='Datensatz wurde angelegt.';$lastAction='after_save';
            }elseif($action==='update'){
                if(!$allowEdit)throw new RuntimeException('Das Bearbeiten ist deaktiviert.');
                $id=(int)$_POST['record'];
                $old=df_find_record($pdo,$formId,$id,$fields);
                if(!$old)throw new RuntimeException('Datensatz nicht gefunden.');
                $data=df_prepare_data($pdo,$formId,$fields,$old['data'],$id);
                if($parentContext!==null&&(!array_key_exists('bound_field_readonly',$parentContext)||!empty($parentContext['bound_field_readonly'])))$data[(string)$parentContext['lookup_field_name']]=(string)$parentContext['parent_record_id'];
                df_write_record($pdo,$formId,$fields,$data,$id);
                $actionContext=df_action_context_after_save($form,$fields,$id,$data,(array)$old['data'],'update',$parentContext,$viewMode,$defaultPer);
                $active=$id;$message='Datensatz wurde gespeichert.';$lastAction='after_save';
            }elseif($action==='delete'){
                if(!$allowDelete)throw new RuntimeException('Das Löschen ist deaktiviert.');
                $id=(int)$_POST['record'];
                df_delete_record($pdo,$formId,$id);
                $message='Datensatz wurde gelöscht.';$lastAction='after_delete';
                if($active===$id)$active=0;
            }elseif($action==='bulk_delete'){
                if(!$allowDelete)throw new RuntimeException('Das Löschen ist deaktiviert.');
                df_bulk_delete($pdo,$formId,(array)($_POST['selected']??[]));
                $message='Ausgewählte Datensätze wurden gelöscht.';$lastAction='after_delete';
                $active=0;
            }
        }
    }catch(Throwable $e){$error=$e->getMessage();}

    $all=df_raw_records($pdo,$formId,$fields);
    if($parentContext!==null){
        $lookup=(string)$parentContext['lookup_field_name'];$pid=(string)$parentContext['parent_record_id'];
        $all=array_values(array_filter($all,static fn(array $r):bool=>(string)($r['data'][$lookup]??'')===$pid));
    }
    $searchableFields=array_values(array_filter($fields,static function(array $f):bool{
        $cfg=(array)($f['cfg']??[]);
        if((string)($f['field_type']??'')==='hidden'||!empty($cfg['hidden']))return false;
        return !array_key_exists('searchable',$cfg)||(bool)$cfg['searchable'];
    }));
    $filterFields=array_values(array_filter($fields,static function(array $f):bool{
        $cfg=(array)($f['cfg']??[]);
        if((string)($f['field_type']??'')==='hidden'||!empty($cfg['hidden']))return false;
        return !array_key_exists('filterable',$cfg)||(bool)$cfg['filterable'];
    }));
    $filterableNames=array_map(static fn(array $f):string=>(string)$f['name'],$filterFields);

    $q=$showSearch?trim((string)($_GET['q']??'')):'';
    $filters=$showFilter&&isset($_GET['filter'])&&is_array($_GET['filter'])?$_GET['filter']:[];
    if($q!==''||$filters!==[]){
        $all=array_values(array_filter($all,function($r)use($searchableFields,$q,$filters,$filterableNames){
            if($q!==''){
                $matched=false;
                foreach($searchableFields as $f){
                    if(stripos(df_display_value($f,$r['data'][(string)$f['name']]??''),$q)!==false){$matched=true;break;}
                }
                if(!$matched&&stripos((string)($r['id']??''),$q)===false)return false;
            }
            foreach($filters as $name=>$needle){
                if(!in_array((string)$name,$filterableNames,true))continue;
                $needle=trim((string)$needle);if($needle==='')continue;
                $field=null;foreach($filterFields as $candidate)if((string)$candidate['name']===(string)$name){$field=$candidate;break;}
                $value=$field?df_display_value($field,$r['data'][(string)$name]??''):(string)($r['data'][(string)$name]??'');
                if(stripos($value,$needle)===false)return false;
            }
            return true;
        }));
    }
    $per=max(1,min(200,(int)($_GET['per_page']??$defaultPer)));
    $pages=max(1,(int)ceil(count($all)/$per));
    $page=max(1,min($pages,(int)($_GET['page']??1)));
    $rows=array_slice($all,($page-1)*$per,$per);

    $allIds=array_map(static fn(array $r):int=>(int)$r['id'],$all);
    if($active<1||!in_array($active,$allIds,true))$active=$allIds[0]??0;
    $activeRecord=$active>0?df_find_record($pdo,$formId,$active,$fields):null;
    $detailId=max(0,(int)($_GET['record']??0));
    $detail=$detailId?df_find_record($pdo,$formId,$detailId,$fields):null;
    $newRequested=(int)($_GET['new']??0)===1;

    $base='../';$logo=$base.'assets/img/easyit-epManager-logo.png';
    $title=(string)($form['name']??'DataForm');
    $ctxQuery=$parentContext!==null?'&amp;parent_relation='.(int)$parentContext['relation_id'].'&amp;parent_record='.(int)$parentContext['parent_record_id']:'';
    $ctxHidden=$parentContext!==null?'<input type="hidden" name="parent_relation" value="'.(int)$parentContext['relation_id'].'"><input type="hidden" name="parent_record" value="'.(int)$parentContext['parent_record_id'].'">':'';

    $recordForm=function(array $r,bool $create=false)use($pdo,$formId,$fields,$allowEdit,$allowDelete,$parentContext,$ctxHidden,$base):string{
        $id=$create?0:(int)$r['id'];$data=$create?[]:(array)$r['data'];if($create){foreach($fields as $dfDefaultField){$dfDefaultName=(string)$dfDefaultField['name'];$dfDefaultKey='default_'.$dfDefaultName;if(array_key_exists($dfDefaultKey,$_GET))$data[$dfDefaultName]=(string)$_GET[$dfDefaultKey];}}
        if($create&&$parentContext!==null)$data[(string)$parentContext['lookup_field_name']]=(string)$parentContext['parent_record_id'];
        $html='<section class="card runtime-record-form" data-runtime-record-form="'.($create?'new':$id).'"><h2>'.($create?'Neuer Datensatz':'Datensatz #'.$id).'</h2>';
        if($create||$allowEdit){
            $html.='<form method="post" enctype="multipart/form-data" class="edit-form">'.$ctxHidden.'<input type="hidden" name="csrf" value="'.df_e(df_csrf()).'"><input type="hidden" name="action" value="'.($create?'create':'update').'"><input type="hidden" name="record" value="'.$id.'"><input type="hidden" name="active_record" value="'.$id.'"><input type="hidden" name="dataform_id" value="'.$formId.'"><div class="fields">';
            foreach($fields as $f){$fw=max(25,min(100,(int)($f['cfg']['width']??100)));$html.='<div class="field" style="--field-width:'.$fw.'%">'.df_render_input($pdo,$f,$formId,$data,$id,$parentContext).'</div>';}
            $html.='</div><div class="form-actions"><button title="Datensatz speichern"><img src="'.$base.'assets/img/speichern.png" alt=""><span class="sr-only">Speichern</span></button></div></form>';
        }else{
            $html.='<dl class="record-detail">';
            foreach($fields as $f){if((string)$f['field_type']==='hidden'||!empty($f['cfg']['hidden']))continue;$html.='<dt>'.df_e((string)$f['label']).'</dt><dd>'.df_display_cell($base,$formId,$id,$f,$data[(string)$f['name']]??'').'</dd>';}
            $html.='</dl>';
        }
        if(!$create&&$allowDelete){
            $html.='<form method="post" class="delete-form" onsubmit="return confirm(\'Datensatz wirklich löschen?\')">'.$ctxHidden.'<input type="hidden" name="csrf" value="'.df_e(df_csrf()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="record" value="'.$id.'"><input type="hidden" name="active_record" value="'.$id.'"><button title="Datensatz löschen"><img src="'.$base.'assets/img/loeschen.png" alt=""><span class="sr-only">Löschen</span></button></form>';
        }
        return $html.'</section>';
    };

    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.df_e($title).'</title><link rel="stylesheet" href="'.$base.'assets/app.css">'.($addCss!==''?'<style>'.str_replace('</style','<\/style',$addCss).'</style>':'').'</head><body class="'.df_e($cssClass).' view-'.df_e($viewMode).'" data-current-record="'.$active.'" data-view-mode="'.df_e($viewMode).'" data-dialog-size="'.df_e($dialogSize).'"><header class="app-header"><a href="'.$base.'index.php"><img src="'.$logo.'" alt="easyIT"></a><div><strong>'.df_e($title).'</strong><span>DataForm</span></div></header><main><nav class="crumb"><a href="'.$base.'index.php">Übersicht</a> → '.df_e($title).'</nav>';
    if($message!=='')echo '<div class="notice success">'.df_e($message).'</div>';
    if($error!=='')echo '<div class="notice error">'.df_e($error).'</div>';
    if($parentContext!==null)echo '<div class="notice parent-context">Kindansicht zu '.df_e((string)$parentContext['parent_form_name']).' · '.df_e((string)($parentContext['parent_caption']??('#'.(int)$parentContext['parent_record_id']))).' – die gekoppelte Eigenschaft ist vorausgewählt; es werden ausschließlich zugehörige Kinddatensätze angezeigt.</div>';
    echo '<div class="view-state"><strong>Ansicht:</strong> '.($viewMode==='form'?'Formular':($viewMode==='dialog'?'Dialog':'Tabelle')).'</div>';

    echo '<section class="toolbar">';
    if($showSearch||$showFilter){
        echo '<form method="get" class="search-filter-form">'.$ctxHidden.'<input type="hidden" name="per_page" value="'.$per.'">';
        if($showSearch){
            echo '<label>Volltextsuche<input type="search" name="q" value="'.df_e($q).'" placeholder="Alle durchsuchbaren Inhalte"></label><button title="Suchen"><img src="'.$base.'assets/img/suchen.png" alt="Suchen"></button>';
        }
        if($showFilter){
            echo '<details'.(array_filter($filters)?' open':'').'><summary>Feldfilter</summary><div class="field-filter-grid">';
            foreach($filterFields as $filterField){$fn=(string)$filterField['name'];echo '<label>'.df_e((string)$filterField['label']).'<input name="filter['.df_e($fn).']" value="'.df_e((string)($filters[$fn]??'')).'" placeholder="enthält …"></label>';}
            echo '</div><button title="Filter anwenden"><img src="'.$base.'assets/img/filter.png" alt="Filter anwenden"></button></details>';
        }
        echo '<a title="Suche und Filter zurücksetzen" href="?per_page='.$per.$ctxQuery.'"><img src="'.$base.'assets/img/filter_loeschen.png" alt=""></a></form>';
    }
    if($allowCreate){
        if($viewMode==='form')echo '<a class="new-action" href="?new=1'.$ctxQuery.'" title="Neuen Datensatz anlegen"><img src="'.$base.'assets/img/neu.png" alt=""></a>';
        elseif($viewMode==='dialog')echo '<button type="button" class="new-action" data-dialog-create title="Neuen Datensatz anlegen"><img src="'.$base.'assets/img/neu.png" alt=""></button>';
        else echo '<a class="new-action" href="#new-record" title="Neuen Datensatz anlegen"><img src="'.$base.'assets/img/neu.png" alt=""></a>';
    }
    echo '</section>';

    if($viewMode==='form'){
        if($allIds){
            $idx=array_search($active,$allIds,true);if($idx===false)$idx=0;
            $first=$allIds[0];$last=$allIds[count($allIds)-1];$prev=$idx>0?$allIds[$idx-1]:0;$next=$idx<count($allIds)-1?$allIds[$idx+1]:0;
            echo '<nav class="record-nav form-record-nav" aria-label="Datensatznavigation">';
            echo '<a href="?active_record='.$first.$ctxQuery.'" title="Erster Datensatz"><img src="'.$base.'assets/img/erster_ds.png" alt=""></a>';
            if($prev)echo '<a href="?active_record='.$prev.$ctxQuery.'" title="Vorheriger Datensatz"><img src="'.$base.'assets/img/vorheriger_ds.png" alt=""></a>';
            echo '<span class="current-record"><span class="current-record-marker"><img src="'.$base.'assets/img/aktueller_ds.png" alt=""></span><span class="current-record-meta"><small>Aktueller Datensatz</small><strong>#'.$active.'</strong></span></span>';
            if($next)echo '<a href="?active_record='.$next.$ctxQuery.'" title="Nächster Datensatz"><img src="'.$base.'assets/img/naechster_ds.png" alt=""></a>';
            echo '<a href="?active_record='.$last.$ctxQuery.'" title="Letzter Datensatz"><img src="'.$base.'assets/img/letzter_ds.png" alt=""></a>';
            if($allowCreate)echo '<a class="form-new-record" href="?new=1'.$ctxQuery.'" title="Neuer Datensatz"><img src="'.$base.'assets/img/neuer_ds.png" alt=""></a>';
            echo '</nav>';
        }
        if($newRequested&&$allowCreate)echo $recordForm([],true);
        elseif($activeRecord)echo $recordForm($activeRecord,false);
        elseif($allowCreate)echo $recordForm([],true);
        else echo '<section class="card empty-children">Keine Datensätze vorhanden.</section>';
        if($childRelations&&$active>0)echo '<section id="child-collections-panel" class="child-collections-panel" data-child-collections data-parent-form="'.$formId.'">'.df_child_collections_html($pdo,$formId,$active,$base).'</section>';
    }else{
        // Tabelle und Dialog verwenden dieselbe Datensatzübersicht. In der
        // Dialogansicht sind die Tabellenzellen bewusst read-only; Bearbeitung
        // erfolgt ausschließlich im modalen Formular.
        echo '<form id="bulk-delete" method="post"><input type="hidden" name="csrf" value="'.df_e(df_csrf()).'"><input type="hidden" name="action" value="bulk_delete"></form>';
        echo '<div class="table-wrap"><table><thead><tr><th></th><th><input type="checkbox" data-select-all></th><th>ID</th>';
        foreach($fields as $f)if((string)$f['field_type']!=='hidden'&&empty($f['cfg']['hidden']))echo '<th>'.df_e((string)$f['label']).'</th>';
        echo '<th>Aktionen</th></tr></thead><tbody>';

        foreach($rows as $r){
            $id=(int)$r['id'];$isActive=$id===$active;
            echo '<tr data-record-row="'.$id.'" class="'.($isActive?'active-row':'').'"><td><button type="button" class="record-pointer" data-record-pointer="'.$id.'"><img src="'.$base.'assets/img/'.($isActive?'aktueller_ds':'normaler_ds').'.png" alt=""></button></td><td><input type="checkbox" form="bulk-delete" name="selected[]" value="'.$id.'"></td><td>'.$id.'</td>';
            foreach($fields as $f){
                if((string)$f['field_type']==='hidden'||!empty($f['cfg']['hidden']))continue;
                if($viewMode==='dialog'||!$allowEdit)echo '<td>'.df_display_cell($base,$formId,$id,$f,$r['data'][(string)$f['name']]??'').'</td>';
                else echo '<td>'.df_render_input($pdo,$f,$formId,(array)$r['data'],$id,$parentContext).'</td>';
            }
            echo '<td class="actions">';
            if($viewMode==='dialog'){
                echo '<button type="button" data-dialog-record="'.$id.'" title="Datensatz im Dialog öffnen"><img src="'.$base.'assets/img/anzeigen.png" alt=""></button>';
            }else{
                if($allowEdit)echo '<button form="update-'.$id.'" title="Datensatz speichern"><img src="'.$base.'assets/img/speichern.png" alt=""></button>';
                echo '<a href="?record='.$id.'&active_record='.$id.'&page='.$page.$ctxQuery.'" title="Datensatz anzeigen"><img src="'.$base.'assets/img/anzeigen.png" alt=""></a>';
            }
            if($allowDelete)echo '<button form="delete-'.$id.'" title="Datensatz löschen"><img src="'.$base.'assets/img/loeschen.png" alt=""></button>';
            echo '</td></tr>';

            if($allowDelete)echo '<form id="delete-'.$id.'" method="post" onsubmit="return confirm(\'Datensatz wirklich löschen?\')">'.$ctxHidden.'<input type="hidden" name="csrf" value="'.df_e(df_csrf()).'"><input type="hidden" name="action" value="delete"><input type="hidden" name="record" value="'.$id.'"><input type="hidden" name="active_record" value="'.$id.'"></form>';
            if($viewMode==='dialog')echo '<template id="dialog-record-'.$id.'">'.$recordForm($r,false).'</template>';
        }
        // PUBLISH18: Seitennavigation direkt unter den gespeicherten Datensätzen,
        // vor der *-Neuzeile.
        if($showPagination&&$all){
            $visibleFieldCount=0;foreach($fields as $pf)if((string)$pf['field_type']!=='hidden'&&empty($pf['cfg']['hidden']))$visibleFieldCount++;
            $colspan=$visibleFieldCount+4;
            $firstId=(int)$all[0]['id'];$lastId=(int)$all[count($all)-1]['id'];
            $nav='<div class="pagination"><button type="button" data-select-record="'.$firstId.'" title="Zum ersten Datensatz"><img src="'.$base.'assets/img/erster_ds.png" alt=""></button>';
            $start=max(1,$page-2);$end=min($pages,$page+2);
            if($start>1)$nav.='<span>…</span>';
            for($i=$start;$i<=$end;$i++)$nav.=$i===$page?'<strong>'.$i.'</strong>':'<a href="?page='.$i.$ctxQuery.'">'.$i.'</a>';
            if($end<$pages)$nav.='<span>…</span>';
            $nav.='<button type="button" data-select-record="'.$lastId.'" title="Zum letzten Datensatz"><img src="'.$base.'assets/img/letzter_ds.png" alt=""></button><span>Seite '.$page.' von '.$pages.'</span></div>';
            echo '<tr class="record-pagination-row"><td colspan="'.$colspan.'">'.$nav.'</td></tr>';
        }

        if($viewMode==='table'&&$allowCreate){
            echo '<tr id="new-record" class="new-row"><td><span class="new-pointer"><img src="'.$base.'assets/img/neuer_ds.png" alt="Neuer Datensatz"></span></td><td></td><td></td>';
            $newData=$parentContext!==null?[(string)$parentContext['lookup_field_name']=>(string)$parentContext['parent_record_id']]:[];
            foreach($fields as $f){if((string)$f['field_type']==='hidden'||!empty($f['cfg']['hidden']))continue;echo '<td>'.df_render_input($pdo,$f,$formId,$newData,0,$parentContext).'</td>';}
            echo '<td><button form="create-record" title="Datensatz anlegen"><img src="'.$base.'assets/img/neu.png" alt=""></button></td></tr>';
        }
        echo '</tbody></table></div>';

        // Die Inputs der Tabellenansicht werden den passenden POST-Formularen
        // über form=... zugeordnet. Das hält die sichtbare Zeile vollständig
        // editierbar, ohne verschachtelte Formulare im table-Markup zu erzeugen.
        if($viewMode==='table'){
            if($allowEdit)foreach($rows as $r){
                $id=(int)$r['id'];
                echo '<form id="update-'.$id.'" method="post" enctype="multipart/form-data">'.$ctxHidden.'<input type="hidden" name="csrf" value="'.df_e(df_csrf()).'"><input type="hidden" name="action" value="update"><input type="hidden" name="record" value="'.$id.'"><input type="hidden" name="active_record" value="'.$id.'"></form>';
            }
            if($allowCreate){
                echo '<form id="create-record" method="post" enctype="multipart/form-data">'.$ctxHidden.'<input type="hidden" name="csrf" value="'.df_e(df_csrf()).'"><input type="hidden" name="action" value="create"></form>';
            }
            echo '<script>(function(){document.querySelectorAll("tr[data-record-row]").forEach(function(row){const id=row.dataset.recordRow;row.querySelectorAll("input,select,textarea").forEach(function(c){if(!c.form)c.setAttribute("form","update-"+id);});});const nr=document.getElementById("new-record");if(nr)nr.querySelectorAll("input,select,textarea").forEach(function(c){if(!c.form)c.setAttribute("form","create-record");});})();</script>';
        }

        if($allowDelete&&$rows)echo '<div class="bulk-actions"><button form="bulk-delete" title="Ausgewählte Datensätze löschen" onclick="return confirm(\'Ausgewählte Datensätze löschen?\')"><img src="'.$base.'assets/img/loeschen.png" alt=""></button></div>';

        if($childRelations)echo '<section id="child-collections-panel" class="child-collections-panel" data-child-collections data-parent-form="'.$formId.'">'.($active>0?df_child_collections_html($pdo,$formId,$active,$base):'<section class="card empty-children">Eltern-Datensatz auswählen, um die zugehörigen Kinddatensätze anzuzeigen.</section>').'</section>';
        if($viewMode==='dialog'){
            if($allowCreate)echo '<template id="dialog-new-record">'.$recordForm([],true).'</template>';
            echo '<dialog id="runtime-dialog" class="runtime-dialog size-'.df_e($dialogSize).'"><div class="dialog-head"><strong data-dialog-title>Datensatz</strong><button type="button" class="dialog-close" data-dialog-close aria-label="Schließen">×</button></div><div class="dialog-body" data-dialog-body></div></dialog>';
        }
    }

    if($detail&&$viewMode==='table'){
        echo '<section class="card detail"><h2>Datensatz #'.$detailId.'</h2><dl>';
        foreach($fields as $f){if((string)$f['field_type']==='hidden'||!empty($f['cfg']['hidden']))continue;echo '<dt>'.df_e((string)$f['label']).'</dt><dd>'.df_display_cell($base,$formId,$detailId,$f,$detail['data'][(string)$f['name']]??'').'</dd>';}
        echo '</dl></section>';
    }

    $eventJson=json_encode($events,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG);
    $recordEventJson=json_encode($recordEvents,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG);
    echo '<script type="application/json" id="df-event-handlers">'.($eventJson?:'{}').'</script><script type="application/json" id="df-recordset-event-handlers">'.($recordEventJson?:'{}').'</script><script>window.DF_RUNTIME_SETTINGS='.json_encode(['view_mode'=>$viewMode,'dialog_size'=>$dialogSize,'fulltext_search'=>$showSearch,'filter'=>$showFilter,'allow_create'=>$allowCreate,'allow_edit'=>$allowEdit,'allow_delete'=>$allowDelete,'last_action'=>$lastAction,'message'=>$message,'action_context'=>$actionContext],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG).';</script>';
    $embedScript='';if((int)($_GET['dialog_embed']??0)===1&&in_array($lastAction,['after_save','after_delete'],true)){$type=$lastAction==='after_delete'?'easyit-dataform-dialog-deleted':'easyit-dataform-dialog-saved';$embedScript='<script>if(window.parent!==window){window.parent.postMessage({type:"'.$type.'"},window.location.origin);}</script>'; } echo '</main><script src="'.$base.'assets/app.js"></script>'.$embedScript.'</body></html>';
}
function df_display_cell(string $base,int $formId,int $recordId,array $field,mixed $value): string { $t=(string)$field['field_type']; if(in_array($t,['file','image'],true)){ $m=df_media_meta((string)$value);if(!$m)return '';if($t==='image')return '<a href="'.$base.'media.php?dataform='.$formId.'&record='.$recordId.'&field='.rawurlencode((string)$field['name']).'" target="_blank"><img class="thumb" src="'.$base.'media.php?dataform='.$formId.'&record='.$recordId.'&field='.rawurlencode((string)$field['name']).'" alt=""></a>';return '<a href="'.$base.'media.php?dataform='.$formId.'&record='.$recordId.'&field='.rawurlencode((string)$field['name']).'">'.df_e($m['name']).'</a>'; } if($t==='link'){ $x=json_decode((string)$value,true);if(is_array($x)&&filter_var((string)($x['url']??''),FILTER_VALIDATE_URL))return '<a href="'.df_e($x['url']).'" target="'.df_e($x['target']??'_self').'">'.df_e($x['label']??$x['url']).'</a>'; } return nl2br(df_e(df_display_value($field,$value))); }
