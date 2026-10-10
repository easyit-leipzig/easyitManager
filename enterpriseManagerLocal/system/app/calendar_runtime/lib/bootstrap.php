<?php
declare(strict_types=1);

const DF_APP_ROOT = __DIR__ . '/..';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

foreach (['EnterpriseCsvPdo.php','EnterpriseSqlitePdo.php','EnterprisePgsqlPdo.php','EnterpriseOraclePdo.php','EnterpriseMssqlAdapter.php','EnterpriseMssqlPdo.php'] as $file) {
    $path=DF_APP_ROOT.'/system/app/'.$file;
    if(is_file($path)) require_once $path;
}

function df_e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function df_config(): array {
    static $cfg=null;
    if($cfg!==null)return $cfg;
    $file=DF_APP_ROOT.'/config.php';
    if(!is_file($file)){header('Location: setup.php');exit;}
    $loaded=require $file;if(!is_array($loaded))throw new RuntimeException('config.php ist ungültig.');
    return $cfg=$loaded;
}
function df_db_driver(): string {$d=strtolower(trim((string)(df_config()['db_driver']??'csv')));return in_array($d,['postgres','postgresql'],true)?'pgsql':$d;}
function df_pdo(): PDO {
    static $pdo=null;if($pdo instanceof PDO)return $pdo;$c=df_config();$d=df_db_driver();
    if($d==='csv'){
        if(!class_exists('EnterpriseCsvPdo'))throw new RuntimeException('CSV-Runtime fehlt.');
        return $pdo=new EnterpriseCsvPdo(DF_APP_ROOT.'/storage',(string)($c['db_name']??'csv'));
    }
    if($d==='sqlite'){
        if(!class_exists('EnterpriseSqlitePdo'))throw new RuntimeException('SQLite-Runtime fehlt.');
        $name=(string)($c['db_name']??'storage/sqlite/database.sqlite');
        $path=str_starts_with(str_replace('\\','/',$name),'/')?$name:DF_APP_ROOT.'/'.ltrim($name,'/');
        return $pdo=new EnterpriseSqlitePdo($path);
    }
    if($d==='pgsql')return $pdo=new EnterprisePgsqlPdo((string)$c['db_host'],(int)$c['db_port'],(string)$c['db_name'],(string)$c['db_user'],(string)$c['db_password'],(string)($c['db_schema']??'public'));
    if($d==='oracle')return $pdo=new EnterpriseOraclePdo((string)$c['db_host'],(int)$c['db_port'],(string)($c['db_service']??'XEPDB1'),(string)$c['db_user'],(string)$c['db_password']);
    if($d==='mssql')return $pdo=new EnterpriseMssqlPdo((string)$c['db_host'],(int)$c['db_port'],(string)$c['db_name'],(string)$c['db_user'],(string)$c['db_password'],(bool)($c['db_encrypt']??true),(bool)($c['db_trust_server_certificate']??false));
    if($d!=='mysql')throw new RuntimeException('Nicht unterstützter Treiber: '.$d);
    $dsn='mysql:host='.(string)$c['db_host'].';port='.(int)$c['db_port'].';dbname='.(string)$c['db_name'].';charset=utf8mb4';
    return $pdo=new PDO($dsn,(string)$c['db_user'],(string)$c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function df_csrf(): string {if(empty($_SESSION['df_csrf']))$_SESSION['df_csrf']=bin2hex(random_bytes(32));return (string)$_SESSION['df_csrf'];}
function df_csrf_check(): void {if(!hash_equals(df_csrf(),(string)($_POST['csrf']??'')))throw new RuntimeException('Sicherheitsprüfung fehlgeschlagen.');}
function df_project_id(): int {return max(1,(int)(df_config()['project_id']??1));}
function df_storage_key(): string {$k=trim((string)(df_config()['storage_key']??''));return preg_match('/^prj_[0-9]{8,18}_[a-z0-9][a-z0-9-]{0,80}$/',$k)===1?$k:'';}
function df_dataform_documents_dir(): string {$k=df_storage_key();$dir=DF_APP_ROOT.'/storage/documents/dataform';if($k!=='')$dir=DF_APP_ROOT.'/storage/documents/dataform';if(!is_dir($dir)&&!mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('Dokumentordner kann nicht angelegt werden.');return $dir;}
function df_ident(string $name): string {if(preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/',$name)!==1)throw new RuntimeException('Ungültiger SQL-Bezeichner: '.$name);return '`'.$name.'`';}
