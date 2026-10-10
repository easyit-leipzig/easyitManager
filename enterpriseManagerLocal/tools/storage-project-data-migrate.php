<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}

function hf88_copy_tree(string $source,string $target): array
{
    if(!is_dir($source)) throw new RuntimeException('Quellordner fehlt: '.$source);
    enterprise_project_storage_mkdir($target);
    $count=0;$bytes=0;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){
        $rel=substr(str_replace('\\','/',$item->getPathname()),strlen(rtrim(str_replace('\\','/',$source),'/'))+1);
        $dest=rtrim($target,'/\\').'/'.$rel;
        if($item->isDir()){enterprise_project_storage_mkdir($dest);continue;}
        enterprise_project_storage_mkdir(dirname($dest));
        if(!copy($item->getPathname(),$dest)) throw new RuntimeException('Datei konnte nicht kopiert werden: '.$item->getPathname());
        $count++;$bytes+=(int)$item->getSize();
    }
    return ['files'=>$count,'bytes'=>$bytes];
}

function hf88_tree_hashes(string $root): array
{
    if(!is_dir($root)) return [];
    $out=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $item){
        if(!$item->isFile())continue;
        $rel=substr(str_replace('\\','/',$item->getPathname()),strlen(rtrim(str_replace('\\','/',$root),'/'))+1);
        $out[$rel]=hash_file('sha256',$item->getPathname());
    }
    ksort($out);
    return $out;
}

function hf88_remove_tree_contents(string $path): void
{
    if(!is_dir($path)) return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){if($item->isDir())@rmdir($item->getPathname());else @unlink($item->getPathname());}
}

$apply=in_array('--apply',$argv??[],true);
$removeLegacy=in_array('--remove-legacy',$argv??[],true);
$pdo=enterprise_pdo();
enterprise_upgrade($pdo);
$env=enterprise_env(dirname(__DIR__).'/DataForm5-Core/.env');
$projects=$pdo->query('SELECT * FROM projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$results=[];

foreach($projects as $project){
    $id=(int)($project['id']??0);
    try{
        if(trim((string)($project['storage_key']??''))===''){
            enterprise_project_storage_assign($pdo,$id,(string)$project['slug']);
            $project=enterprise_project_storage_fetch($pdo,$id);
        }
        enterprise_project_storage_provision($project,$env);
        $driver=enterprise_project_store_driver($env,$project);
        $database=trim((string)($project['database_name']??''));
        $entry=['project_id'=>$id,'storage_key'=>$project['storage_key'],'driver'=>$driver,'database_name'=>$database,'mode'=>$apply?'apply':'dry-run','status'=>'SKIP'];

        if($driver==='csv'){
            $legacy=enterprise_project_store_csv_database_path($env,$database);
            $target=enterprise_project_store_csv_database_path_for_project($env,$project,false);
            $entry['legacy']=$legacy;$entry['target']=$target;
            if(!enterprise_project_store_csv_path_has_data($legacy)){$entry['message']='Kein Legacy-CSV-Datenbestand gefunden.';$results[]=$entry;continue;}
            if(enterprise_project_store_csv_path_has_data($target)){$entry['message']='Projekt-CSV-Ziel enthält bereits Daten; keine Überschreibung.';$results[]=$entry;continue;}
            $entry['status']=$apply?'MIGRATED':'READY';
            if($apply){
                hf88_remove_tree_contents($target);
                $stats=hf88_copy_tree($legacy,$target);
                if(hf88_tree_hashes($legacy)!==hf88_tree_hashes($target)) throw new RuntimeException('CSV-Integritätsprüfung nach Kopie fehlgeschlagen.');
                $projectPdo=enterprise_project_store_for_project($env,$project);
                $sourceId=enterprise_project_store_ensure_default_csv_source_for_project($projectPdo,$env,$project);
                $entry['files']=$stats['files'];$entry['bytes']=$stats['bytes'];$entry['data_source_id']=$sourceId;
                if($removeLegacy){enterprise_project_store_remove_tree($legacy);$entry['legacy_removed']=true;}else{$entry['legacy_removed']=false;}
            }
        }elseif($driver==='sqlite'){
            $legacy=enterprise_project_store_sqlite_database_path($env,$database);
            $target=enterprise_project_store_sqlite_database_path_for_project($env,$project,false);
            $entry['legacy']=$legacy;$entry['target']=$target;
            if(!is_file($legacy)||(int)filesize($legacy)===0){$entry['message']='Keine Legacy-SQLite-Datei gefunden.';$results[]=$entry;continue;}
            if(is_file($target)&&(int)filesize($target)>0){$entry['message']='Projekt-SQLite-Ziel enthält bereits Daten; keine Überschreibung.';$results[]=$entry;continue;}
            $entry['status']=$apply?'MIGRATED':'READY';
            if($apply){
                enterprise_project_storage_mkdir(dirname($target));
                $tmp=$target.'.migrating-'.bin2hex(random_bytes(4));
                if(!copy($legacy,$tmp)) throw new RuntimeException('SQLite-Datei konnte nicht kopiert werden.');
                $check=new EnterpriseSqlitePdo($tmp);
                if((string)$check->query('PRAGMA integrity_check')->fetchColumn()!=='ok'){@unlink($tmp);throw new RuntimeException('SQLite-Integritätsprüfung nach Kopie fehlgeschlagen.');}
                if(hash_file('sha256',$legacy)!==hash_file('sha256',$tmp)){@unlink($tmp);throw new RuntimeException('SQLite-Prüfsumme nach Kopie stimmt nicht überein.');}
                if(!@rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('SQLite-Migrationsdatei konnte nicht aktiviert werden.');}
                $projectPdo=enterprise_project_store_for_project($env,$project);
                $sourceId=enterprise_project_store_ensure_default_sqlite_source_for_project($projectPdo,$env,$project);
                $entry['bytes']=(int)filesize($target);$entry['sha256']=hash_file('sha256',$target);$entry['data_source_id']=$sourceId;
                if($removeLegacy){foreach([$legacy.'-wal',$legacy.'-shm',$legacy] as $f)if(is_file($f))@unlink($f);$entry['legacy_removed']=!is_file($legacy);}else{$entry['legacy_removed']=false;}
            }
        }else{
            $entry['message']='Kein lokaler CSV/SQLite-Legacy-Speicher zu migrieren.';
        }
        $results[]=$entry;
    }catch(Throwable $e){$results[]=['project_id'=>$id,'storage_key'=>$project['storage_key']??null,'status'=>'ERROR','message'=>$e->getMessage()];}
}

$output=['phase'=>'HF88-storage-phase2','apply'=>$apply,'remove_legacy'=>$removeLegacy,'projects'=>$results];
echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
