<?php
declare(strict_types=1);
require_once __DIR__.'/project_storage.php';
require_once __DIR__.'/project_store.php';

function enterprise_calendar_config_path(array $project): string {
    $dir=enterprise_project_storage_documents_path($project,'calendar');
    if(!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) throw new RuntimeException('Calendar-Konfigurationsordner konnte nicht angelegt werden.');
    return $dir.'/calendar-project.json';
}
function enterprise_calendar_default_field_mapping(): array {
    return [
      'source_table'=>'events',
      'fields'=>[
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
}
function enterprise_calendar_default_config(array $project): array {
    return [
      'version'=>4,'project_id'=>(int)$project['id'],'data_source'=>['driver'=>(string)$project['database_driver'],'database'=>(string)$project['database_name']],
      'calendar'=>['timezone'=>'Europe/Berlin','week_start'=>1,'workday_start'=>'08:00','workday_end'=>'18:00','slot_minutes'=>30,'default_view'=>'week','weekends'=>false],
      'maintenance'=>['soft_delete'=>true,'audit'=>true,'attachments'=>true],
      'mappings'=>['events'=>'events','participants'=>'event_participants','persons'=>'persons','places'=>'places','resources'=>'resources','categories'=>'categories','attachments'=>'event_attachments'],
      'field_mapping'=>enterprise_calendar_default_field_mapping(),
      'dataforms'=>['events'=>0,'participants'=>0,'persons'=>0,'places'=>0,'resources'=>0,'categories'=>0,'attachments'=>0,'roles'=>0],
      'term_dialog'=>['dataform_id'=>0,'mode'=>'dialog','use_dataform'=>true,'close_after_save'=>true],
      'delivery'=>['include_csv_engine'=>(string)$project['database_driver']==='csv','include_help'=>true,'include_migrations'=>true],
      'updated_at'=>gmdate('c')
    ];
}
function enterprise_calendar_config_load(array $project): array {
    $d=enterprise_calendar_default_config($project);$f=enterprise_calendar_config_path($project);
    if(!is_file($f))return $d;$x=json_decode((string)file_get_contents($f),true);if(!is_array($x))return $d;
    $merged=array_replace_recursive($d,$x);
    // Dynamic Calendar field mappings are a replace-list, not a merge-list: if
    // an administrator removes an optional mapping, it must stay removed.
    if(isset($x['field_mapping']['fields'])&&is_array($x['field_mapping']['fields'])){
        $merged['field_mapping']['fields']=$x['field_mapping']['fields'];
    } elseif(isset($x['field_mapping'])&&is_array($x['field_mapping'])) {
        // Upgrade the former six fixed mapping keys into the dynamic structure.
        $legacy=['id'=>'id_field','start'=>'start_field','end'=>'end_field','title'=>'title_field','description'=>'description_field','active'=>'active_field'];
        foreach($legacy as $logical=>$oldKey){
            if(array_key_exists($oldKey,$x['field_mapping'])){
                $field=trim((string)$x['field_mapping'][$oldKey]);
                if($field==='')unset($merged['field_mapping']['fields'][$logical]);
                else $merged['field_mapping']['fields'][$logical]['field']=$field;
            }
        }
    }
    return $merged;
}
function enterprise_calendar_config_save(array $project,array $config): void {
    $config['version']=4;$config['project_id']=(int)$project['id'];$config['updated_at']=gmdate('c');
    $json=json_encode($config,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    if(file_put_contents(enterprise_calendar_config_path($project),$json."\n",LOCK_EX)===false)throw new RuntimeException('Calendar-Konfiguration konnte nicht gespeichert werden.');
}
function enterprise_calendar_setup_reconcile(array $project,array $env): array {
    $root=dirname(__DIR__,2);
    require_once $root.'/products/calendar/api/runtime.php';
    require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
    $cfg=enterprise_calendar_config_load($project);
    $pdo=enterprise_project_store_for_project($env,$project);
    $ctx=['project'=>$project,'env'=>$env,'config'=>$cfg];
    $integration=easyit_calendar_dataform_ensure($pdo,$ctx);
    foreach((array)($integration['bindings']??[]) as $logical=>$binding){
        $cfg['dataforms'][$logical]=(int)($binding['id']??0);
    }
    $events=(int)($cfg['dataforms']['events']??0);
    if($events>0){
        $cfg['term_dialog']['dataform_id']=$events;
        $cfg['term_dialog']['use_dataform']=true;
    }
    enterprise_calendar_config_save($project,$cfg);
    return ['pdo'=>$pdo,'ctx'=>['project'=>$project,'env'=>$env,'config'=>$cfg],'integration'=>$integration,'config'=>$cfg];
}
// HF120 compatibility marker: Calendar ships places and requires >=8 embedded bindings.
function enterprise_calendar_setup_status(array $project,array $env): array {
    $cfg=enterprise_calendar_config_load($project);$checks=[];$details=[];
    $checks['storage']=is_dir(enterprise_project_storage_root($project));
    $checks['data_source']=enterprise_project_store_exists_for_project($env,$project);
    $checks['mapping']=count(array_filter((array)$cfg['mappings'],fn($v)=>trim((string)$v)!==''))>=4;
    $checks['csv_engine']=(string)$project['database_driver']!=='csv'||is_file(dirname(__DIR__,2).'/products/csv-engine/index.php');
    $checks['runtime']=is_file(dirname(__DIR__,2).'/products/calendar/index.php');
    $checks['calendar_schema']=false;$checks['dataforms']=false;$checks['term_dataform']=false;
    if($checks['data_source']){
        try{
            $root=dirname(__DIR__,2);
            require_once $root.'/products/calendar/api/runtime.php';
            require_once $root.'/products/calendar/system/CalendarDataFormBridge.php';
            $pdo=enterprise_project_store_for_project($env,$project);
            $ctx=['project'=>$project,'env'=>$env,'config'=>$cfg];
            $schema=easyit_calendar_schema_status($pdo,$ctx);
            $bindings=easyit_calendar_dataform_bindings($pdo,$ctx);
            $bound=count(array_filter($bindings,static fn(array $b):bool=>(int)($b['id']??0)>0));
            $events=(int)($bindings['events']['id']??0);
            $checks['calendar_schema']=!empty($schema['ready']);
            $checks['dataforms']=$bound===count(easyit_calendar_dataform_specs($ctx));
            $checks['term_dataform']=$events>0;
            $details['schema']=$schema;$details['bindings']=$bindings;$details['binding_count']=$bound;
        }catch(Throwable $e){$details['inspection_error']=$e->getMessage();}
    }
    $missing=array_keys(array_filter($checks,static fn(bool $ok):bool=>!$ok));
    return ['ready'=>$missing===[],'checks'=>$checks,'missing'=>$missing,'details'=>$details,'config'=>$cfg];
}

function enterprise_calendar_zip_tree(ZipArchive $zip,string $source,string $target): int {
    if(!is_dir($source))return 0;$real=realpath($source);if($real===false)return 0;$n=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
    foreach($it as $f){if(!$f instanceof SplFileInfo||!$f->isFile()||$f->isLink())continue;$rel=ltrim(str_replace('\\','/',substr($f->getPathname(),strlen($real))),'/');if(!$zip->addFile($f->getPathname(),trim($target,'/').'/'.$rel))throw new RuntimeException('Calendar-Paketdatei konnte nicht hinzugefügt werden: '.$rel);$n++;}return $n;
}
function enterprise_calendar_distribution_create(array $env,array $project): array {
    if(!class_exists('ZipArchive'))throw new RuntimeException('ext-zip / ZipArchive fehlt.');
    $root=dirname(__DIR__,2);
    // HF124: Export is self-healing. A stale project-config.json must never block
    // an otherwise healthy Calendar project. Bindings/schema are reconciled from
    // the real project store before the readiness gate is evaluated.
    try{enterprise_calendar_setup_reconcile($project,$env);}catch(Throwable $e){
        throw new RuntimeException('Calendar-Setup konnte vor dem Export nicht synchronisiert werden: '.$e->getMessage(),0,$e);
    }
    $status=enterprise_calendar_setup_status($project,$env);
    if(!$status['ready']){
        $missing=implode(', ',(array)($status['missing']??[]));
        $extra=(string)($status['details']['inspection_error']??'');
        throw new RuntimeException('Calendar-Setup ist nicht READY. Fehlend: '.($missing!==''?$missing:'unbekannt').($extra!==''?' · '.$extra:''));
    }
    $driver=(string)$project['database_driver'];
    $out=enterprise_project_storage_export_path($project,'calendar-packages');
    if(!is_dir($out)&&!mkdir($out,0770,true)&&!is_dir($out))throw new RuntimeException('Exportordner konnte nicht erstellt werden.');
    $slug=preg_replace('/[^a-z0-9-]+/','-',strtolower((string)$project['slug']))?:'calendar';
    $filename='easyITCalendar-'.$slug.'-'.gmdate('Ymd_His').'.zip';$path=$out.'/'.$filename;
    $zip=new ZipArchive();if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP konnte nicht erzeugt werden.');
    $files=0;$cfg=enterprise_calendar_config_load($project);
    try{
      // Stand-alone application runtime. Do not copy products/calendar/index.php:
      // it intentionally depends on Enterprise authentication and can never run
      // as /htdocs/easyITCalendar/index.php.
      $runtime=$root.'/system/app/calendar_runtime';
      foreach(['index.php','api.php','api-v1.php','api-tokens.php','term.php','lib/bootstrap.php','lib/DataFormApp.php','bootstrap.php','src/Calendar.php','src/BaseRepository.php','src/EventRepository.php','src/PlaceRepository.php','src/CategoryRepository.php','setup.php','auth.php','login.php','logout.php','admin.php'] as $required){
          if(!is_file($runtime.'/'.$required))throw new RuntimeException('Calendar-Standalone-Runtime fehlt: '.$required);
      }
      foreach(['index.php','api.php','api-v1.php','api-tokens.php','setup.php','auth.php','login.php','logout.php','admin.php','bootstrap.php'] as $f){$zip->addFile($runtime.'/'.$f,'easyITCalendar/'.$f);$files++;}
      $zip->addFile($runtime.'/term.php','easyITCalendar/dataforms/term.php');$files++;
      foreach(['bootstrap.php','DataFormApp.php'] as $f){$zip->addFile($runtime.'/lib/'.$f,'easyITCalendar/lib/'.$f);$files++;}
      $files+=enterprise_calendar_zip_tree($zip,$runtime.'/src','easyITCalendar/src');
      $files+=enterprise_calendar_zip_tree($zip,$runtime.'/examples','easyITCalendar/examples');
      $files+=enterprise_calendar_zip_tree($zip,$runtime.'/config','easyITCalendar/config');
      $files+=enterprise_calendar_zip_tree($zip,$runtime.'/public','easyITCalendar/public');
      $files+=enterprise_calendar_zip_tree($zip,$root.'/products/calendar/assets','easyITCalendar/assets');
      if(is_file($root.'/products/calendar/init.json')){$zip->addFile($root.'/products/calendar/init.json','easyITCalendar/init.json');$files++;}
      $files+=enterprise_calendar_zip_tree($zip,$root.'/products/calendar/help','easyITCalendar/help');
      $files+=enterprise_calendar_zip_tree($zip,$runtime.'/docs','easyITCalendar/docs');
      // HF105 compatibility marker: 'dependencies'=>['dataform-core'] and /products/dataform.
      // HF124 embeds the required DataForm runtime instead of copying the Enterprise product surface.
      // Generic DataForm runtime assets and CSV adapter dependencies.
      foreach(['app.css','app.js'] as $f){$zip->addFile($root.'/system/app/project_runtime/assets/'.$f,'easyITCalendar/assets/'.$f);$files++;}
      $images=['easyit-epManager-logo.png','neu.png','speichern.png','anzeigen.png','bearbeiten.png','loeschen.png','erster_ds.png','vorheriger_ds.png','normaler_ds.png','aktueller_ds.png','naechster_ds.png','neuer_ds.png','letzter_ds.png','suchen.png','filter.png','filter_loeschen.png'];
      foreach($images as $image){$src=$root.'/assets/img/'.$image;if(is_file($src)){$zip->addFile($src,'easyITCalendar/assets/img/'.$image);$files++;}}
      foreach(['EnterpriseCsvPdo.php','EnterpriseSqlitePdo.php','EnterprisePgsqlPdo.php','EnterpriseOraclePdo.php','EnterpriseMssqlPdo.php','EnterpriseMssqlAdapter.php'] as $f){$src=$root.'/system/app/'.$f;if(is_file($src)){$zip->addFile($src,'easyITCalendar/system/app/'.$f);$files++;}}
      $files+=enterprise_calendar_zip_tree($zip,$root.'/DataForm5-Core/system/database','easyITCalendar/DataForm5-Core/system/database');
      $projectCfg=[
        'db_driver'=>$driver,
        'db_host'=>'127.0.0.1','db_port'=>$driver==='pgsql'?5432:($driver==='mssql'?1433:3306),
        'db_name'=>$driver==='csv'?'csv':(string)$project['database_name'],'db_schema'=>'public','db_user'=>'','db_password'=>'',
        'project_id'=>(int)$project['id'],'project_name'=>(string)$project['name'],'storage_key'=>(string)$project['storage_key'],
        'term_dataform_id'=>(int)($cfg['term_dialog']['dataform_id']??0),'calendar'=>(array)($cfg['calendar']??[]),
        'field_mapping'=>(array)($cfg['field_mapping']??[]),'mappings'=>(array)($cfg['mappings']??[]),'dataforms'=>(array)($cfg['dataforms']??[]),
        'close_after_save'=>!empty($cfg['term_dialog']['close_after_save']),
      ];
      if($driver==='csv'){
          $csv=enterprise_project_storage_csv_path($project);$files+=enterprise_calendar_zip_tree($zip,$csv,'easyITCalendar/storage/csv');
          $zip->addFromString('easyITCalendar/config.php',"<?php\ndeclare(strict_types=1);\nreturn ".var_export($projectCfg,true).";\n");$files++;
      }elseif($driver==='sqlite'){
          $sqlite=enterprise_project_storage_sqlite_file($project);if(is_file($sqlite)){$zip->addFile($sqlite,'easyITCalendar/storage/sqlite/database.sqlite');$files++;}
          $projectCfg['db_name']='storage/sqlite/database.sqlite';$zip->addFromString('easyITCalendar/config.php',"<?php\ndeclare(strict_types=1);\nreturn ".var_export($projectCfg,true).";\n");$files++;
      }else{
          // External SQL credentials are deliberately never exported.
          $zip->addFromString('easyITCalendar/config.php.example',"<?php\ndeclare(strict_types=1);\nreturn ".var_export($projectCfg,true).";\n");$files++;
      }
      $zip->addFromString('easyITCalendar/project-config.json',json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$files++;
      $manifest=['product'=>'calendar','standalone'=>true,'project_id'=>(int)$project['id'],'storage_driver'=>$driver,'dependencies'=>['embedded-dataform-runtime'],'generated_at'=>gmdate('c'),'runtime'=>'HF150','auth_roles'=>['admin','editor','viewer'],'categorized_blocks'=>true];
      if($driver==='csv')$manifest['dependencies'][]='csv-runtime';
      $zip->addFromString('easyITCalendar/delivery-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$files++;
      $readme="easyITCalendar – eigenständiges Anwenderpaket (HF150)\n\nCSV/SQLite: Ordner unter einem PHP-Webserver bereitstellen und index.php öffnen.\nExterne SQL-Datenbanken: config.php.example nach config.php kopieren und Zugangsdaten über setup.php eintragen.\nDas Paket benötigt keinen Enterprise-Manager-Bootstrap.\nPHP-Integration: require_once __DIR__.'/bootstrap.php'; anschließend new Calendar().\n";
      $zip->addFromString('easyITCalendar/README.txt',$readme);$files++;
    }finally{$zip->close();}
    if(!is_file($path)||filesize($path)<1)throw new RuntimeException('Calendar-Anwenderpaket wurde nicht erzeugt.');
    return ['path'=>$path,'filename'=>$filename,'sha256'=>hash_file('sha256',$path),'size'=>filesize($path),'files'=>$files,'stats'=>['files'=>$files]];
}
