<?php
declare(strict_types=1);

/**
 * Local loaders for centrally visible products.
 * Central availability/licensing comes from the license server; this registry only
 * says whether the corresponding module code is physically present in this manager.
 */
function enterprise_product_module_registry(): array
{
    $root=dirname(__DIR__,2);
    return [
        'dataform'=>[
            'label'=>'DataForm',
            'entry'=>'products/dataform/runtime.php',
            'installed'=>is_file($root.'/products/dataform/runtime.php'),
        ],
        'calendar'=>[
            'label'=>'easyITCalendar',
            'entry'=>'products/calendar/index.php',
            'installed'=>is_file($root.'/products/calendar/index.php'),
        ],
        'dialog'=>[
            'label'=>'Dialog',
            'entry'=>'products/dialog/index.php',
            'installed'=>is_file($root.'/products/dialog/index.php'),
        ],
        'nachhilfe'=>[
            'label'=>'Nachhilfe',
            'entry'=>'products/nachhilfe/index.php',
            'installed'=>is_file($root.'/products/nachhilfe/index.php'),
        ],
        'csv-engine'=>[
            'label'=>'CSV-Engine',
            'entry'=>'products/csv-engine/index.php',
            'installed'=>is_file($root.'/products/csv-engine/index.php'),
        ],
        'db-engine'=>[
            'label'=>'DB-Engine',
            'entry'=>'products/db-engine/index.php',
            'installed'=>is_file($root.'/products/db-engine/index.php'),
        ],
    ];
}

function enterprise_local_product_module(string $productCode): array
{
    return enterprise_product_module_registry()[$productCode]??[
        'label'=>$productCode,
        'entry'=>null,
        'installed'=>false,
    ];
}

function enterprise_project_product_portal(array $user): array
{
    if(empty($user['customer']))return ['products'=>[],'project_requests'=>[],'project_entitlements'=>[]];
    $root=dirname(__DIR__,2);require_once $root.'/system/licensing/LicenseConfigStore.php';$cfg=EnterpriseLicenseConfigStore::load($root);
    if(!is_array($cfg)||empty($cfg['enabled'])||empty($cfg['server_url']))throw new RuntimeException('LICENSE_SERVER_NOT_CONFIGURED');
    $token=(string)($_SESSION['enterprise_customer_account_token']??'');if($token==='')throw new RuntimeException('ACCOUNT_SESSION_INVALID');
    require_once $root.'/system/licensing/Autoload.php';$api=new \EasyIT\Enterprise\Licensing\LicenseApiClient($cfg);
    return $api->accountProjectProducts($token);
}

function enterprise_project_product_entitlement(array $user,string|int $projectId,string $productCode,string $projectSlug=''): ?array
{
    if(empty($user['customer']))return ['status'=>'active','legacy'=>true];
    try{$portal=enterprise_project_product_portal($user);}catch(Throwable){return null;}
    $pid=(string)$projectId;$slug=trim($projectSlug);$now=time();
    $productHasAnyEntitlement=false;
    foreach((array)($portal['project_entitlements']??[]) as $e){
        if((string)($e['product_code']??'')!==$productCode)continue;$productHasAnyEntitlement=true;
        if((string)($e['status']??'')!=='active')continue;
        $until=$e['valid_until']??null;if($until!==null&&(int)$until<$now)continue;
        if((string)($e['project_id']??'')===$pid||($slug!==''&&(string)($e['project_slug']??'')===$slug))return $e;
    }
    // Backward compatibility: licenses created before project entitlements stay usable
    // until the customer starts using project-scoped entitlements for this product.
    if(!$productHasAnyEntitlement && enterprise_customer_has_module($user,$productCode))return ['status'=>'active','legacy'=>true];
    return null;
}

function enterprise_require_project_product(array $user,string|int $projectId,string $productCode,string $projectSlug=''): void
{
    if(empty($user['customer']))return;
    $ent=enterprise_project_product_entitlement($user,$projectId,$productCode,$projectSlug);
    if(!$ent){http_response_code(403);exit('Dieses Produkt ist für das ausgewählte Projekt nicht freigegeben. Fordern Sie die Projektlizenz im Dashboard an.');}
}


/** Products that can be assigned to a project in this local manager. */
function enterprise_available_project_products(PDO $pdo,array $user): array
{
    $rows=$pdo->query("SELECT product_key,label,status,version FROM installed_products WHERE status='available' ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
    $registry=enterprise_product_module_registry();$out=[];
    foreach($rows as $row){
        $key=(string)$row['product_key'];
        if(!isset($registry[$key])||empty($registry[$key]['installed']))continue;
        if(enterprise_is_customer($user) && !enterprise_customer_has_module($user,$key))continue;
        $out[$key]=['key'=>$key,'label'=>(string)$row['label'],'version'=>(string)($row['version']??'')];
    }
    return $out;
}
