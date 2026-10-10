<?php
declare(strict_types=1);

require __DIR__.'/system/app/bootstrap.php';
require __DIR__.'/system/ui/layout.php';

$root=__DIR__;
$envFile=$root.'/DataForm5-Core/.env';
$lockFile=$root.'/DataForm5-Core/storage/framework/installer/installed.json';
$recoveryRoot=$root.'/DataForm5-Core/storage/recovery';
$backupRoot=$root.'/backup';
$user=enterprise_user();
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$isLocal=in_array($remote,['127.0.0.1','::1'],true);
$isAdmin=is_array($user) && enterprise_is_admin($user);
if(!$isLocal && !$isAdmin){http_response_code(403);exit('Recovery ist nur lokal oder für eine bereits authentifizierte Admin-Session verfügbar.');}

function recovery_quote_identifier(string $name): string {
    if($name==='' || !preg_match('/^[A-Za-z0-9_]+$/',$name)) throw new RuntimeException('Ungültiger Datenbankname: '.$name);
    return '`'.$name.'`';
}
function recovery_csv_admin_storage_path(string $root,array $env): ?string {
    if(strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'mysql')))!=='csv') return null;
    $base=trim((string)($env['ADMIN_DB_CSV_BASE_PATH']??'storage/admin-csv'));
    $name=trim((string)($env['ADMIN_DB_DATABASE']??''));
    if($name===''||!preg_match('/^[A-Za-z0-9_]+$/',$name)) return null;
    $normalized=str_replace('\\','/',$base);
    $absolute=(bool)preg_match('~^(?:[A-Za-z]:/|/|//)~',$normalized);
    $basePath=$absolute?rtrim($normalized,'/') : rtrim(str_replace('\\','/',$root),'/').'/'.ltrim($normalized,'/');
    return $basePath.'/'.$name;
}

function recovery_csv_project_storage_path(string $root,array $env): ?string {
    if(strtolower(trim((string)($env['PROJECT_DB_DRIVER']??'mysql')))!=='csv') return null;
    $base=trim((string)($env['PROJECT_DB_CSV_BASE_PATH']??'storage/project-csv'));
    if($base==='')$base='storage/project-csv';
    $normalized=str_replace('\\','/',$base);
    $absolute=(bool)preg_match('~^(?:[A-Za-z]:/|/|//)~',$normalized);
    return $absolute?rtrim($normalized,'/') : rtrim(str_replace('\\','/',$root),'/').'/'.ltrim($normalized,'/');
}
function recovery_sqlite_admin_storage_path(string $root,array $env): ?string {
    if(strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'mysql')))!=='sqlite') return null;
    $base=trim((string)($env['ADMIN_DB_SQLITE_BASE_PATH']??'storage/admin-sqlite'));
    $name=trim((string)($env['ADMIN_DB_DATABASE']??''));
    if($name===''||!preg_match('/^[A-Za-z0-9_]+$/',$name)) return null;
    if($base==='')$base='storage/admin-sqlite';
    $normalized=str_replace('\\','/',$base);
    $absolute=(bool)preg_match('~^(?:[A-Za-z]:/|/|//)~',$normalized);
    $basePath=$absolute?rtrim($normalized,'/') : rtrim(str_replace('\\','/',$root),'/').'/'.ltrim($normalized,'/');
    return $basePath.'/'.$name.'.sqlite';
}
function recovery_sqlite_project_storage_path(string $root,array $env): ?string {
    if(strtolower(trim((string)($env['PROJECT_DB_DRIVER']??'mysql')))!=='sqlite') return null;
    $base=trim((string)($env['PROJECT_DB_SQLITE_BASE_PATH']??'storage/project-sqlite'));
    if($base==='')$base='storage/project-sqlite';
    $normalized=str_replace('\\','/',$base);
    $absolute=(bool)preg_match('~^(?:[A-Za-z]:/|/|//)~',$normalized);
    return $absolute?rtrim($normalized,'/') : rtrim(str_replace('\\','/',$root),'/').'/'.ltrim($normalized,'/');
}
function recovery_restore_zip_file(ZipArchive $zip,string $entry,string $target,string $label): void {
    $content=$zip->getFromName($entry);
    if(!is_string($content)||$content==='')throw new RuntimeException($label.' fehlt oder ist leer.');
    $dir=dirname($target);if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException($label.'-Zielverzeichnis konnte nicht angelegt werden.');
    if(file_put_contents($target,$content,LOCK_EX)===false)throw new RuntimeException($label.' konnte nicht wiederhergestellt werden.');
}
function recovery_needs_mysql(array $env): bool {
    return strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'mysql')))==='mysql'
        || strtolower(trim((string)($env['PROJECT_DB_DRIVER']??'mysql')))==='mysql';
}
function recovery_zip_tree(ZipArchive $zip,string $source,string $prefix): void {
    if(!is_dir($source)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){
        $rel=str_replace('\\','/',substr($item->getPathname(),strlen($source)+1));
        if($rel==='')continue;
        $name=rtrim($prefix,'/').'/'.$rel;
        if($item->isDir())$zip->addEmptyDir($name); elseif($item->isFile())$zip->addFile($item->getPathname(),$name);
    }
}
function recovery_restore_zip_tree(ZipArchive $zip,string $prefix,string $target,string $label): void {
    if(!is_dir($target)&&!mkdir($target,0770,true)&&!is_dir($target))throw new RuntimeException($label.' konnte für Restore nicht angelegt werden.');
    $prefix=rtrim($prefix,'/').'/';
    for($i=0;$i<$zip->numFiles;$i++){
        $name=str_replace('\\','/',(string)$zip->getNameIndex($i));
        if(!str_starts_with($name,$prefix))continue;
        $rel=substr($name,strlen($prefix));
        if($rel===''||str_contains($rel,"\0")||str_starts_with($rel,'/')||preg_match('~(^|/)\.\.(/|$)~',$rel)===1)continue;
        $dest=rtrim($target,'/\\').DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$rel);
        if(str_ends_with($name,'/')){if(!is_dir($dest))@mkdir($dest,0770,true);continue;}
        $dir=dirname($dest);if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException($label.'-Verzeichnis konnte nicht angelegt werden: '.$rel);
        $content=$zip->getFromIndex($i);if(!is_string($content))throw new RuntimeException($label.'-Datei kann nicht gelesen werden: '.$rel);
        if(file_put_contents($dest,$content,LOCK_EX)===false)throw new RuntimeException($label.'-Datei konnte nicht wiederhergestellt werden: '.$rel);
    }
}
function recovery_remove_tree(string $path): void {
    if(!is_dir($path)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){ if($item->isDir()) { if(!@rmdir($item->getPathname())) throw new RuntimeException('CSV-Adminordner konnte nicht gelöscht werden: '.$item->getPathname()); } else { if(!@unlink($item->getPathname())) throw new RuntimeException('CSV-Admin-Datei konnte nicht gelöscht werden: '.$item->getPathname()); } }
    if(!@rmdir($path)) throw new RuntimeException('CSV-Administrationsspeicher konnte nicht gelöscht werden: '.$path);
}

