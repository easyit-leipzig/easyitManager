<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}

function hf88_doc_hashes(string $root): array
{
    if(!is_dir($root)) return [];
    $out=[];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
    foreach($it as $item){
        if(!$item->isFile() || $item->isLink()) continue;
        $rel=substr(str_replace('\\','/',$item->getPathname()),strlen(rtrim(str_replace('\\','/',$root),'/'))+1);
        if(in_array($rel,['.htaccess','index.html'],true)) continue;
        $out[$rel]=hash_file('sha256',$item->getPathname());
    }
    ksort($out);
    return $out;
}

function hf88_doc_copy(string $source,string $target): array
{
    enterprise_project_storage_mkdir($target);
    $files=0;$bytes=0;
    if(!is_dir($source)) return ['files'=>0,'bytes'=>0];
    $sourceReal=realpath($source);
    if($sourceReal===false) return ['files'=>0,'bytes'=>0];
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceReal,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
    foreach($it as $item){
        if($item->isLink()) throw new RuntimeException('Symbolischer Link im Legacy-Dokumentbereich ist nicht zulässig: '.$item->getPathname());
        $rel=substr(str_replace('\\','/',$item->getPathname()),strlen(rtrim(str_replace('\\','/',$sourceReal),'/'))+1);
        if(in_array($rel,['.htaccess','index.html'],true)) continue;
        $dest=rtrim($target,'/\\').'/'.$rel;
        if($item->isDir()){enterprise_project_storage_mkdir($dest);continue;}
        enterprise_project_storage_mkdir(dirname($dest));
        if(is_file($dest)){
            if(hash_file('sha256',$dest)!==hash_file('sha256',$item->getPathname())) throw new RuntimeException('Zieldatei existiert mit abweichendem Inhalt: '.$dest);
            continue;
        }
        $tmp=$dest.'.migrating-'.bin2hex(random_bytes(4));
        if(!copy($item->getPathname(),$tmp)) throw new RuntimeException('Dokument konnte nicht kopiert werden: '.$item->getPathname());
        if(hash_file('sha256',$tmp)!==hash_file('sha256',$item->getPathname())){@unlink($tmp);throw new RuntimeException('Dokument-Prüfsumme nach Kopie stimmt nicht: '.$rel);}
        if(!@rename($tmp,$dest)){@unlink($tmp);throw new RuntimeException('Dokument konnte nicht aktiviert werden: '.$rel);}
        @chmod($dest,0660);
        $files++;$bytes+=(int)$item->getSize();
    }
    return ['files'=>$files,'bytes'=>$bytes];
}

function hf88_doc_remove_tree(string $path): void
{
    if(!is_dir($path)) return;
    $real=realpath($path); if($real===false)return;
    $expected=realpath(dirname(__DIR__).'/storage/dataform/uploads');
    if($expected===false || !str_starts_with(str_replace('\\','/',$real),rtrim(str_replace('\\','/',$expected),'/').'/project-')){
        throw new RuntimeException('Legacy-Dokumentordner liegt außerhalb des erwarteten Bereichs.');
    }
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $item){
        if($item->isDir()) { if(!@rmdir($item->getPathname())) throw new RuntimeException('Ordner konnte nicht entfernt werden: '.$item->getPathname()); }
        else { if(!@unlink($item->getPathname())) throw new RuntimeException('Datei konnte nicht entfernt werden: '.$item->getPathname()); }
    }
    if(!@rmdir($real)) throw new RuntimeException('Legacy-Projektordner konnte nicht entfernt werden: '.$real);
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
            enterprise_project_storage_assign($pdo,$id,(string)($project['slug']??'project'));
            $project=enterprise_project_storage_fetch($pdo,$id);
        }
        enterprise_project_storage_provision($project,$env);
        $legacy=dirname(__DIR__).'/storage/dataform/uploads/project-'.$id;
        $target=enterprise_project_storage_documents_path($project,'dataform');
        $entry=[
            'project_id'=>$id,
            'storage_key'=>(string)$project['storage_key'],
            'legacy'=>$legacy,
            'target'=>$target,
            'mode'=>$apply?'apply':'dry-run',
            'status'=>'SKIP',
        ];
        $sourceHashes=hf88_doc_hashes($legacy);
        if($sourceHashes===[]){$entry['message']='Keine Legacy-DataForm-Dokumente gefunden.';$results[]=$entry;continue;}
        $targetHashes=hf88_doc_hashes($target);
        foreach($targetHashes as $rel=>$hash){
            if(isset($sourceHashes[$rel]) && !hash_equals((string)$sourceHashes[$rel],(string)$hash)) throw new RuntimeException('Zielkonflikt bei '.$rel);
        }
        $entry['source_files']=count($sourceHashes);
        $entry['status']=$apply?'MIGRATED':'READY';
        if($apply){
            $stats=hf88_doc_copy($legacy,$target);
            $after=hf88_doc_hashes($target);
            foreach($sourceHashes as $rel=>$hash){
                if(!isset($after[$rel]) || !hash_equals((string)$hash,(string)$after[$rel])) throw new RuntimeException('Integritätsprüfung nach Migration fehlgeschlagen: '.$rel);
            }
            $entry['copied_files']=$stats['files'];
            $entry['copied_bytes']=$stats['bytes'];
            $entry['verified_files']=count($sourceHashes);
            if($removeLegacy){hf88_doc_remove_tree($legacy);$entry['legacy_removed']=!is_dir($legacy);}else{$entry['legacy_removed']=false;}
        }
        $results[]=$entry;
    }catch(Throwable $e){$results[]=['project_id'=>$id,'storage_key'=>$project['storage_key']??null,'status'=>'ERROR','message'=>$e->getMessage()];}
}

echo json_encode([
    'phase'=>'HF88-storage-phase3-documents',
    'apply'=>$apply,
    'remove_legacy'=>$removeLegacy,
    'compatibility'=>'Legacy Media-Deskriptoren bleiben auch nach --remove-legacy lesbar; die Runtime mappt sie auf documents/dataform.',
    'projects'=>$results,
],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
