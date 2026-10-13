<?php
declare(strict_types=1);
ob_start(); // Never let PHP warnings/notices corrupt the JSON API.
// db.php starts the session if necessary and installs the JSON response contract.
require __DIR__.'/db.php';
$root=dirname(__DIR__,3);
require_once $root.'/system/app/bootstrap.php';
require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
$user=enterprise_require_auth('../../../');
$projectId=(int)($_SESSION['easyit_calendar_project_id'] ?? 0);
if($projectId<1) throw new RuntimeException('Calendar-Projektkontext fehlt.');
enterprise_require_project_scope($user,$projectId,true);
$adminPdo=enterprise_pdo(); enterprise_upgrade($adminPdo);
$ps=$adminPdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1'); $ps->execute([$projectId]); $project=$ps->fetch(PDO::FETCH_ASSOC);
if(!$project || (string)$project['product_type']!=='calendar') throw new RuntimeException('Calendar-Projekt nicht gefunden.');
enterprise_require_project_product($user,$projectId,'calendar',(string)$project['slug']);
function calendar_admin_driver(): string { $ctx=easyit_calendar_project_context(); return easyit_calendar_driver($ctx); }
function ident(string $s): string {
 if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$s)) throw new InvalidArgumentException('Ungültiger Bezeichner.');
 $d=calendar_admin_driver(); return match($d){'mysql','csv'=>'`'.$s.'`','mssql'=>'['.$s.']',default=>'"'.$s.'"'};
}
function tables(PDO $p): array {
 $d=calendar_admin_driver();
 if($d==='csv' && method_exists($p,'tableNames')) return $p->tableNames();
 if($d==='sqlite') return array_column($p->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(),'name');
 if($d==='pgsql') return array_column($p->query("SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema() AND table_type='BASE TABLE' ORDER BY table_name")->fetchAll(),'table_name');
 if($d==='mssql') return array_column($p->query("SELECT name FROM sys.tables ORDER BY name")->fetchAll(),'name');
 $q=$p->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"); return array_column($q->fetchAll(),'TABLE_NAME');
}
function calendar_admin_semantic_types(PDO $p,string $t): array {
 try{
   $df=easyit_calendar_df_for_table($p,$t); if($df<1)return [];
   $st=$p->prepare('SELECT name,field_type FROM dataform_fields WHERE dataform_id=?');$st->execute([$df]);$out=[];
   foreach($st->fetchAll(PDO::FETCH_ASSOC) as $r)$out[(string)$r['name']]=(string)$r['field_type'];
   return $out;
 }catch(Throwable){return [];}
}
function calendar_admin_enrich_columns(PDO $p,string $t,array $cols): array {
 $types=calendar_admin_semantic_types($p,$t);
 foreach($cols as &$c){$n=(string)($c['COLUMN_NAME']??'');$c['SEMANTIC_TYPE']=$types[$n]??'';}unset($c);
 return $cols;
}
function cols(PDO $p,string $t): array {
 $d=calendar_admin_driver(); ident($t);
 if($d==='csv' && method_exists($p,'columnNames')) return calendar_admin_enrich_columns($p,$t,array_map(fn($n)=>['COLUMN_NAME'=>$n,'DATA_TYPE'=>'text','COLUMN_TYPE'=>'text','IS_NULLABLE'=>'YES','COLUMN_DEFAULT'=>null,'COLUMN_KEY'=>$n==='id'?'PRI':'','EXTRA'=>$n==='id'?'auto_increment':''],$p->columnNames($t)));
 if($d==='sqlite'){$q=$p->query('PRAGMA table_info('.ident($t).')');return calendar_admin_enrich_columns($p,$t,array_map(fn($c)=>['COLUMN_NAME'=>$c['name'],'DATA_TYPE'=>$c['type'],'COLUMN_TYPE'=>$c['type'],'IS_NULLABLE'=>((int)$c['notnull']===1?'NO':'YES'),'COLUMN_DEFAULT'=>$c['dflt_value'],'COLUMN_KEY'=>((int)$c['pk']===1?'PRI':''),'EXTRA'=>((int)$c['pk']===1?'auto_increment':'')],$q->fetchAll()));}
 if($d==='pgsql'){$s=$p->prepare("SELECT c.column_name AS \"COLUMN_NAME\",c.data_type AS \"DATA_TYPE\",c.udt_name AS \"COLUMN_TYPE\",c.is_nullable AS \"IS_NULLABLE\",c.column_default AS \"COLUMN_DEFAULT\",CASE WHEN tc.constraint_type='PRIMARY KEY' THEN 'PRI' ELSE '' END AS \"COLUMN_KEY\",CASE WHEN c.column_default LIKE 'nextval(%' THEN 'auto_increment' ELSE '' END AS \"EXTRA\" FROM information_schema.columns c LEFT JOIN information_schema.key_column_usage kcu ON kcu.table_schema=c.table_schema AND kcu.table_name=c.table_name AND kcu.column_name=c.column_name LEFT JOIN information_schema.table_constraints tc ON tc.constraint_name=kcu.constraint_name AND tc.constraint_type='PRIMARY KEY' WHERE c.table_schema=current_schema() AND c.table_name=? ORDER BY c.ordinal_position");$s->execute([$t]);return calendar_admin_enrich_columns($p,$t,$s->fetchAll());}
 if($d==='mssql'){$s=$p->prepare("SELECT c.name AS COLUMN_NAME,ty.name AS DATA_TYPE,ty.name AS COLUMN_TYPE,CASE WHEN c.is_nullable=1 THEN 'YES' ELSE 'NO' END AS IS_NULLABLE,OBJECT_DEFINITION(c.default_object_id) AS COLUMN_DEFAULT,CASE WHEN ic.column_id IS NOT NULL THEN 'PRI' ELSE '' END AS COLUMN_KEY,CASE WHEN c.is_identity=1 THEN 'auto_increment' ELSE '' END AS EXTRA FROM sys.columns c JOIN sys.types ty ON c.user_type_id=ty.user_type_id LEFT JOIN sys.indexes i ON i.object_id=c.object_id AND i.is_primary_key=1 LEFT JOIN sys.index_columns ic ON ic.object_id=i.object_id AND ic.index_id=i.index_id AND ic.column_id=c.column_id WHERE c.object_id=OBJECT_ID(?) ORDER BY c.column_id");$s->execute([$t]);return calendar_admin_enrich_columns($p,$t,$s->fetchAll());}
 $s=$p->prepare("SELECT COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION");$s->execute([$t]);return calendar_admin_enrich_columns($p,$t,$s->fetchAll());
}
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='POST') throw new RuntimeException('Nur POST.',405);
 $x=request_json(); $a=(string)($x['action']??''); $p=db();
 if($a==='tables') json_response(['ok'=>true,'tables'=>tables($p)]);
 if($a==='columns'){ $t=(string)$x['table']; json_response(['ok'=>true,'columns'=>cols($p,$t)]); }
 if($a==='create_table'){
   $n=(string)($x['name']??''); ident($n); if(in_array($n,tables($p),true)) throw new InvalidArgumentException('Tabelle existiert bereits.');
   $d=calendar_admin_driver();
   if($d==='csv' && method_exists($p,'createDataTable')) $p->createDataTable($n,['id']);
   else { $idDef=match($d){'pgsql'=>'BIGSERIAL PRIMARY KEY','sqlite'=>'INTEGER PRIMARY KEY AUTOINCREMENT','mssql'=>'BIGINT IDENTITY(1,1) PRIMARY KEY',default=>'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'}; $suffix=$d==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':''; $p->exec('CREATE TABLE '.ident($n).' ('.ident('id').' '.$idDef.')'.$suffix); } json_response(['ok'=>true]);
 }
 if($a==='drop_table'){ $n=(string)$x['table']; if(in_array($n,['events','event_participants','event_attachments','persons','places','resources','categories','users','roles','permissions','main_settings','calendar_mappings'],true)) throw new InvalidArgumentException('Systemtabelle ist geschützt.'); if(calendar_admin_driver()==='csv' && method_exists($p,'dropDataTable')) $p->dropDataTable($n); else $p->exec('DROP TABLE '.ident($n)); json_response(['ok'=>true]); }
 if($a==='add_column'){
   $t=(string)$x['table'];$n=(string)$x['name'];$type=strtoupper((string)($x['type']??'VARCHAR'));
   $allowed=['VARCHAR','TEXT','INT','BIGINT','DECIMAL','DATE','DATETIME','TIME','TINYINT','COLOR']; if(!in_array($type,$allowed,true)) throw new InvalidArgumentException('Datentyp nicht erlaubt.');
   $sqlType=$type==='COLOR'?'VARCHAR(7)':($type==='VARCHAR'?'VARCHAR(255)':($type==='DECIMAL'?'DECIMAL(18,4)':$type)); $null=!empty($x['nullable'])?' NULL':' NOT NULL';
   if(calendar_admin_driver()==='csv' && method_exists($p,'addDataColumn')) $p->addDataColumn($t,$n); else $p->exec('ALTER TABLE '.ident($t).' ADD COLUMN '.ident($n).' '.$sqlType.$null);
   if($type==='COLOR'){
     $df=easyit_calendar_df_for_table($p,$t);
     if($df>0){
       $st=$p->prepare('SELECT id FROM dataform_fields WHERE dataform_id=? AND name=? LIMIT 1');$st->execute([$df,$n]);$fieldId=(int)($st->fetchColumn()?:0);
       if($fieldId>0)$p->prepare("UPDATE dataform_fields SET field_type='color' WHERE id=?")->execute([$fieldId]);
       else{$pos=(int)($p->query('SELECT COALESCE(MAX(position),0)+10 FROM dataform_fields WHERE dataform_id='.(int)$df)->fetchColumn()?:10);$label=ucwords(str_replace('_',' ',$n));$p->prepare('INSERT INTO dataform_fields(dataform_id,name,label,field_type,position,is_required,configuration_json) VALUES(?,?,?,?,?,?,?)')->execute([$df,$n,$label,'color',$pos,!empty($x['nullable'])?0:1,'{}']);}
     }
   }
   json_response(['ok'=>true]);
 }
 if($a==='drop_column'){ $t=(string)$x['table'];$n=(string)$x['name']; if($n==='id') throw new InvalidArgumentException('Primärschlüssel id ist geschützt.'); if(calendar_admin_driver()==='csv' && method_exists($p,'dropDataColumn')) $p->dropDataColumn($t,$n); else $p->exec('ALTER TABLE '.ident($t).' DROP COLUMN '.ident($n));json_response(['ok'=>true]); }
 if($a==='rows'){
   $t=(string)$x['table']; $cs=cols($p,$t); if(!$cs) throw new InvalidArgumentException('Tabelle nicht gefunden.'); $sql=calendar_admin_driver()==='mssql'?'SELECT TOP 200 * FROM '.ident($t).' ORDER BY 1 DESC':'SELECT * FROM '.ident($t).' ORDER BY 1 DESC LIMIT 200'; $q=$p->query($sql); json_response(['ok'=>true,'columns'=>$cs,'rows'=>$q->fetchAll()]);
 }
 if($a==='save_row'){
   $t=(string)$x['table'];$data=is_array($x['data']??null)?$x['data']:[];$cs=cols($p,$t);$names=array_column($cs,'COLUMN_NAME');$pk=null;foreach($cs as $c)if($c['COLUMN_KEY']==='PRI'){$pk=$c['COLUMN_NAME'];break;}
   $clean=[];foreach($data as $k=>$v)if(in_array($k,$names,true)&&$k!==$pk){
     if(is_string($v) && preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}/',$v)) $v=str_replace('T',' ',$v);
     $clean[$k]=$v===''?null:$v;
   }
   $id=$x['id']??null;
   if($id!==null&&$id!==''&&$pk){$set=[];$vals=[];foreach($clean as $k=>$v){$set[]=ident($k).'=?';$vals[]=$v;}$vals[]=$id;if($set)$p->prepare('UPDATE '.ident($t).' SET '.implode(',',$set).' WHERE '.ident($pk).'=?')->execute($vals);}
   else {if(!$clean) throw new InvalidArgumentException('Keine Daten.');$ks=array_keys($clean);$p->prepare('INSERT INTO '.ident($t).' ('.implode(',',array_map('ident',$ks)).') VALUES ('.implode(',',array_fill(0,count($ks),'?')).')')->execute(array_values($clean));}
   json_response(['ok'=>true]);
 }
 if($a==='delete_row'){ $t=(string)$x['table'];$cs=cols($p,$t);$pk=null;foreach($cs as $c)if($c['COLUMN_KEY']==='PRI'){$pk=$c['COLUMN_NAME'];break;}if(!$pk)throw new InvalidArgumentException('Kein Primärschlüssel.');$p->prepare('DELETE FROM '.ident($t).' WHERE '.ident($pk).'=?')->execute([$x['id']]);json_response(['ok'=>true]); }
 if($a==='get_mapping'){
   $cfg=enterprise_calendar_config_load($project);$ctx=['project'=>$project,'env'=>enterprise_env($root.'/DataForm5-Core/.env'),'config'=>$cfg];
   json_response(['ok'=>true,'mapping'=>easyit_calendar_field_mapping($ctx)]);
 }
 if($a==='save_mapping'){
   $t=trim((string)($x['source_table']??'')); if($t===''||!in_array($t,tables($p),true))throw new InvalidArgumentException('Quelltabelle ist ungültig.');
   $available=array_column(cols($p,$t),'COLUMN_NAME');$raw=is_array($x['fields']??null)?$x['fields']:[];$fields=[];$seen=[];
   $core=['id','start','end','title'];
   foreach($raw as $entry){
     if(!is_array($entry))continue;$logical=strtolower(trim((string)($entry['logical']??'')));
     if(!preg_match('/^[a-z][a-z0-9_]{0,62}$/',$logical))throw new InvalidArgumentException('Logischer Feldname ist ungültig: '.$logical);
     if(isset($seen[$logical]))throw new InvalidArgumentException('Logische Zuordnung ist doppelt: '.$logical);$seen[$logical]=true;
     $field=trim((string)($entry['field']??''));if($field===''||!in_array($field,$available,true))throw new InvalidArgumentException('Quellfeld für '.$logical.' ist ungültig.');
     $kind=(string)($entry['kind']??'field');if(!in_array($kind,['field','lookup'],true))throw new InvalidArgumentException('Zuordnungstyp für '.$logical.' ist ungültig.');
     $def=['label'=>trim((string)($entry['label']??$logical))?:$logical,'field'=>$field,'kind'=>$kind,'required'=>in_array($logical,$core,true)];
     if($kind==='lookup'){
       $target=trim((string)($entry['target_table']??''));if($target===''||!in_array($target,tables($p),true))throw new InvalidArgumentException('Zieltabelle für '.$logical.' ist ungültig.');
       $targetCols=array_column(cols($p,$target),'COLUMN_NAME');$key=trim((string)($entry['target_key']??''));$label=trim((string)($entry['target_label']??''));
       if($key===''||!in_array($key,$targetCols,true))throw new InvalidArgumentException('Zielschlüssel für '.$logical.' ist ungültig.');
       if($label===''||!in_array($label,$targetCols,true))throw new InvalidArgumentException('Anzeigefeld für '.$logical.' ist ungültig.');
       $def['target_table']=$target;$def['target_key']=$key;$def['target_label']=$label;
     }
     $fields[$logical]=$def;
   }
   foreach($core as $logical)if(!isset($fields[$logical]))throw new InvalidArgumentException('Pflichtzuordnung fehlt: '.$logical);
   $cfg=enterprise_calendar_config_load($project);$cfg['mappings']['events']=$t;$cfg['field_mapping']=['source_table'=>$t,'fields'=>$fields];
   // Keep the former keys for older callers/packages while the new mapping is authoritative.
   $cfg['field_mapping']['id_field']=$fields['id']['field'];$cfg['field_mapping']['start_field']=$fields['start']['field'];$cfg['field_mapping']['end_field']=$fields['end']['field'];$cfg['field_mapping']['title_field']=$fields['title']['field'];
   $cfg['field_mapping']['description_field']=(string)($fields['description']['field']??'');$cfg['field_mapping']['active_field']=(string)($fields['active']['field']??'');
   enterprise_calendar_config_save($project,$cfg);
   $syncCtx=['project'=>$project,'env'=>enterprise_env($root.'/DataForm5-Core/.env'),'config'=>$cfg];
   $sync=easyit_calendar_dataform_ensure($p,$syncCtx);
   json_response(['ok'=>true,'mapping'=>$cfg['field_mapping'],'lookup_warnings'=>(array)($sync['warnings']??[])]);
 }
 throw new InvalidArgumentException('Unbekannte Aktion.');
}catch(InvalidArgumentException $e){json_response(['ok'=>false,'error'=>$e->getMessage()],400);}catch(Throwable $e){error_log($e->__toString());json_response(['ok'=>false,'error'=>$e->getMessage()],500);}