function recovery_archive(string $source,string $targetDir): ?string {
    if(!is_file($source)) return null;
    if(!is_dir($targetDir) && !mkdir($targetDir,0775,true) && !is_dir($targetDir)) throw new RuntimeException('Recovery-Archiv konnte nicht angelegt werden.');
    $target=$targetDir.'/'.basename($source);
    if(!copy($source,$target)) throw new RuntimeException('Datei konnte nicht in das Recovery-Archiv kopiert werden: '.basename($source));
    return $target;
}
function recovery_server_pdo(array $env): PDO {
    $adminDriver=strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'mysql')));
    if($adminDriver==='sqlite') $prefix='PROJECT_DB_';
    else $prefix=$adminDriver==='csv'?'PROJECT_DB_':'ADMIN_DB_';
    foreach([$prefix.'HOST',$prefix.'PORT',$prefix.'USERNAME'] as $key) if(empty($env[$key])) throw new RuntimeException('DB-Konfiguration '.$key.' fehlt.');
    return new PDO(
        'mysql:host='.$env[$prefix.'HOST'].';port='.(int)$env[$prefix.'PORT'].';charset=utf8mb4',
        (string)$env[$prefix.'USERNAME'],(string)($env[$prefix.'PASSWORD']??''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}

function recovery_env_from_string(string $content): array {
    $values=[];
    foreach(preg_split('/\R/',$content)?:[] as $line){
        $line=trim($line);
        if($line==='' || str_starts_with($line,'#') || !str_contains($line,'=')) continue;
        [$key,$value]=explode('=',$line,2);
        $values[trim($key)]=trim(trim($value), "\"'");
    }
    return $values;
}
function recovery_sql_literal(PDO $pdo,mixed $value): string {
    if($value===null) return 'NULL';
    if(is_int($value)||is_float($value)) return (string)$value;
    return $pdo->quote((string)$value);
}
function recovery_dump_statement(string &$dump,string $statement): void {
    $dump.='--EASYIT-STMT:'.base64_encode($statement)."\n";
}
function recovery_dump_database(PDO $server,string $db): string {
    $quoted=recovery_quote_identifier($db);
    $server->exec('USE '.$quoted);
    $createDb=$server->query('SHOW CREATE DATABASE '.$quoted)->fetch(PDO::FETCH_NUM);
    $sql="-- easyIT Enterprise encoded SQL backup\n-- database: {$db}\n-- Each --EASYIT-STMT line contains one base64 encoded SQL statement.\n";
    recovery_dump_statement($sql,'SET FOREIGN_KEY_CHECKS=0');
    if(is_array($createDb) && isset($createDb[1])){
        $create=(string)$createDb[1];
        $create=preg_replace('/^CREATE DATABASE/', 'CREATE DATABASE IF NOT EXISTS', $create,1) ?: $create;
        recovery_dump_statement($sql,$create);
    } else recovery_dump_statement($sql,'CREATE DATABASE IF NOT EXISTS '.$quoted);
    recovery_dump_statement($sql,'USE '.$quoted);
    $objects=$server->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
    $views=[];
    foreach($objects as $row){
        $name=(string)($row[0]??'');$type=strtoupper((string)($row[1]??''));
        if($name==='') continue;
        if($type==='VIEW'){ $views[]=$name; continue; }
        $qi=recovery_quote_identifier($name);
        $createRow=$server->query('SHOW CREATE TABLE '.$qi)->fetch(PDO::FETCH_ASSOC)?:[];
        $valsCreate=array_values($createRow);
        $create=(string)($createRow['Create Table']??($valsCreate[1]??''));
        if($create==='') continue;
        recovery_dump_statement($sql,'DROP TABLE IF EXISTS '.$qi);
        recovery_dump_statement($sql,$create);
        $stmt=$server->query('SELECT * FROM '.$qi);
        while($data=$stmt->fetch(PDO::FETCH_ASSOC)){
            $cols=array_map(fn($c)=>recovery_quote_identifier((string)$c),array_keys($data));
            $vals=array_map(fn($v)=>recovery_sql_literal($server,$v),array_values($data));
            recovery_dump_statement($sql,'INSERT INTO '.$qi.' ('.implode(',',$cols).') VALUES ('.implode(',',$vals).')');
        }
    }
    foreach($views as $name){
        $qi=recovery_quote_identifier($name);
        $createRow=$server->query('SHOW CREATE VIEW '.$qi)->fetch(PDO::FETCH_ASSOC)?:[];
        $valsCreate=array_values($createRow);
        $create=(string)($createRow['Create View']??($valsCreate[1]??''));
        if($create!==''){
            recovery_dump_statement($sql,'DROP VIEW IF EXISTS '.$qi);
            recovery_dump_statement($sql,$create);
        }
    }
    try{
        foreach($server->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $tr){
            $name=(string)($tr['Trigger']??''); if($name==='')continue;
            $cr=$server->query('SHOW CREATE TRIGGER '.recovery_quote_identifier($name))->fetch(PDO::FETCH_ASSOC)?:[];
            $create=(string)($cr['SQL Original Statement']??$cr['Create Trigger']??'');
            if($create!==''){
                recovery_dump_statement($sql,'DROP TRIGGER IF EXISTS '.recovery_quote_identifier($name));
                recovery_dump_statement($sql,$create);
            }
        }
    }catch(Throwable){/* Trigger sind optional. */}
    recovery_dump_statement($sql,'SET FOREIGN_KEY_CHECKS=1');
    return $sql;
}
function recovery_backup_create(string $root,string $backupRoot,array $env): array {
    if(!class_exists(ZipArchive::class)) throw new RuntimeException('ZIP-Erweiterung fehlt. Vollbackup benötigt ext-zip.');
    if(!$env) throw new RuntimeException('DataForm5-Core/.env fehlt. Ein vollständiges Datenbankbackup ist ohne DB-Konfiguration nicht möglich.');
    if(!is_dir($backupRoot) && !mkdir($backupRoot,0775,true) && !is_dir($backupRoot)) throw new RuntimeException('Backup-Ordner konnte nicht angelegt werden.');
    $stamp=date('Ymd_His');
    $file=$backupRoot.'/easyit-full-backup-'.$stamp.'.zip';
    $tmp=$root.'/DataForm5-Core/storage/recovery/backup-build/'.$stamp.'-'.bin2hex(random_bytes(3));
    if(!mkdir($tmp,0775,true) && !is_dir($tmp)) throw new RuntimeException('Temporärer Backup-Ordner konnte nicht angelegt werden.');
    try{
        $server=recovery_needs_mysql($env)?recovery_server_pdo($env):null;
        $dbs=$server instanceof PDO?recovery_database_names($server,$env):[];
        $csvAdminPath=recovery_csv_admin_storage_path($root,$env);
        $sqliteAdminPath=recovery_sqlite_admin_storage_path($root,$env);
        $projectStoragePath=$root.'/storage/projects';
        $manifest=['format'=>'easyit-full-backup/2','created_at'=>date(DATE_ATOM),'release'=>trim((string)@file_get_contents($root.'/RELEASE_CANDIDATE')),'version'=>trim((string)@file_get_contents($root.'/VERSION')),'databases'=>$dbs,'admin_password'=>'restored-via-users.password_hash','admin_store'=>[
            'driver'=>strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'mysql'))),
            'database'=>(string)($env['ADMIN_DB_DATABASE']??''),
            'csv_included'=>$csvAdminPath!==null && is_dir($csvAdminPath),
            'sqlite_included'=>$sqliteAdminPath!==null && is_file($sqliteAdminPath),
        ],'project_storage'=>[
            'layout'=>'storage/projects/<storage_key>',
            'included'=>is_dir($projectStoragePath),
        ]];
        $zip=new ZipArchive();
        if($zip->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Backup-ZIP konnte nicht erstellt werden.');
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
        $backupNorm=str_replace('\\','/',$backupRoot).'/';
        $tmpNorm=str_replace('\\','/',$tmp).'/';
        foreach($it as $item){
            $path=$item->getPathname();$norm=str_replace('\\','/',$path);
            if(str_starts_with($norm,$backupNorm)||str_starts_with($norm,$tmpNorm)) continue;
            $rel=str_replace('\\','/',substr($path,strlen($root)+1));
            if($rel==='')continue;
            if($item->isDir())$zip->addEmptyDir('project/'.$rel);
            elseif($item->isFile())$zip->addFile($path,'project/'.$rel);
        }
        if($csvAdminPath!==null && is_dir($csvAdminPath)){
            $csvIt=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($csvAdminPath,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach($csvIt as $item){
                $rel=str_replace('\\','/',substr($item->getPathname(),strlen($csvAdminPath)+1));
                if($rel==='')continue;
                if($item->isDir())$zip->addEmptyDir('admin-csv/'.$rel);
                elseif($item->isFile())$zip->addFile($item->getPathname(),'admin-csv/'.$rel);
            }
        }
        if($sqliteAdminPath!==null && is_file($sqliteAdminPath)){
            try{$sqliteAdminPdo=new EnterpriseSqlitePdo($sqliteAdminPath);$sqliteAdminPdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');unset($sqliteAdminPdo);}catch(Throwable){}
            $zip->addFile($sqliteAdminPath,'admin-sqlite/database.sqlite');
        }
        foreach($dbs as $db) {
            if(!$server instanceof PDO) throw new RuntimeException('MySQL-Serververbindung fehlt für Datenbankbackup.');
            $zip->addFromString('databases/'.$db.'.sql',recovery_dump_database($server,$db));
        }
        $zip->addFromString('BACKUP_MANIFEST.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        $zip->close();
        return ['file'=>$file,'name'=>basename($file),'databases'=>$dbs,'sha256'=>hash_file('sha256',$file)];
    } finally {
        if(is_dir($tmp)) @rmdir($tmp);
    }
}
function recovery_restore_sql(PDO $server,string $sql): void {
    $count=0;
    foreach(preg_split('/\R/',$sql)?:[] as $line){
        $line=trim($line);
        if(!str_starts_with($line,'--EASYIT-STMT:'))continue;
        $encoded=substr($line,14);
        $statement=base64_decode($encoded,true);
        if(!is_string($statement)||trim($statement)==='') throw new RuntimeException('Beschädigter SQL-Statement-Block im Backup.');
        $server->exec($statement);$count++;
    }
    if($count===0) throw new RuntimeException('Backup enthält keine wiederherstellbaren SQL-Statements.');
}
function recovery_backup_restore(string $root,string $backupFile): array {
    if(!class_exists(ZipArchive::class)) throw new RuntimeException('ZIP-Erweiterung fehlt. Restore benötigt ext-zip.');
    if(!is_file($backupFile)) throw new RuntimeException('Backup-Datei wurde nicht gefunden.');
    $zip=new ZipArchive();
    if($zip->open($backupFile)!==true) throw new RuntimeException('Backup-ZIP kann nicht geöffnet werden.');
    try{
        $manifestRaw=$zip->getFromName('BACKUP_MANIFEST.json');
        $manifest=is_string($manifestRaw)?json_decode($manifestRaw,true):null;
        $backupFormat=is_array($manifest)?(string)($manifest['format']??''):'';
        if(!in_array($backupFormat,['easyit-full-backup/1','easyit-full-backup/2'],true)) throw new RuntimeException('Ungültiges easyIT-Vollbackup.');
        $envRaw=$zip->getFromName('project/DataForm5-Core/.env');
        if(!is_string($envRaw)||$envRaw==='') throw new RuntimeException('Backup enthält keine DataForm5-Core/.env.');
        $backupEnv=recovery_env_from_string($envRaw);
        $dbs=array_values(array_filter((array)($manifest['databases']??[]),'is_string'));
        $server=$dbs!==[]?recovery_server_pdo($backupEnv):null;
        foreach($dbs as $db){
            recovery_quote_identifier($db);
            $sql=$zip->getFromName('databases/'.$db.'.sql');
            if(!is_string($sql)||$sql==='') throw new RuntimeException('SQL-Dump fehlt: '.$db);
            if(!$server instanceof PDO) throw new RuntimeException('MySQL-Serververbindung fehlt für Datenbank-Restore.');
            $server->exec('DROP DATABASE IF EXISTS '.recovery_quote_identifier($db));
            recovery_restore_sql($server,$sql);
        }
        $snapshot=[];
        for($i=0;$i<$zip->numFiles;$i++){
            $name=(string)$zip->getNameIndex($i);
            if(!str_starts_with($name,'project/'))continue;
            $rel=rtrim(substr($name,8),'/'); if($rel!=='')$snapshot[str_replace('\\','/',$rel)]=true;
        }
        $current=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($current as $item){
            $path=$item->getPathname();$rel=str_replace('\\','/',substr($path,strlen($root)+1));
            if($rel==='backup'||str_starts_with($rel,'backup/'))continue;
            if(isset($snapshot[$rel]))continue;
            if($item->isDir())@rmdir($path);else @unlink($path);
        }
        for($i=0;$i<$zip->numFiles;$i++){
            $name=(string)$zip->getNameIndex($i);
            if(!str_starts_with($name,'project/'))continue;
            $rel=substr($name,8);
            if($rel===''||str_contains($rel,'../')||str_starts_with($rel,'/'))continue;
            $target=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$rel);
            if(str_ends_with($name,'/')){if(!is_dir($target))@mkdir($target,0775,true);continue;}
            $dir=dirname($target);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Restore-Verzeichnis konnte nicht angelegt werden: '.$rel);
            $content=$zip->getFromIndex($i);if(!is_string($content))throw new RuntimeException('Backup-Datei kann nicht gelesen werden: '.$rel);
            if(file_put_contents($target,$content,LOCK_EX)===false)throw new RuntimeException('Datei konnte nicht wiederhergestellt werden: '.$rel);
        }
        $csvAdminTarget=recovery_csv_admin_storage_path($root,$backupEnv);
        $csvAdminRestored=$csvAdminTarget!==null && (($manifest['admin_store']['csv_included']??false)===true);
        if($csvAdminRestored) recovery_restore_zip_tree($zip,'admin-csv',$csvAdminTarget,'CSV-Administrationsspeicher');
        $csvProjectRestored=false;
        if($backupFormat==='easyit-full-backup/1'){
            $csvProjectTarget=recovery_csv_project_storage_path($root,$backupEnv);
            $csvProjectRestored=$csvProjectTarget!==null && (($manifest['project_store']['csv_included']??false)===true);
            if($csvProjectRestored) recovery_restore_zip_tree($zip,'project-csv',$csvProjectTarget,'Legacy-CSV-Projektdatenspeicher');
        }
        $sqliteAdminTarget=recovery_sqlite_admin_storage_path($root,$backupEnv);
        $sqliteAdminRestored=$sqliteAdminTarget!==null && (($manifest['admin_store']['sqlite_included']??false)===true);
        if($sqliteAdminRestored){
            recovery_restore_zip_file($zip,'admin-sqlite/database.sqlite',$sqliteAdminTarget,'SQLite-Administrationsspeicher');
            if(extension_loaded('pdo_sqlite')){$probe=new EnterpriseSqlitePdo($sqliteAdminTarget);if(strtolower(trim((string)$probe->query('PRAGMA integrity_check')->fetchColumn()))!=='ok')throw new RuntimeException('Wiederhergestellter SQLite-Administrationsspeicher ist beschädigt.');}
        }
        $sqliteProjectRestored=false;
        if($backupFormat==='easyit-full-backup/1'){
            $sqliteProjectTarget=recovery_sqlite_project_storage_path($root,$backupEnv);
            $sqliteProjectRestored=$sqliteProjectTarget!==null && (($manifest['project_store']['sqlite_included']??false)===true);
            if($sqliteProjectRestored) recovery_restore_zip_tree($zip,'project-sqlite',$sqliteProjectTarget,'Legacy-SQLite-Projektdatenspeicher');
            if($sqliteProjectRestored && extension_loaded('pdo_sqlite')){foreach(glob(rtrim($sqliteProjectTarget,'/\\').'/*.sqlite')?:[] as $sf){$probe=new EnterpriseSqlitePdo($sf);if(strtolower(trim((string)$probe->query('PRAGMA integrity_check')->fetchColumn()))!=='ok')throw new RuntimeException('Wiederhergestellter SQLite-Projektspeicher ist beschädigt: '.basename($sf));}}
        }
        return ['databases'=>$dbs,'manifest'=>$manifest,'csv_admin_restored'=>$csvAdminRestored,'csv_project_restored'=>$csvProjectRestored,'sqlite_admin_restored'=>$sqliteAdminRestored,'sqlite_project_restored'=>$sqliteProjectRestored];
    } finally {$zip->close();}
}
function recovery_database_names(?PDO $server,array $env): array {
    if(!$server instanceof PDO) return [];
    $names=[];
    $adminDriver=strtolower(trim((string)($env['ADMIN_DB_DRIVER']??'mysql')));
    $projectDriver=strtolower(trim((string)($env['PROJECT_DB_DRIVER']??'mysql')));
    $admin=trim((string)($env['ADMIN_DB_DATABASE']??''));
    $project=trim((string)($env['PROJECT_DB_DATABASE']??''));
    if($projectDriver==='mysql' && $project!=='')$names[]=$project;
    if($adminDriver!=='mysql'){
        try{
            $control=enterprise_pdo();
            foreach($control->query("SELECT database_name,database_driver FROM projects WHERE database_name IS NOT NULL AND database_name<>''")->fetchAll(PDO::FETCH_ASSOC) as $row){
                $name=(string)($row['database_name']??'');$driver=strtolower((string)($row['database_driver']??$projectDriver));
                if(in_array($driver,['mysql','mariadb'],true) && preg_match('/^[A-Za-z0-9_]+$/',$name))$names[]=$name;
            }
        }catch(Throwable){}
        return array_values(array_unique(array_filter($names)));
    }
    if($admin!==''){
        try{
            $server->exec('USE '.recovery_quote_identifier($admin));
            $exists=$server->query("SHOW TABLES LIKE 'projects'")->fetchColumn();
            if($exists){
                try{$rows=$server->query("SELECT database_name,database_driver FROM projects WHERE database_name IS NOT NULL AND database_name<>''")->fetchAll(PDO::FETCH_ASSOC);}catch(Throwable){$rows=[];}
                foreach($rows as $row){
                    $name=(string)($row['database_name']??'');$driver=strtolower((string)($row['database_driver']??$projectDriver));
                    if(in_array($driver,['mysql','mariadb'],true) && preg_match('/^[A-Za-z0-9_]+$/',$name))$names[]=$name;
                }
            }
        }catch(Throwable){}
        $names[]=$admin;
    }
    return array_values(array_unique(array_filter($names)));
}

$env=enterprise_env($envFile);
$message='';$error='';$details=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        $confirm=trim((string)($_POST['confirm']??''));
        if($action==='backup_create'){
            @set_time_limit(0);
            if(!$isLocal && !$isAdmin) throw new RuntimeException('Backup ist nur lokal oder für Administratoren erlaubt.');
            $result=recovery_backup_create($root,$backupRoot,$env);
            $message='Vollbackup erfolgreich erstellt: '.$result['name'];
            $details[]='Datenbanken: '.implode(', ',$result['databases']);
            $details[]='SHA-256: '.$result['sha256'];
            $action='done';
        }elseif($action==='backup_restore'){
            @set_time_limit(0);
            if(!$isLocal) throw new RuntimeException('Vollständige Wiederherstellung ist ausschließlich über localhost erlaubt.');
            if($confirm!=='RESTORE EASYIT') throw new RuntimeException('Bestätigung fehlt. Geben Sie exakt RESTORE EASYIT ein.');
            $backupName=basename((string)($_POST['backup_file']??''));
            if($backupName===''||!str_ends_with(strtolower($backupName),'.zip')) throw new RuntimeException('Ungültige Backup-Datei.');
            $result=recovery_backup_restore($root,$backupRoot.'/'.$backupName);
            unset($_SESSION['enterprise_user'],$_SESSION['enterprise_csrf']);
            session_regenerate_id(true);
            $message='Vollständige Wiederherstellung abgeschlossen. Projektdateien, Konfiguration und Datenbanken wurden aus '.$backupName.' wiederhergestellt.';
            $details[]='Wiederhergestellte Datenbanken: '.implode(', ',$result['databases']);
            $details[]='Der Admin-Benutzer einschließlich password_hash wurde aus dem Administrationsspeicher wiederhergestellt; das zum Backup-Zeitpunkt gültige Admin-Passwort gilt wieder.';
            $action='done';
            $env=enterprise_env($envFile);
        }elseif($action==='setup_reset'){
            if($confirm!=='RESET SETUP') throw new RuntimeException('Bestätigung fehlt. Geben Sie exakt RESET SETUP ein.');
        }elseif($action==='factory_reset'){
            if(!$isLocal) throw new RuntimeException('Der destruktive Vollreset ist ausschließlich über localhost erlaubt.');
            if($confirm!=='RESET EASYIT' || empty($_POST['destroy_databases'])) throw new RuntimeException('Vollreset nicht bestätigt. Geben Sie RESET EASYIT ein und aktivieren Sie die Datenbank-Löschbestätigung.');
        }else throw new RuntimeException('Unbekannte Recovery-Aktion.');

        if(in_array($action,['setup_reset','factory_reset'],true)){
            $stamp=gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
            $archive=$recoveryRoot.'/factory-reset/'.$stamp;
            recovery_archive($envFile,$archive);
            recovery_archive($lockFile,$archive);
            $details[]='Konfiguration und Install-Lock wurden vor dem Reset in DataForm5-Core/storage/recovery/factory-reset/'.$stamp.' gesichert.';
        }

        if($action==='factory_reset'){
            if(!$env) throw new RuntimeException('Für den Datenbank-Vollreset fehlt DataForm5-Core/.env. Der Setup-Reset kann weiterhin verwendet werden.');
            $server=recovery_needs_mysql($env)?recovery_server_pdo($env):null;
            $dbs=$server instanceof PDO?recovery_database_names($server,$env):[];
            if($server instanceof PDO)try{$server->exec('USE information_schema');}catch(Throwable){}
            foreach($dbs as $db){
                $server->exec('DROP DATABASE IF EXISTS '.recovery_quote_identifier($db));
                $details[]='Datenbank gelöscht: '.$db;
            }
            $csvAdminPath=recovery_csv_admin_storage_path($root,$env);
            if($csvAdminPath!==null && is_dir($csvAdminPath)){
                recovery_remove_tree($csvAdminPath);
                $details[]='CSV-Administrationsspeicher gelöscht: '.$csvAdminPath;
            }
                if($csvProjectPath!==null && is_dir($csvProjectPath)){
                recovery_remove_tree($csvProjectPath);
                $details[]='CSV-Projektdatenspeicher gelöscht: '.$csvProjectPath;
            }
            $sqliteAdminPath=recovery_sqlite_admin_storage_path($root,$env);
            if($sqliteAdminPath!==null && is_file($sqliteAdminPath)){
                @unlink($sqliteAdminPath);@unlink($sqliteAdminPath.'-wal');@unlink($sqliteAdminPath.'-shm');
                $details[]='SQLite-Administrationsspeicher gelöscht: '.$sqliteAdminPath;
            }
                if($sqliteProjectPath!==null && is_dir($sqliteProjectPath)){
                recovery_remove_tree($sqliteProjectPath);
                $details[]='SQLite-Projektdatenspeicher gelöscht: '.$sqliteProjectPath;
            }
        }

        if(in_array($action,['setup_reset','factory_reset'],true)){
            if(is_file($lockFile) && !unlink($lockFile)) throw new RuntimeException('Install-Lock konnte nicht entfernt werden.');
            if(is_file($envFile) && !unlink($envFile)) throw new RuntimeException('.env konnte nicht entfernt werden.');
            unset($_SESSION['enterprise_user'],$_SESSION['enterprise_csrf']);
            session_regenerate_id(true);
            $message=$action==='factory_reset'?'Vollreset abgeschlossen. easyIT befindet sich wieder im Installationszustand.':'Setup-Reset abgeschlossen. Datenbanken wurden nicht gelöscht; Konfiguration und Install-Lock wurden zurückgesetzt.';
            $env=[];
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$backupFiles=[]; if(is_dir($backupRoot)){foreach(glob($backupRoot.'/easyit-full-backup-*.zip')?:[] as $bf){$backupFiles[]=basename($bf);} rsort($backupFiles,SORT_STRING);}
$envExists=is_file($envFile);$lockExists=is_file($lockFile);
$databasePreview=[];$databasePreviewError='';
if($env && recovery_needs_mysql($env)){try{$previewServer=recovery_server_pdo($env);$databasePreview=recovery_database_names($previewServer,$env);}catch(Throwable $e){$databasePreviewError=$e->getMessage();}}
ob_start();
?>
<section class="hero">
<span class="badge">Recovery Console</span>
<h1>System zurücksetzen / Ursprungszustand</h1>
<p>Diese Konsole bleibt auf localhost auch dann erreichbar, wenn der Administrationsspeicher beschädigt oder nicht mehr verfügbar ist.</p>
<div class="actions"><a class="button secondary" <?= easyit_button_attributes('zurueck') ?> href="index.php">Zur Startseite</a><?php if($isAdmin):?><a class="button secondary" <?= easyit_button_attributes('start','dashboard') ?> href="app/dashboard.php">Zum Dashboard</a><?php endif;?></div>
</section>

<?php if($message):?><div class="notice success"><strong><?=e($message)?></strong><?php foreach($details as $d):?><br><?=e($d)?><?php endforeach;?></div><?php endif;?>
<?php if($error):?><div class="notice error"><strong>Reset abgebrochen:</strong> <?=e($error)?></div><?php endif;?>

<section class="card"><h2>Aktueller Recovery-Status</h2>
<table><tbody>
<tr><th>Zugriff</th><td><?=$isLocal?'localhost':'authentifizierter Administrator'?></td></tr>
<tr><th>DataForm5-Core/.env</th><td><?=$envExists?'vorhanden':'nicht vorhanden'?></td></tr>
<tr><th>Install-Lock</th><td><?=$lockExists?'gesetzt':'nicht gesetzt'?></td></tr>
<tr><th>Administrationsspeicher</th><td><?=e(strtoupper((string)($env['ADMIN_DB_DRIVER']??'mysql')).' · '.(string)($env['ADMIN_DB_DATABASE']??'nicht konfiguriert'))?></td></tr>
<tr><th>Projektdatenspeicher</th><td><?=e(strtoupper((string)($env['PROJECT_DB_DRIVER']??'mysql')).' · '.(string)($env['PROJECT_DB_DATABASE']??'nicht konfiguriert'))?></td></tr>
</tbody></table></section>


<section class="card"><h2>Backup & vollständige Wiederherstellung</h2>
<p>Erstellt eine <strong>einzige vollständige Backup-Datei</strong> im Ordner <code>backup/</code>. Sie enthält den gesamten Projektstand (ohne den Backup-Ordner selbst), <code>.env</code>, Install-Lock sowie SQL-Dumps aller ermittelten MariaDB/MySQL-Projektdatenbanken. CSV- und SQLite-Speicher werden vollständig mitgesichert, auch wenn ihr Basispfad außerhalb des Projektverzeichnisses liegt. Dadurch werden auch Benutzer, Rollen und der <code>password_hash</code> des Administrators gesichert.</p>
<div class="notice error"><strong>Sicherheitsrelevant:</strong> Das Vollbackup enthält <code>.env</code>, Datenbankinhalte und damit vertrauliche Konfiguration. Die Datei wie ein Administrationsgeheimnis behandeln und nicht öffentlich weitergeben.</div>
<div class="notice"><strong>Admin-Passwort:</strong> Das Passwort wird nicht im Klartext gespeichert. Bei der Wiederherstellung wird der vorhandene Passwort-Hash aus dem Administrationsspeicher zurückgespielt; anschließend gilt wieder dasselbe Admin-Passwort wie zum Zeitpunkt des Backups.</div>
<form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="backup_create"><p><button class="button secondary" type="submit">Vollbackup jetzt erstellen</button></p></form>
<?php if($backupFiles!==[]):?>
<hr><h3>Vorhandenes Vollbackup wiederherstellen</h3>
<div class="notice error"><strong>Achtung:</strong> Die Wiederherstellung überschreibt Projektdateien und ersetzt die im Backup enthaltenen Datenbanken. Nur auf localhost ausführbar.</div>
<form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="backup_restore">
<label>Backup-Datei<select name="backup_file" required><?php foreach($backupFiles as $bf):?><option value="<?=e($bf)?>"><?=e($bf)?> · <?=e(number_format((float)filesize($backupRoot.'/'.$bf)/1048576,2,',','.'))?> MB</option><?php endforeach;?></select></label>
<label>Zur Bestätigung exakt <code>RESTORE EASYIT</code> eingeben<input name="confirm" autocomplete="off" required></label>
<p><button class="button" type="submit">Projekt vollständig wiederherstellen</button></p></form>
<?php else:?><p>Noch kein Vollbackup im Ordner <code>backup/</code> vorhanden.</p><?php endif;?>
</section>

<section class="card"><h2>A. Setup-Reset – Daten behalten</h2>
<p>Entfernt <strong>nur</strong> <code>DataForm5-Core/.env</code> und den Install-Lock. Vorhandene Datenbanken werden nicht gelöscht. Die beiden Dateien werden vorher im Recovery-Verzeichnis archiviert.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="setup_reset">
<label>Zur Bestätigung exakt <code>RESET SETUP</code> eingeben<input name="confirm" autocomplete="off" required></label>
<p><button class="button secondary" type="submit">Setup-Zustand zurücksetzen</button></p>
</form></section>

<section class="card"><h2>B. Vollreset – Datenbanken löschen</h2>
<div class="notice error"><strong>Achtung:</strong> Dieser Vorgang löscht die in der Konfiguration bekannten Projekt-/Admin-Datenbanken sowie einen konfigurierten CSV-Administrationsspeicher dauerhaft. Er ist für einen echten Neuaufbau nach beschädigten Test-/Entwicklungsdatenbanken vorgesehen.</div>
<?php if($databasePreview!==[]):?><p><strong>Für den Vollreset aktuell ermittelte Datenbanken:</strong> <?=e(implode(', ',$databasePreview))?></p><?php elseif($databasePreviewError!==''):?><p><strong>Datenbank-Vorschau nicht möglich:</strong> <?=e($databasePreviewError)?></p><?php endif;?>
<?php if(!$isLocal):?><div class="notice error">Der Vollreset ist aus Sicherheitsgründen ausschließlich über localhost ausführbar.</div><?php endif;?>
<form method="post">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="factory_reset">
<label><input type="checkbox" name="destroy_databases" value="1" required> Ich bestätige, dass die ermittelten easyIT-Datenbanken gelöscht werden dürfen.</label>
<label>Zur Bestätigung exakt <code>RESET EASYIT</code> eingeben<input name="confirm" autocomplete="off" required></label>
<p><button class="button" type="submit">Vollreset durchführen</button></p>
</form></section>

<section class="card"><h2>Administration erneut aufbauen</h2><div class="actions"><a class="button" <?= easyit_button_attributes('setup') ?> href="setup.php">Installation / Setup öffnen</a><a class="button secondary" <?= easyit_button_attributes('datenbank_assistent') ?> href="installer/database.php">Datenbank-Assistent öffnen</a></div></section>
<section class="card"><h2>Nach dem Reset</h2><p>Öffnen Sie <a href="setup.php">Installer 2.0</a> bzw. bei Bedarf den <a href="installer/database.php">Datenbank-Assistenten</a> und installieren Sie das System neu.</p></section>
<?php
$content=ob_get_clean();
render_page(['title'=>'Recovery / Reset','active'=>'home','content'=>$content,'help'=>[
    'title'=>'Recovery Console','location'=>'Start → Recovery / Reset','short'=>'Setzt Installationszustand oder Datenbanken kontrolliert zurück.','goal'=>'Nach beschädigter Konfiguration oder Datenbank wieder einen installationsfähigen Ausgangszustand herstellen.','next'=>'Vor einem Reset möglichst zuerst ein Vollbackup erstellen. Danach Setup-Reset verwenden; Vollreset nur wenn die Datenbanken tatsächlich verworfen werden sollen.','steps'=>['Optional vollständiges Backup in backup/ erstellen.','Reset-Umfang wählen.','Warntext lesen.','Bestätigung exakt eingeben.','Reset ausführen.','Installer erneut starten.'],'tips'=>['Vollbackup enthält Projektdateien, .env, CSV-/SQLite-Speicher und SQL-Dumps der MariaDB/MySQL-Datenbanken.','Restore stellt auch den Admin-Passwort-Hash wieder her.','Der Vollreset ist destruktiv.','Vorhandene .env und Install-Lock werden vor dem Reset archiviert.','Außerhalb von localhost ist die Recovery Console nur mit bestehender Admin-Session erreichbar.']
]]);
