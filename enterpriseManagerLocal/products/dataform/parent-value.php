<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require_once __DIR__ . '/system/RelationManager.php';
require_once __DIR__ . '/system/DataFormRecordStore.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user=enterprise_require_auth('../../');
    enterprise_require_customer_module($user,'dataform');
    require_once dirname(__DIR__,2).'/system/licensing/Autoload.php';

    $projectId=(int)($_GET['project']??0);
enterprise_require_project_scope($user,$projectId,false);
    $childDataformId=(int)($_GET['child_dataform']??0);
    if($childDataformId>0)enterprise_require_dataform_scope($user,$projectId,$childDataformId);
    $relationId=(int)($_GET['relation']??0);
    $parentRecordId=(int)($_GET['parent_record']??0);
    $parentFieldId=(int)($_GET['parent_field']??0);

    if(
        $projectId<1
        ||$childDataformId<1
        ||$relationId<1
        ||$parentRecordId<1
        ||$parentFieldId<1
    ){
        throw new RuntimeException('Unvollständige Elternwert-Anfrage.');
    }

    $adminPdo=enterprise_pdo();
    enterprise_upgrade($adminPdo);

    $projectStmt=$adminPdo->prepare(
        'SELECT * FROM projects WHERE id=? AND product_type=? LIMIT 1'
    );
    $projectStmt->execute([$projectId,'dataform']);
    $project=$projectStmt->fetch();

    if(!$project){
        throw new RuntimeException('DataForm-Projekt nicht gefunden.');
    }
    $manifest=\EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::boot(dirname(__DIR__,2),$projectId,$childDataformId);
    if(!in_array('relation.read',(array)($manifest['capabilities']??[]),true)) throw new RuntimeException('CAPABILITY_NOT_LICENSED');

    $env=enterprise_env(dirname(__DIR__,2).'/DataForm5-Core/.env');
    $pdo = enterprise_project_store_for_project($env, $project);

    RelationManager::ensureSchema($pdo);
    RelationManager::repairLegacyOneToMany($pdo);

    $relationStmt=$pdo->prepare(
        "SELECT source_dataform_id,target_dataform_id,lookup_field_id
         FROM dataform_relations
         WHERE id=?
           AND target_dataform_id=?
           AND relation_type='1:n'
           AND is_enabled=1
           AND lookup_field_id IS NOT NULL
         LIMIT 1"
    );
    $relationStmt->execute([$relationId,$childDataformId]);
    $relation=$relationStmt->fetch();

    if(!$relation){
        throw new RuntimeException('Ungültige Elternbeziehung.');
    }

    $fieldStmt=$pdo->prepare(
        'SELECT name FROM dataform_fields WHERE id=? AND dataform_id=? LIMIT 1'
    );
    $fieldStmt->execute([
        $parentFieldId,
        (int)$relation['source_dataform_id']
    ]);
    $parentFieldName=$fieldStmt->fetchColumn();

    if($parentFieldName===false){
        throw new RuntimeException('Elternfeld nicht gefunden.');
    }

    $parentRecord=DataFormRecordStore::find(
        $pdo,
        (int)$relation['source_dataform_id'],
        $parentRecordId
    );

    if($parentRecord===null){
        throw new RuntimeException(
            'Eltern-Datensatz nicht gefunden.'
        );
    }

    $data=(array)($parentRecord['data']??[]);
    $value=$data[(string)$parentFieldName]??null;

    if(is_array($value)||is_object($value)){
        $value=json_encode(
            $value,
            JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
        );
    }elseif($value!==null){
        $value=(string)$value;
    }

    echo json_encode(
        ['ok'=>true,'value'=>$value],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
} catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(
        ['ok'=>false,'error'=>$e->getMessage()],
        JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
    );
}
