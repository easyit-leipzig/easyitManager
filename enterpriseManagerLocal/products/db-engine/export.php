<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/system/licensing/LicenseConfigStore.php';
require dirname(__DIR__,2).'/system/app/bootstrap.php';
require dirname(__DIR__,2).'/system/licensing/Autoload.php';
require __DIR__.'/DbEnginePackage.php';
use EasyIT\Enterprise\Licensing\LicenseApiClient;
$user=enterprise_require_auth('../../');$projectId=(int)($_GET['project_id']??0);
try{
 if($projectId<1)throw new RuntimeException('Projekt fehlt.');
 $pdo=enterprise_pdo();enterprise_upgrade($pdo);$st=$pdo->prepare('SELECT * FROM projects WHERE id=? LIMIT 1');$st->execute([$projectId]);$project=$st->fetch();if(!$project)throw new RuntimeException('Projekt wurde nicht gefunden.');
 enterprise_require_project_scope($user,$projectId,false);enterprise_require_project_product($user,$projectId,'db-engine',(string)$project['slug']);enterprise_require_license_capability($user,'db-engine.export');
 $cfg=EnterpriseLicenseConfigStore::load(dirname(__DIR__,2));$api=new LicenseApiClient($cfg);$token=(string)($_SESSION['enterprise_customer_account_token']??'');if($token==='')throw new RuntimeException('ACCOUNT_SESSION_INVALID');
 $claim=$api->accountProjectProductToken($token,(string)$projectId,(string)$project['slug'],'db-engine',(string)($user['customer_key']??$user['email']??''));
 $pkg=easyit_db_engine_create_package($project,$claim);
 header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.basename($pkg['filename']).'"');header('Content-Length: '.(string)$pkg['size']);header('X-Content-Type-Options: nosniff');readfile($pkg['path']);@unlink($pkg['path']);exit;
}catch(Throwable $e){http_response_code(403);header('Content-Type: text/plain; charset=utf-8');echo 'DB-Engine-Export nicht möglich: '.$e->getMessage();}
