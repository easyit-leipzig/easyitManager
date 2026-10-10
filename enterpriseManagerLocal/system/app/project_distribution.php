<?php
declare(strict_types=1);

require_once __DIR__ . '/project_backup.php';

/** Minimal project-specific HTML5 DataForm application distribution builder. */
function enterprise_project_distribution_root(array $project): string
{
    return enterprise_project_storage_artifact_path($project, 'export', 'application-packages');
}

function enterprise_project_distribution_ensure_root(array $project): string
{
    $root = enterprise_project_distribution_root($project);
    if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('Das Verzeichnis für Projekt-Anwenderpakete konnte nicht angelegt werden.');
    }
    foreach ([
        '.htaccess' => "Require all denied\nDeny from all\n",
        'index.html' => "<!doctype html><title>403</title>\n",
    ] as $name => $contents) {
        if (!is_file($root . '/' . $name)) @file_put_contents($root . '/' . $name, $contents);
    }
    return $root;
}

function enterprise_project_distribution_cleanup(array $project, int $maxAgeSeconds = 86400): void
{
    $root = enterprise_project_distribution_ensure_root($project);
    $cutoff = time() - max(3600, $maxAgeSeconds);
    foreach (glob($root . '/*.zip') ?: [] as $file) {
        if (is_file($file) && (int)@filemtime($file) < $cutoff) @unlink($file);
    }
}

function enterprise_project_distribution_app_slug(string $value, string $fallback): string
{
    $value = trim($value);
    if ($value === '') $value = $fallback;
    $value = preg_replace('/[^A-Za-z0-9_-]+/', '-', $value) ?? $value;
    $value = trim($value, '-_');
    if ($value === '') $value = $fallback;
    return strtolower(substr($value, 0, 64));
}

