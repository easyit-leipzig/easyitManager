<?php
declare(strict_types=1);

/** HF91 Phase 10: project-bound Calendar runtime and schema management. */
function easyit_calendar_root(): string { return dirname(__DIR__, 3); }

function easyit_calendar_project_context(): array {
    $root=easyit_calendar_root();
    require_once $root.'/system/app/bootstrap.php';
    $projectId=(int)($_SESSION['easyit_calendar_project_id'] ?? $_GET['project'] ?? $_POST['project'] ?? 0);
    if($projectId<1) throw new RuntimeException('Calendar-Projektkontext fehlt.',403);
    $admin=enterprise_pdo(); enterprise_upgrade($admin);
    $st=$admin->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');$st->execute([$projectId]);$project=$st->fetch(PDO::FETCH_ASSOC);
    if(!$project || (string)$project['product_type']!=='calendar') throw new RuntimeException('Calendar-Projekt nicht gefunden.',404);
    $env=enterprise_env($root.'/DataForm5-Core/.env');
    return ['project'=>$project,'env'=>$env,'config'=>enterprise_calendar_config_load($project)];
}
function easyit_calendar_ident(string $value): string {
    $value=trim($value); if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/',$value)) throw new RuntimeException('Ungültige Calendar-Tabellenzuordnung: '.$value);
    return $value;
}
function easyit_calendar_table(array $ctx,string $logical): string {
    return easyit_calendar_ident((string)($ctx['config']['mappings'][$logical]??$logical));
}
function easyit_calendar_db(?array &$ctx=null): PDO {
    $ctx=easyit_calendar_project_context();
    return enterprise_project_store_for_project($ctx['env'],$ctx['project']);
}
function easyit_calendar_driver(array $ctx): string { return enterprise_project_store_driver($ctx['env'],$ctx['project']); }
function easyit_calendar_field_mapping(array $ctx): array {
    $defaults=function_exists('enterprise_calendar_default_field_mapping')?enterprise_calendar_default_field_mapping():[
      'source_table'=>'events','fields'=>[
        'id'=>['label'=>'ID','field'=>'id','kind'=>'field','required'=>true],
        'start'=>['label'=>'Start','field'=>'start','kind'=>'field','required'=>true],
        'end'=>['label'=>'Ende','field'=>'end','kind'=>'field','required'=>true],
        'title'=>['label'=>'Titel','field'=>'thema','kind'=>'field','required'=>true],
        'description'=>['label'=>'Beschreibung','field'=>'description','kind'=>'field','required'=>false],
        'active'=>['label'=>'Aktiv','field'=>'activ','kind'=>'field','required'=>false],
        'all_day'=>['label'=>'Ganztägig','field'=>'all_day','kind'=>'field','required'=>false],
        'location'=>['label'=>'Ort','field'=>'location','kind'=>'lookup','required'=>false,'target_table'=>'places','target_key'=>'id','target_label'=>'name'],
        'resource'=>['label'=>'Ressource','field'=>'resource_id','kind'=>'lookup','required'=>false,'target_table'=>'resources','target_key'=>'id','target_label'=>'name'],
        'category'=>['label'=>'Kategorie','field'=>'category_id','kind'=>'lookup','required'=>false,'target_table'=>'categories','target_key'=>'id','target_label'=>'name'],
        'status'=>['label'=>'Status','field'=>'event_status','kind'=>'field','required'=>false],
      ],
    ];
    $raw=is_array($ctx['config']['field_mapping']??null)?$ctx['config']['field_mapping']:[];
    // Backward compatibility for the former six fixed fields. Explicit dynamic
    // fields, however, are authoritative so removed optional mappings stay gone.
    $legacy=['id'=>'id_field','start'=>'start_field','end'=>'end_field','title'=>'title_field','description'=>'description_field','active'=>'active_field'];
    $explicit=isset($raw['fields'])&&is_array($raw['fields']);
    $rawFields=$explicit?(array)$raw['fields']:[];
    foreach($legacy as $logical=>$oldKey){
        if(!$explicit&&isset($raw[$oldKey])&&trim((string)$raw[$oldKey])!=='')$rawFields[$logical]=array_replace((array)($defaults['fields'][$logical]??[]),['field'=>(string)$raw[$oldKey]]);
    }
    $fields=$explicit?[]:(array)$defaults['fields'];
    foreach($rawFields as $logical=>$def){
        if(!is_string($logical)||!is_array($def))continue;
        $fields[$logical]=array_replace((array)($defaults['fields'][$logical]??[]),$def);
    }
    return ['source_table'=>(string)($raw['source_table']??$defaults['source_table']),'fields'=>$fields];
}
function easyit_calendar_field_name(array $ctx,string $logical,string $fallback=''): string {
    $map=easyit_calendar_field_mapping($ctx);$name=trim((string)($map['fields'][$logical]['field']??$fallback));
    return $name!==''?easyit_calendar_ident($name):$fallback;
}
function easyit_calendar_field_definition(array $ctx,string $logical): array {
    $map=easyit_calendar_field_mapping($ctx);return is_array($map['fields'][$logical]??null)?$map['fields'][$logical]:[];
}
function easyit_calendar_table_exists(PDO $pdo,string $table,string $driver): bool {
    try {
        if($driver==='csv') return method_exists($pdo,'tableExists') && $pdo->tableExists($table);
        if($driver==='sqlite') { $s=$pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0; }
        if($driver==='pgsql') { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=?');$s->execute([$table]);return (int)$s->fetchColumn()>0; }
        if($driver==='mysql') { $s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$s->execute([$table]);return (int)$s->fetchColumn()>0; }
        if($driver==='mssql') { $s=$pdo->prepare("SELECT COUNT(*) FROM sys.tables WHERE name=?");$s->execute([$table]);return (int)$s->fetchColumn()>0; }
        if($driver==='oracle') { $s=$pdo->prepare('SELECT COUNT(*) FROM user_tables WHERE table_name=UPPER(?)');$s->execute([$table]);return (int)$s->fetchColumn()>0; }
    } catch(Throwable) { return false; }
    return false;
}
function easyit_calendar_schema_ident(string $name,string $driver): string {
    easyit_calendar_ident($name);
    return match($driver){'mysql','csv'=>'`'.$name.'`','mssql'=>'['.$name.']',default=>'"'.$name.'"'};
}
function easyit_calendar_schema_install(PDO $pdo,array $ctx): array {
    $d=easyit_calendar_driver($ctx); $t=[]; foreach(['events','participants','persons','places','resources','categories','attachments'] as $k)$t[$k]=easyit_calendar_table($ctx,$k); $t['blocks']='blocked_ranges';
    // CSV has its own schema API; SQL backends get a real relational base schema.
    if($d==='csv' && method_exists($pdo,'createDataTable')){
        $csvDefs=[
          $t['events']=>['id','start','end','all_day','location','resource_id','category_id','activ','thema','description','event_status','version_no','created_by','updated_by','created_at','updated_at'],
          $t['places']=>['id','name','street','postal_code','city','room','description','activ','created_at','updated_at'],
          $t['participants']=>['id','event_id','person_id','role_id','participation_status','is_primary'],
          $t['persons']=>['id','display_name','email','calendar_color','activ','created_at','updated_at'],
          $t['resources']=>['id','name','activ','created_at','updated_at'],
          $t['categories']=>['id','name','category_color','activ','created_at','updated_at'],
          $t['attachments']=>['id','event_id','original_name','stored_name','storage_path','activ','created_at'],
          $t['blocks']=>['id','person_id','start','end','category_id','reason','activ','created_by','created_at','updated_at'],
          'roles'=>['id','name','label','role_key','created_at'],
        ];
        $created=[]; foreach($csvDefs as $table=>$cols){if(!easyit_calendar_table_exists($pdo,$table,$d)){$pdo->createDataTable($table,$cols);$created[]=$table;}}
        if(method_exists($pdo,'columnNames')&&method_exists($pdo,'addDataColumn')){
            $cols=$pdo->columnNames($t['persons']);if(!in_array('calendar_color',$cols,true))$pdo->addDataColumn($t['persons'],'calendar_color');
            $cols=$pdo->columnNames($t['categories']);if(!in_array('category_color',$cols,true))$pdo->addDataColumn($t['categories'],'category_color');
            $cols=$pdo->columnNames($t['blocks']);if(!in_array('person_id',$cols,true))$pdo->addDataColumn($t['blocks'],'person_id');
        }
        return ['driver'=>$d,'created'=>$created,'tables'=>array_keys($csvDefs)];
    }
    $id = match($d){'mysql'=>'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY','pgsql'=>'BIGSERIAL PRIMARY KEY','sqlite'=>'INTEGER PRIMARY KEY AUTOINCREMENT','mssql'=>'BIGINT IDENTITY(1,1) PRIMARY KEY','oracle'=>'NUMBER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',default=>'INTEGER PRIMARY KEY'};
    $text = match($d){'oracle'=>'CLOB',default=>'TEXT'}; $dt=match($d){'oracle'=>'TIMESTAMP','pgsql'=>'TIMESTAMP','mssql'=>'DATETIME2',default=>'DATETIME'};
    $q=fn(string $n)=>easyit_calendar_schema_ident($n,$d);
    $defs=[
      $t['events']=>[$q('id')." $id",$q('start')." $dt NOT NULL",$q('end')." $dt NOT NULL",$q('all_day')." INTEGER NOT NULL DEFAULT 0",$q('location')." BIGINT",$q('resource_id')." BIGINT",$q('category_id')." BIGINT",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('thema')." VARCHAR(255)",$q('description')." $text",$q('event_status')." VARCHAR(30)",$q('version_no')." INTEGER NOT NULL DEFAULT 1",$q('created_by')." BIGINT",$q('updated_by')." BIGINT",$q('created_at')." $dt",$q('updated_at')." $dt"],
      $t['places']=>[$q('id')." $id",$q('name')." VARCHAR(255) NOT NULL",$q('street')." VARCHAR(255)",$q('postal_code')." VARCHAR(32)",$q('city')." VARCHAR(190)",$q('room')." VARCHAR(120)",$q('description')." $text",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('created_at')." $dt",$q('updated_at')." $dt"],
      $t['participants']=>[$q('id')." $id",$q('event_id')." BIGINT NOT NULL",$q('person_id')." BIGINT NOT NULL",$q('role_id')." BIGINT NOT NULL",$q('participation_status')." VARCHAR(30)",$q('is_primary')." INTEGER NOT NULL DEFAULT 1"],
      $t['persons']=>[$q('id')." $id",$q('display_name')." VARCHAR(255)",$q('email')." VARCHAR(255)",$q('calendar_color')." VARCHAR(7)",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('created_at')." $dt",$q('updated_at')." $dt"],
      $t['resources']=>[$q('id')." $id",$q('name')." VARCHAR(255)",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('created_at')." $dt",$q('updated_at')." $dt"],
      $t['categories']=>[$q('id')." $id",$q('name')." VARCHAR(255)",$q('category_color')." VARCHAR(7)",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('created_at')." $dt",$q('updated_at')." $dt"],
      $t['attachments']=>[$q('id')." $id",$q('event_id')." BIGINT NOT NULL",$q('original_name')." VARCHAR(255)",$q('stored_name')." VARCHAR(255)",$q('storage_path')." VARCHAR(500)",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('created_at')." $dt"],
      $t['blocks']=>[$q('id')." $id",$q('person_id')." BIGINT",$q('start')." $dt NOT NULL",$q('end')." $dt NOT NULL",$q('category_id')." BIGINT NOT NULL",$q('reason')." VARCHAR(255)",$q('activ')." INTEGER NOT NULL DEFAULT 1",$q('created_by')." BIGINT",$q('created_at')." $dt",$q('updated_at')." $dt"],
      'roles'=>[$q('id')." $id",$q('name')." VARCHAR(80)",$q('label')." VARCHAR(120)",$q('role_key')." VARCHAR(80)",$q('created_at')." $dt"],
    ];
    $created=[];$suffix=$d==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
    foreach($defs as $table=>$columns){ if(!easyit_calendar_table_exists($pdo,$table,$d)){ $pdo->exec('CREATE TABLE IF NOT EXISTS '.$q($table).' ('.implode(', ',$columns).')'.$suffix);$created[]=$table; } }
    // HF145: bestehende Calendar-Projekte erhalten die optionale Schüler-Terminfarbe ohne Neuaufbau.
    try {
        $personTable=$t['persons'];$hasColor=false;
        if($d==='sqlite'){$st=$pdo->query('PRAGMA table_info('.$q($personTable).')');foreach($st?$st->fetchAll(PDO::FETCH_ASSOC):[] as $r)if((string)($r['name']??'')==='calendar_color')$hasColor=true;}
        elseif($d==='pgsql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?');$st->execute([$personTable,'calendar_color']);$hasColor=(int)$st->fetchColumn()>0;}
        elseif($d==='mysql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$st->execute([$personTable,'calendar_color']);$hasColor=(int)$st->fetchColumn()>0;}
        elseif($d==='mssql'){$st=$pdo->prepare('SELECT COUNT(*) FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id WHERE t.name=? AND c.name=?');$st->execute([$personTable,'calendar_color']);$hasColor=(int)$st->fetchColumn()>0;}
        elseif($d==='oracle'){$st=$pdo->prepare('SELECT COUNT(*) FROM user_tab_columns WHERE table_name=UPPER(?) AND column_name=UPPER(?)');$st->execute([$personTable,'calendar_color']);$hasColor=(int)$st->fetchColumn()>0;}
        if(!$hasColor){$type=match($d){'oracle'=>'VARCHAR2(7)','mssql'=>'NVARCHAR(7)',default=>'VARCHAR(7)'};$pdo->exec('ALTER TABLE '.$q($personTable).' ADD '.$q('calendar_color').' '.$type);}
    } catch(Throwable $e) { throw new RuntimeException('Schüler-Terminfarbe konnte nicht eingerichtet werden: '.$e->getMessage(),0,$e); }
    // HF150: bestehende Calendar-Projekte erhalten eine optionale Kategoriefarbe für den linken Terminbalken.
    try {
        $categoryTable=$t['categories'];$hasCategoryColor=false;
        if($d==='sqlite'){$st=$pdo->query('PRAGMA table_info('.$q($categoryTable).')');foreach($st?$st->fetchAll(PDO::FETCH_ASSOC):[] as $r)if((string)($r['name']??'')==='category_color')$hasCategoryColor=true;}
        elseif($d==='pgsql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?');$st->execute([$categoryTable,'category_color']);$hasCategoryColor=(int)$st->fetchColumn()>0;}
        elseif($d==='mysql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$st->execute([$categoryTable,'category_color']);$hasCategoryColor=(int)$st->fetchColumn()>0;}
        elseif($d==='mssql'){$st=$pdo->prepare('SELECT COUNT(*) FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id WHERE t.name=? AND c.name=?');$st->execute([$categoryTable,'category_color']);$hasCategoryColor=(int)$st->fetchColumn()>0;}
        elseif($d==='oracle'){$st=$pdo->prepare('SELECT COUNT(*) FROM user_tab_columns WHERE table_name=UPPER(?) AND column_name=UPPER(?)');$st->execute([$categoryTable,'category_color']);$hasCategoryColor=(int)$st->fetchColumn()>0;}
        if(!$hasCategoryColor){$type=match($d){'oracle'=>'VARCHAR2(7)','mssql'=>'NVARCHAR(7)',default=>'VARCHAR(7)'};$pdo->exec('ALTER TABLE '.$q($categoryTable).' ADD '.$q('category_color').' '.$type);}
    } catch(Throwable $e) { throw new RuntimeException('Kategorie-Terminfarbe konnte nicht eingerichtet werden: '.$e->getMessage(),0,$e); }
    // HF153: Blockierungen können optional einer Person zugeordnet werden; NULL/0 = alle Personen.
    try {
        $blockTable=$t['blocks'];$hasBlockPerson=false;
        if($d==='sqlite'){$st=$pdo->query('PRAGMA table_info('.$q($blockTable).')');foreach($st?$st->fetchAll(PDO::FETCH_ASSOC):[] as $r)if((string)($r['name']??'')==='person_id')$hasBlockPerson=true;}
        elseif($d==='pgsql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=current_schema() AND table_name=? AND column_name=?');$st->execute([$blockTable,'person_id']);$hasBlockPerson=(int)$st->fetchColumn()>0;}
        elseif($d==='mysql'){$st=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$st->execute([$blockTable,'person_id']);$hasBlockPerson=(int)$st->fetchColumn()>0;}
        elseif($d==='mssql'){$st=$pdo->prepare('SELECT COUNT(*) FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id WHERE t.name=? AND c.name=?');$st->execute([$blockTable,'person_id']);$hasBlockPerson=(int)$st->fetchColumn()>0;}
        elseif($d==='oracle'){$st=$pdo->prepare('SELECT COUNT(*) FROM user_tab_columns WHERE table_name=UPPER(?) AND column_name=UPPER(?)');$st->execute([$blockTable,'person_id']);$hasBlockPerson=(int)$st->fetchColumn()>0;}
        if(!$hasBlockPerson){$type=match($d){'oracle'=>'NUMBER','mssql'=>'BIGINT',default=>'BIGINT'};$pdo->exec('ALTER TABLE '.$q($blockTable).' ADD '.$q('person_id').' '.$type);}
    } catch(Throwable $e) { throw new RuntimeException('Personenzuordnung für Blockierungen konnte nicht eingerichtet werden: '.$e->getMessage(),0,$e); }
    // Stable system roles. Do not force explicit identity values on engines where that is unsafe.
    try {
      $roles=$q('roles');$rk=$q('role_key');
      foreach([['admin','Administrator','admin'],['tutor','Tutor','tutor'],['student','Student','student']] as $r){$st=$pdo->prepare("SELECT ".$q('id')." FROM $roles WHERE $rk=?");$st->execute([$r[2]]);if(!$st->fetchColumn()){$st=$pdo->prepare("INSERT INTO $roles (".$q('name').','.$q('label').','.$rk.") VALUES (?,?,?)");$st->execute($r);}}
    } catch(Throwable $e) { throw $e; }
    return ['driver'=>$d,'created'=>$created,'tables'=>array_keys($defs)];
}
function easyit_calendar_maintenance_entity(array $ctx,string $entity): array {
    $map=[
      'persons'=>['table'=>easyit_calendar_table($ctx,'persons'),'fields'=>['display_name','email','calendar_color'],'required'=>'display_name','active'=>true],
      'places'=>['table'=>easyit_calendar_table($ctx,'places'),'fields'=>['name','street','postal_code','city','room','description'],'required'=>'name','active'=>true],
      'resources'=>['table'=>easyit_calendar_table($ctx,'resources'),'fields'=>['name'],'required'=>'name','active'=>true],
      'categories'=>['table'=>easyit_calendar_table($ctx,'categories'),'fields'=>['name','category_color'],'required'=>'name','active'=>true],
      'roles'=>['table'=>'roles','fields'=>['name','label','role_key'],'required'=>'role_key','active'=>false],
    ];
    if(!isset($map[$entity])) throw new RuntimeException('Unbekannter Calendar-Datenpflegebereich.');
    return $map[$entity];
}
function easyit_calendar_maintenance_list(PDO $pdo,array $ctx,string $entity): array {
    $m=easyit_calendar_maintenance_entity($ctx,$entity);$order=$entity==='persons'?'display_name':($entity==='roles'?'label':'name');
    $q=$pdo->query('SELECT * FROM '.$m['table'].' ORDER BY '.$order.' ASC');return $q?$q->fetchAll(PDO::FETCH_ASSOC):[];
}
function easyit_calendar_maintenance_save(PDO $pdo,array $ctx,string $entity,array $input): void {
    $m=easyit_calendar_maintenance_entity($ctx,$entity);$id=(int)($input['record_id']??0);$values=[];
    foreach($m['fields'] as $f)$values[$f]=trim((string)($input[$f]??''));
    if($values[$m['required']]==='')throw new RuntimeException('Pflichtfeld fehlt.');
    if($entity==='persons'&&$values['calendar_color']!==''&&!preg_match('/^#[0-9a-f]{6}$/i',$values['calendar_color']))throw new RuntimeException('Terminfarbe muss im Format #RRGGBB angegeben werden.');
    if($entity==='categories'&&$values['category_color']!==''&&!preg_match('/^#[0-9a-f]{6}$/i',$values['category_color']))throw new RuntimeException('Kategoriefarbe muss im Format #RRGGBB angegeben werden.');
    if($entity==='roles'&&!preg_match('/^[a-z][a-z0-9_-]{1,79}$/',$values['role_key']))throw new RuntimeException('Rollenschlüssel muss mit einem Kleinbuchstaben beginnen und darf nur a-z, 0-9, _ und - enthalten.');
    $now=date('Y-m-d H:i:s');
    if($id>0){$sets=[];$params=[];foreach($values as $f=>$v){$sets[]="$f=?";$params[]=$v;}if($m['active']){$sets[]='activ=?';$params[]=isset($input['activ'])?1:0;$sets[]='updated_at=?';$params[]=$now;}$params[]=$id;$q=$pdo->prepare('UPDATE '.$m['table'].' SET '.implode(',',$sets).' WHERE id=?');$q->execute($params);return;}
    $cols=array_keys($values);$params=array_values($values);if($m['active']){$cols[]='activ';$params[]=isset($input['activ'])?1:0;$cols[]='created_at';$params[]=$now;$cols[]='updated_at';$params[]=$now;}
    $q=$pdo->prepare('INSERT INTO '.$m['table'].' ('.implode(',',$cols).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');$q->execute($params);
}
function easyit_calendar_maintenance_remove(PDO $pdo,array $ctx,string $entity,int $id): void {
    if($id<1)throw new RuntimeException('Ungültiger Datensatz.');$m=easyit_calendar_maintenance_entity($ctx,$entity);
    if($m['active']){$q=$pdo->prepare('UPDATE '.$m['table'].' SET activ=? WHERE id=?');$q->execute([0,$id]);return;}
    if($entity==='roles'&&in_array($id,[1,2,3],true))throw new RuntimeException('Die Systemrollen Administrator, Tutor und Student dürfen nicht gelöscht werden.');
    $q=$pdo->prepare('DELETE FROM '.$m['table'].' WHERE id=?');$q->execute([$id]);
}
function easyit_calendar_schema_status(PDO $pdo,array $ctx): array {
    $d=easyit_calendar_driver($ctx);$checks=[];foreach(['events','participants','persons','places','resources','categories','attachments'] as $k){$name=easyit_calendar_table($ctx,$k);$checks[$k]=easyit_calendar_table_exists($pdo,$name,$d);} $checks['blocks']=easyit_calendar_table_exists($pdo,'blocked_ranges',$d);return ['ready'=>!in_array(false,$checks,true),'driver'=>$d,'tables'=>$checks];
}
