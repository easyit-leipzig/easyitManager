<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require __DIR__.'/lib/DataFormApp.php';

$pdo=df_pdo();
$form=max(1,(int)($_GET['dataform']??0));
$record=max(1,(int)($_GET['record']??0));
$field=(string)($_GET['field']??'');
$fields=df_fields($pdo,$form);
$f=null;
foreach($fields as $x) if((string)$x['name']===$field) $f=$x;
if(!$f||!in_array((string)$f['field_type'],['file','image'],true)){http_response_code(404);exit;}
$r=df_find_record($pdo,$form,$record,$fields);
$m=$r?df_media_meta((string)($r['data'][$field]??'')):null;
if(!$m){http_response_code(404);exit;}
$bytes='';
if(($m['storage']??'')==='database'){
    $bytes=base64_decode((string)($m['data_base64']??''),true)?:'';
}elseif(($m['storage']??'')==='filesystem'){
    $relative=str_replace('\\','/',ltrim((string)($m['path']??''),'/\\'));
    if($relative!=='' && preg_match('#(^|/)\.\.(/|$)#',$relative)!==1){
        $path=DF_APP_ROOT.'/'.$relative;
        $real=realpath($path);
        $bases=[DF_APP_ROOT.'/storage/dataform/uploads',DF_APP_ROOT.'/storage/projects'];
        foreach($bases as $candidate){
            $base=realpath($candidate);
            if($real&&$base&&str_starts_with(str_replace('\\','/',$real),rtrim(str_replace('\\','/',$base),'/').'/')&&is_file($real)){
                $bytes=(string)file_get_contents($real); break;
            }
        }
    }
}
if($bytes===''||hash('sha256',$bytes)!==(string)($m['sha256']??'')){http_response_code(404);exit;}
header('Content-Type: '.(string)$m['mime']);
header('X-Content-Type-Options: nosniff');
header('Content-Length: '.strlen($bytes));
header('Content-Disposition: '.((string)$f['field_type']==='image'?'inline':'attachment').'; filename="'.str_replace('"','',(string)$m['name']).'"');
echo $bytes;