/** @return array<int,array{id:int,name:string,slug:string,status:string,file:string}> */
function enterprise_project_distribution_dataforms(PDO $db): array
{
    $rows = $db->query('SELECT id,name,slug,status FROM dataforms ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $used=[]; $out=[];
    foreach ($rows as $row) {
        $id=(int)$row['id'];
        $base=enterprise_project_distribution_app_slug((string)($row['slug']??$row['name']??''),'dataform-'.$id);
        $file=$base; $n=2;
        while(isset($used[$file])){$file=$base.'-'.$n;$n++;}
        $used[$file]=true;
        $out[]=[
            'id'=>$id,
            'name'=>(string)$row['name'],
            'slug'=>(string)($row['slug']??''),
            'status'=>(string)($row['status']??''),
            'file'=>$file.'.php',
        ];
    }
    return $out;
}

function enterprise_project_distribution_index_html(array $project,array $forms): string
{
    $cards='';
    foreach($forms as $form){
        $cards.='<a class="form-card" href="dataforms/'.htmlspecialchars((string)$form['file'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'
            .'<strong>'.htmlspecialchars((string)$form['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</strong>'
            .'<span>DataForm öffnen</span></a>';
    }
    $name=htmlspecialchars((string)($project['name']??'Projekt'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    return "<?php\ndeclare(strict_types=1);\nif(!is_file(__DIR__.'/config.php')){header('Location: setup.php');exit;}\n?>"
        .'<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        .'<title>'.$name.'</title><link rel="stylesheet" href="assets/app.css"></head><body>'
        .'<header class="app-header"><img src="assets/img/easyit-epManager-logo.png" alt="easyIT"><div><strong>'.$name.'</strong><span>DataForm-Anwendung</span></div></header>'
        .'<main><section class="card"><h1>'.$name.'</h1><p>Wählen Sie ein DataForm.</p><div class="form-grid">'.$cards.'</div></section></main></body></html>';
}

function enterprise_project_distribution_dataform_page(int $id): string
{
    return "<?php\ndeclare(strict_types=1);\nrequire __DIR__.'/../lib/DataFormApp.php';\ndf_render_dataform(".$id.");\n";
}

function enterprise_project_distribution_add_file(ZipArchive $zip,string $source,string $target): void
{
    if(!is_file($source) || !$zip->addFile($source,$target)){
        throw new RuntimeException('Datei konnte nicht in das Anwenderpaket aufgenommen werden: '.$target);
    }
}

function enterprise_project_distribution_add_text(ZipArchive $zip,string $target,string $contents): void
{
    if(!$zip->addFromString($target,$contents)) throw new RuntimeException('Datei konnte nicht im Anwenderpaket erzeugt werden: '.$target);
}

function enterprise_project_distribution_add_project_uploads(ZipArchive $zip,string $appRoot,string $folder,array $project): int
{
    $projectId=(int)($project['id']??0);
    $storageKey=trim((string)($project['storage_key']??''));
    if($storageKey==='' || preg_match('/^prj_[0-9]{8,18}_[a-z0-9][a-z0-9-]{0,80}$/',$storageKey)!==1){
        throw new RuntimeException('Projekt besitzt keinen gültigen Storage-Key. Storage-Migration zuerst abschließen.');
    }
    $root=$appRoot.'/storage/projects/'.$storageKey.'/documents/dataform';
    $targetRoot=$folder.'/storage/projects/'.$storageKey.'/documents/dataform';
    $legacy=$appRoot.'/storage/dataform/uploads/project-'.$projectId;
    if(is_dir($legacy)){
        $legacyFiles=[];
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($legacy,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
        foreach($it as $f){ if($f instanceof SplFileInfo && $f->isFile() && !$f->isLink()){$legacyFiles[]=$f->getPathname();break;} }
        if($legacyFiles!==[]){
            throw new RuntimeException('Im Legacy-Uploadbereich liegen noch Projektdateien. Bitte zuerst tools/storage-project-documents-migrate.php --apply ausführen; Legacy-Dateien werden nicht mehr in neue Anwenderpakete übernommen.');
        }
    }
    if(!is_dir($root)) return 0;
    $rootReal=realpath($root); if($rootReal===false)return 0;
    $count=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootReal,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::LEAVES_ONLY);
    foreach($it as $file){
        if(!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink())continue;
        $absolute=$file->getPathname();
        $relative=ltrim(str_replace('\\','/',substr($absolute,strlen($rootReal))),'/');
        $target=rtrim($targetRoot,'/').'/'.$relative;
        if(!$zip->addFile($absolute,$target))throw new RuntimeException('Projektmedium konnte nicht exportiert werden: '.$relative);
        $count++;
    }
    return $count;
}

/** @return array{path:string,filename:string,sha256:string,size:int,files:int,stats:array<string,int>} */
function enterprise_project_distribution_create(array $env, array $project, int $userId): array
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Für den Anwenderpaket-Export wird ext-zip / ZipArchive benötigt.');
    $driver=strtolower(trim((string)($project['database_driver'] ?? $env['PROJECT_DB_DRIVER'] ?? 'mysql')));
    if(in_array($driver,['postgres','postgresql'],true))$driver='pgsql';
    if(!in_array($driver,['mysql','pgsql','oracle','mssql'],true)){
        throw new RuntimeException('Der HTML5-Anwenderpaket-Export ist für MySQL/MariaDB-, PostgreSQL- und Oracle-XE-Projekte freigegeben.');
    }
    $projectId=(int)($project['id']??0); if($projectId<1)throw new RuntimeException('Ungültiges Projekt.');
    $database=trim((string)($project['database_name']??''));
    if(preg_match('/^[A-Za-z][A-Za-z0-9_]{1,62}$/',$database)!==1)throw new RuntimeException('Ungültiger Projektdatenbankname.');
    $databaseSchema=$driver==='pgsql'?(trim((string)($env['PROJECT_DB_SCHEMA']??'public')) ?: 'public'):'';
    if($driver==='pgsql' && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$databaseSchema)!==1)throw new RuntimeException('Ungültiger PostgreSQL-Projektschemaname.');

    enterprise_project_distribution_cleanup($project);
    $distRoot=enterprise_project_distribution_ensure_root($project);
    $appRoot=dirname(__DIR__,2);
    $runtimeRoot=__DIR__.'/project_runtime';
    foreach(['bootstrap.php','DataFormApp.php','setup.php','media.php','assets/app.css','assets/app.js'] as $required){
        if(!is_file($runtimeRoot.'/'.$required))throw new RuntimeException('Project-Runtime-Datei fehlt: '.$required);
    }
    if(!is_file(__DIR__.'/EnterprisePgsqlPdo.php'))throw new RuntimeException('PostgreSQL-Kompatibilitätsklasse fehlt.');if(!is_file(__DIR__.'/EnterpriseOraclePdo.php'))throw new RuntimeException('Oracle-XE-Kompatibilitätsklasse fehlt.');if($driver==='mssql'&&(!is_file(__DIR__.'/EnterpriseMssqlPdo.php')||!is_file(__DIR__.'/EnterpriseMssqlAdapter.php')))throw new RuntimeException('MSSQL-Kompatibilitätsklassen fehlen.');

    if(!enterprise_project_store_exists($env,$database,$driver))throw new RuntimeException('Der Projektdatenbestand wurde nicht gefunden.');
    $db=enterprise_project_store_pdo($env,$database,$driver);
    $forms=enterprise_project_distribution_dataforms($db);
    if($forms===[])throw new RuntimeException('Das Projekt enthält keine DataForms.');

    $tmp=$distRoot.'/tmp-'.bin2hex(random_bytes(10));
    if(!mkdir($tmp,0770,true)&&!is_dir($tmp))throw new RuntimeException('Temporäres Exportverzeichnis konnte nicht angelegt werden.');

    $slug=enterprise_project_backup_slug((string)($project['slug']??$project['name']??'projekt'));
    $folder='easyIT-'.$slug.'-App';
    $filename=$folder.'-'.gmdate('Ymd_His').'.zip';
    $path=$distRoot.'/'.$filename;

    try{
        $sqlFile=$tmp.'/database.sql';
        if($driver==='mysql'){
            $snapshot=false;
            try{$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');$snapshot=true;$stats=enterprise_project_backup_dump_database($db,$database,$sqlFile);$db->exec('COMMIT');$snapshot=false;}
            catch(Throwable $e){if($snapshot)try{$db->exec('ROLLBACK');}catch(Throwable){}throw $e;}
            // Runtime packages import into the database chosen by the end user.
            // Remove source CREATE DATABASE/USE statements and source DEFINER clauses.
            $runtimeSql=(string)file_get_contents($sqlFile);
            $runtimeSql=preg_replace('/^CREATE DATABASE IF NOT EXISTS .*?;\R/m','',$runtimeSql)??$runtimeSql;
            $runtimeSql=preg_replace('/^USE `[^`]+`;\R/m','',$runtimeSql)??$runtimeSql;
            $runtimeSql=preg_replace('/\s+DEFINER=`[^`]+`@`[^`]+`/i','',$runtimeSql)??$runtimeSql;
            file_put_contents($sqlFile,$runtimeSql,LOCK_EX);
        }elseif($driver==='mssql'){
            $stats=enterprise_project_backup_dump_mssql($db,$database,$sqlFile);
        }elseif($driver==='pgsql'){
            $tx=false;
            try{
                if(!$db->inTransaction()){$db->beginTransaction();$tx=true;$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');}
                $stats=enterprise_project_backup_dump_pgsql($db,$database,$sqlFile);
                if($tx&&$db->inTransaction())$db->commit();
            }catch(Throwable $e){if($tx&&$db->inTransaction())try{$db->rollBack();}catch(Throwable){}throw $e;}
        }else{
            $stats=enterprise_project_backup_dump_oracle($db,$database,$sqlFile);
        }

        $driverLabel=$driver==='pgsql'?'PostgreSQL':($driver==='oracle'?'Oracle XE':($driver==='mssql'?'Microsoft SQL Server':'MySQL / MariaDB'));
        $pdoRequirement=$driver==='pgsql'?'pdo_pgsql':($driver==='oracle'?'pdo_oci':($driver==='mssql'?'pdo_sqlsrv':'pdo_mysql'));
        $projectMeta=[
            'id'=>$projectId,'source_project_id'=>$projectId,'name'=>(string)($project['name']??''),'slug'=>(string)($project['slug']??''),'storage_key'=>(string)($project['storage_key']??''),
            'product_type'=>'dataform-html5','database_driver'=>$driver,'database_name'=>$database,'database_schema'=>$databaseSchema,'description'=>(string)($project['description']??''),
            'exported_at'=>gmdate('c'),'exported_by_user_id'=>$userId,
        ];
        $manifest=[
            'format'=>'easyit-dataform-html5-application','format_version'=>3,'runtime_version'=>'1.1','created_at'=>gmdate('c'),
            'project'=>$projectMeta,'dataforms'=>$forms,'database'=>$stats+['driver'=>$driver,'sha256'=>hash_file('sha256',$sqlFile)],
            'install'=>['entrypoint'=>'setup.php','requires'=>['PHP 8.1+',$pdoRequirement,'fileinfo'],'writes'=>'config.php'],
        ];
        $readme="easyIT DataForm HTML5-Anwendung\n==============================\n\nProjekt: ".$projectMeta['name']."\nDataForms: ".count($forms)."\nDatenbank: {$driverLabel}".($driver==='pgsql'?' · Schema: '.$databaseSchema:'')."\n\nInstallation:\n1. ZIP in einen Webserverordner entpacken.\n2. setup.php im Browser öffnen.\n3. {$driverLabel}-Zugangsdaten eingeben.\n4. Der enthaltene Datenbanksnapshot wird importiert und config.php erzeugt.\n5. Danach index.php öffnen.\n\nDas Paket enthält ausschließlich die Projekt-Runtime, DataForm-Seiten, erforderliche Bilder/Assets, Projektmedien und den Datenbanksnapshot. easyIT Enterprise ist nicht enthalten.\n";

        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Anwenderpaket-ZIP konnte nicht erzeugt werden.');
        $fileCount=0;
        try{
            enterprise_project_distribution_add_text($zip,$folder.'/index.php',enterprise_project_distribution_index_html($project,$forms));$fileCount++;
            enterprise_project_distribution_add_file($zip,$runtimeRoot.'/setup.php',$folder.'/setup.php');$fileCount++;
            enterprise_project_distribution_add_file($zip,$runtimeRoot.'/media.php',$folder.'/media.php');$fileCount++;
            enterprise_project_distribution_add_file($zip,$runtimeRoot.'/bootstrap.php',$folder.'/lib/bootstrap.php');$fileCount++;
            enterprise_project_distribution_add_file($zip,__DIR__.'/EnterprisePgsqlPdo.php',$folder.'/lib/EnterprisePgsqlPdo.php');$fileCount++;enterprise_project_distribution_add_file($zip,__DIR__.'/EnterpriseOraclePdo.php',$folder.'/lib/EnterpriseOraclePdo.php');$fileCount++;if($driver==='mssql'){enterprise_project_distribution_add_file($zip,__DIR__.'/EnterpriseMssqlAdapter.php',$folder.'/lib/EnterpriseMssqlAdapter.php');$fileCount++;enterprise_project_distribution_add_file($zip,__DIR__.'/EnterpriseMssqlPdo.php',$folder.'/lib/EnterpriseMssqlPdo.php');$fileCount++;}
            enterprise_project_distribution_add_file($zip,$runtimeRoot.'/DataFormApp.php',$folder.'/lib/DataFormApp.php');$fileCount++;
            enterprise_project_distribution_add_file($zip,$runtimeRoot.'/assets/app.css',$folder.'/assets/app.css');$fileCount++;
            enterprise_project_distribution_add_file($zip,$runtimeRoot.'/assets/app.js',$folder.'/assets/app.js');$fileCount++;

            $imageNames=['easyit-epManager-logo.png','neu.png','speichern.png','anzeigen.png','bearbeiten.png','loeschen.png','erster_ds.png','normaler_ds.png','aktueller_ds.png','neuer_ds.png','letzter_ds.png','suchen.png','filter_loeschen.png'];
            foreach($imageNames as $image){enterprise_project_distribution_add_file($zip,$appRoot.'/assets/img/'.$image,$folder.'/assets/img/'.$image);$fileCount++;}
            foreach($forms as $form){enterprise_project_distribution_add_text($zip,$folder.'/dataforms/'.$form['file'],enterprise_project_distribution_dataform_page((int)$form['id']));$fileCount++;}

            enterprise_project_distribution_add_file($zip,$sqlFile,$folder.'/install/database.sql');$fileCount++;
            enterprise_project_distribution_add_text($zip,$folder.'/install/project.json',json_encode($projectMeta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$fileCount++;
            enterprise_project_distribution_add_text($zip,$folder.'/install/manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));$fileCount++;
            enterprise_project_distribution_add_text($zip,$folder.'/install/.htaccess',"Require all denied\nDeny from all\n");$fileCount++;
            enterprise_project_distribution_add_text($zip,$folder.'/storage/.htaccess',"Options -Indexes -ExecCGI\nRequire all denied\nDeny from all\n");$fileCount++;
            $port=$driver==='pgsql'?5432:($driver==='oracle'?1521:($driver==='mssql'?1433:3306));
            enterprise_project_distribution_add_text($zip,$folder.'/config.php.example',"<?php\ndeclare(strict_types=1);\nreturn ['db_driver'=>'{$driver}','db_host'=>'localhost','db_port'=>{$port},'db_service'=>'".($driver==='oracle'?'XEPDB1':'')."','db_name'=>'".addslashes($database)."','db_schema'=>'".addslashes($databaseSchema)."','db_user'=>'','db_password'=>'','db_engine_path'=>'','project_id'=>".$projectId.",'project_name'=>'".addslashes((string)$projectMeta['name'])."','storage_key'=>'".addslashes((string)$projectMeta['storage_key'])."'];\n");$fileCount++;
            enterprise_project_distribution_add_text($zip,$folder.'/README.txt',$readme);$fileCount++;
            $fileCount+=enterprise_project_distribution_add_project_uploads($zip,$appRoot,$folder,$project);
        }finally{if(!$zip->close())throw new RuntimeException('Anwenderpaket-ZIP konnte nicht abgeschlossen werden.');}

        if(!is_file($path)||(int)filesize($path)<1)throw new RuntimeException('Das Anwenderpaket wurde nicht erzeugt.');
        $sha=hash_file('sha256',$path);if(!is_string($sha)||strlen($sha)!==64)throw new RuntimeException('SHA-256-Prüfung des Anwenderpakets fehlgeschlagen.');
        return ['path'=>$path,'filename'=>$filename,'sha256'=>$sha,'size'=>(int)filesize($path),'files'=>$fileCount,'stats'=>$stats];
    }finally{
        foreach(glob($tmp.'/*')?:[] as $f)if(is_file($f))@unlink($f);@rmdir($tmp);
    }
}
