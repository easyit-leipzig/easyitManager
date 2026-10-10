<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/system/app/bootstrap.php';
require_once __DIR__.'/system/ApiDesigner.php';
require_once __DIR__.'/system/DataFormTransport.php';
require_once dirname(__DIR__,2).'/system/licensing/Autoload.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$started=microtime(true); $status=500; $pdo=null; $api=null; $endpoint=null; $key=null;
$pendingCreated=[];

try {
    $pathInfo=trim((string)($_SERVER['PATH_INFO']??''),'/');
    $segments=$pathInfo!==''?array_values(array_filter(explode('/',$pathInfo),static fn(string $v):bool=>$v!=='')):[];
    $projectId=(int)($_GET['project']??($segments[0]??0));
    $apiSlug=(string)($_GET['api']??($segments[1]??''));
    $endpointSlug=(string)($_GET['endpoint']??($segments[2]??''));
    $pathRecordId=(int)($segments[3]??0);
    if ($projectId<1 || $apiSlug==='' || $endpointSlug==='') throw new RuntimeException('Ungültige API-Adresse.',400);

    $admin=enterprise_pdo(); enterprise_upgrade($admin);
    $s=$admin->prepare('SELECT * FROM projects WHERE id=? AND product_type=? LIMIT 1');
    $s->execute([$projectId,'dataform']);
    $project=$s->fetch(PDO::FETCH_ASSOC);
    if (!$project) throw new RuntimeException('Projekt nicht gefunden.',404);

    $env=enterprise_env(dirname(__DIR__,2).'/DataForm5-Core/.env');
    $pdo = enterprise_project_store_for_project($env, $project);
    ApiDesigner::ensureSchema($pdo);

    $q=$pdo->prepare('SELECT * FROM api_definitions WHERE slug=? AND is_active=1');
    $q->execute([$apiSlug]); $api=$q->fetch(PDO::FETCH_ASSOC);
    if (!$api) throw new RuntimeException('API nicht gefunden.',404);

    $method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
    $q=$pdo->prepare('SELECT e.*,d.slug AS dataform_slug FROM api_endpoints e LEFT JOIN dataforms d ON d.id=e.dataform_id WHERE e.api_id=? AND e.slug=? AND e.http_method=? AND e.is_active=1');
    $q->execute([(int)$api['id'],$endpointSlug,$method]); $endpoint=$q->fetch(PDO::FETCH_ASSOC);
    if (!$endpoint) throw new RuntimeException('Endpunkt nicht gefunden.',404);

    if ((int)$endpoint['requires_auth']===1) {
        $key=ApiDesigner::authenticate($pdo,(int)$api['id'],ApiDesigner::bearerToken());
        if (!$key) throw new RuntimeException('Gültiger Bearer-Schlüssel erforderlich.',401);
    }
    if (!ApiDesigner::rateLimit($pdo,(int)$api['id'],(int)$endpoint['id'],$key?(int)$key['id']:null,(int)$endpoint['rate_limit_per_minute'])) {
        throw new RuntimeException('Rate-Limit überschritten.',429);
    }

    $dfId=(int)($endpoint['dataform_id']??0);
    if ($dfId<1) throw new RuntimeException('Keine DataForm-Datenquelle konfiguriert.',500);
    $manifest=\EasyIT\Enterprise\Licensing\EnterpriseRuntimeBridge::boot(dirname(__DIR__,2),$projectId,$dfId);
    $caps=array_fill_keys(array_map('strval',(array)($manifest['capabilities']??[])),true);
    if(!isset($caps['api.access'])) throw new RuntimeException('API-Zugriff ist für dieses DataForm nicht lizenziert.',403);
    $requiredCap=match((string)$endpoint['operation']){
        'list','detail'=>'dataset.read',
        'create'=>'dataset.create',
        'update'=>'dataset.update',
        'delete'=>'dataset.delete',
        default=>'dataset.read',
    };
    if(!isset($caps[$requiredCap])) throw new RuntimeException('Die angeforderte API-Operation ist für dieses DataForm nicht lizenziert.',403);
    $allFields=DataFormTransport::fields($pdo,$dfId);
    $byName=[]; foreach ($allFields as $field) $byName[(string)$field['name']]=$field;
    $selected=json_decode((string)($endpoint['fields_json']??'[]'),true);
    if (!is_array($selected) || $selected===[]) $selected=array_keys($byName);
    $selected=array_values(array_filter(array_map('strval',$selected),static fn(string $name): bool=>isset($byName[$name])));
    $fields=[]; foreach ($selected as $name) $fields[]=$byName[$name];

    $storage=new DataFormFieldStorageManager(dirname(__DIR__,2));
    $op=(string)$endpoint['operation'];
    $id=max(0,(int)($_GET['id']??$pathRecordId));

    if ($op==='list') {
        $limit=max(1,min(200,(int)($_GET['limit']??50)));
        $offset=max(0,(int)($_GET['offset']??0));
        $rows=DataFormRecordStore::all($pdo,$dfId);
        usort($rows,static fn(array $a,array $b): int=>(int)$b['id']<=>(int)$a['id']);
        $rows=array_slice($rows,$offset,$limit);
        $items=[];
        foreach ($rows as $row) {
            $item=['id'=>(int)$row['id']]+DataFormTransport::externalize($fields,(array)$row['data'],$storage,false);
            $item['created_at']=(string)($row['created_at']??'');
            $item['updated_at']=(string)($row['updated_at']??'');
            $items[]=$item;
        }
        $payload=['data'=>$items,'meta'=>['limit'=>$limit,'offset'=>$offset,'count'=>count($items)]];
    } elseif ($op==='detail') {
        if ($id<1) throw new RuntimeException('Datensatz-ID fehlt.',400);
        $row=DataFormRecordStore::find($pdo,$dfId,$id);
        if ($row===null) throw new RuntimeException('Datensatz nicht gefunden.',404);
        $item=['id'=>$id]+DataFormTransport::externalize($fields,(array)$row['data'],$storage,false);
        $item['created_at']=(string)($row['created_at']??'');
        $item['updated_at']=(string)($row['updated_at']??'');
        $payload=['data'=>$item];
    } elseif ($op==='create') {
        $input=json_decode((string)file_get_contents('php://input'),true);
        if (!is_array($input) || array_is_list($input)) throw new RuntimeException('JSON-Objekt erwartet.',422);
        $allowedInput=array_intersect_key($input,array_flip($selected));
        $norm=DataFormTransport::normalizeIncoming($fields,$allowedInput,[],$storage,['project_id'=>$projectId,'dataform_id'=>$dfId],false);
        $pendingCreated=$norm['created_media'];
        $newId=DataFormRecordStore::create($pdo,$dfId,$norm['data']);
        $pendingCreated=[];
        $row=DataFormRecordStore::find($pdo,$dfId,$newId);
        $status=201;
        $payload=['data'=>['id'=>$newId]+DataFormTransport::externalize($fields,(array)($row['data']??$norm['data']),$storage,false)];
    } elseif ($op==='update') {
        if ($id<1) throw new RuntimeException('Datensatz-ID fehlt.',400);
        $row=DataFormRecordStore::find($pdo,$dfId,$id);
        if ($row===null) throw new RuntimeException('Datensatz nicht gefunden.',404);
        $input=json_decode((string)file_get_contents('php://input'),true);
        if (!is_array($input) || array_is_list($input)) throw new RuntimeException('JSON-Objekt erwartet.',422);
        $allowedInput=array_intersect_key($input,array_flip($selected));
        $norm=DataFormTransport::normalizeIncoming($fields,$allowedInput,(array)$row['data'],$storage,['project_id'=>$projectId,'dataform_id'=>$dfId],true);
        $pendingCreated=$norm['created_media'];
        DataFormRecordStore::update($pdo,$dfId,$id,$norm['data']);
        $pendingCreated=[];
        DataFormTransport::cleanup($norm['replaced_media'],$storage);
        $updated=DataFormRecordStore::find($pdo,$dfId,$id);
        $payload=['data'=>['id'=>$id]+DataFormTransport::externalize($fields,(array)($updated['data']??$norm['data']),$storage,false)];
    } elseif ($op==='delete') {
        if ($id<1) throw new RuntimeException('Datensatz-ID fehlt.',400);
        $row=DataFormRecordStore::find($pdo,$dfId,$id);
        if ($row===null) throw new RuntimeException('Datensatz nicht gefunden.',404);
        $media=[];
        foreach ($allFields as $field) {
            if (!DataFormFieldTypeRegistry::isMedia((string)$field['field_type'])) continue;
            $raw=(string)($row['data'][(string)$field['name']]??''); if ($raw!=='') $media[]=$raw;
        }
        DataFormRecordStore::delete($pdo,$dfId,$id);
        DataFormTransport::cleanup($media,$storage);
        $payload=['deleted'=>true,'id'=>$id];
    } else {
        throw new RuntimeException('Nicht unterstützte Operation.',405);
    }

    if ($status===500) $status=200;
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($pendingCreated!==[]) {
        try { $storage=$storage??new DataFormFieldStorageManager(dirname(__DIR__,2)); DataFormTransport::cleanup($pendingCreated,$storage); } catch (Throwable) {}
    }
    $code=(int)$e->getCode(); $status=($code>=400&&$code<=599)?$code:500;
    http_response_code($status);
    echo json_encode(['error'=>['status'=>$status,'message'=>$e->getMessage()]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} finally {
    if ($pdo instanceof PDO && is_array($api)) {
        try {
            ApiDesigner::log($pdo,(int)$api['id'],is_array($endpoint)?(int)$endpoint['id']:null,is_array($key)?(int)$key['id']:null,strtoupper($_SERVER['REQUEST_METHOD']??'GET'),(string)($_SERVER['REQUEST_URI']??''),$status,(int)round((microtime(true)-$started)*1000));
        } catch (Throwable) {}
    }
}
