<?php
declare(strict_types=1);
require dirname(__DIR__).'/system/app/bootstrap.php';

$pdo=enterprise_pdo();
enterprise_upgrade($pdo);
$env=enterprise_env(dirname(__DIR__).'/DataForm5-Core/.env');
$rows=$pdo->query('SELECT * FROM projects ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$results=[];
foreach($rows as $project){
    $id=(int)$project['id'];
    try{
        if(trim((string)($project['storage_key']??''))===''){
            enterprise_project_storage_assign($pdo,$id,(string)$project['slug']);
            $project=enterprise_project_storage_fetch($pdo,$id);
        }
        $root=enterprise_project_storage_provision($project,$env);
        $results[]=['project_id'=>$id,'storage_key'=>$project['storage_key'],'root'=>$root,'status'=>'OK'];
    }catch(Throwable $e){
        $results[]=['project_id'=>$id,'storage_key'=>$project['storage_key']??null,'status'=>'ERROR','message'=>$e->getMessage()];
    }
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['phase'=>'HF88-storage-phase1','projects'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
