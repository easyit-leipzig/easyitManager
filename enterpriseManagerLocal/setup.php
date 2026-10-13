<?php
declare(strict_types=1);

if(session_status()!==PHP_SESSION_ACTIVE)session_start();
require __DIR__.'/system/ui/layout.php';
require_once __DIR__.'/system/app/bootstrap.php';
require_once __DIR__.'/system/licensing/Autoload.php';

use EasyIT\Enterprise\Licensing\LicenseApiClient;
use EasyIT\Enterprise\Licensing\Transport;

$kernel=require __DIR__.'/DataForm5-Core/bootstrap/app.php';
$installer=$kernel->container()->get(\DataForm5\Installer\Core\EnterpriseInstaller::class);
if(empty($_SESSION['setup_csrf']))$_SESSION['setup_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['setup_csrf'];
$status=$installer->status();
$message='';$error='';$result=null;
$licenseServerState=[];
$projectDbConfirmationRequired=false;
$projectDbConfirmationToken='';
$projectDbExistingName='';

/**
 * Liefert ausschließlich die in der aktuellen PHP-Laufzeit tatsächlich
 * nutzbaren Speicher-/Datenbanktreiber. CSV benötigt keinen PDO-Treiber.
 */
function setup_runtime_driver_options(): array
{
    $availablePdo = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
    $all = [
        'mysql' => ['label' => 'MariaDB / MySQL', 'pdo' => 'mysql'],
        'pgsql' => ['label' => 'PostgreSQL', 'pdo' => 'pgsql'],
        'oracle' => ['label' => 'Oracle XE', 'pdo' => 'oci'],
        'mssql' => ['label' => 'Microsoft SQL Server', 'pdo' => 'sqlsrv'],
        'csv' => ['label' => 'CSV', 'pdo' => null],
        'sqlite' => ['label' => 'SQLite', 'pdo' => 'sqlite'],
    ];
    $options = [];
    foreach ($all as $driver => $meta) {
        if ($meta['pdo'] === null || in_array($meta['pdo'], $availablePdo, true)) {
            $options[$driver] = $meta['label'];
        }
    }
    return $options;
}


/**
 * HF136: Resolve the sibling license server for the one-step bootstrap.
 *
 * A previously persisted remote server_url must never hijack a fresh/local
 * bootstrap. We only reuse an already configured URL when it is loopback.
 * Otherwise the URL is derived from the current local manager request.
 */
function setup_normalize_license_server_url(string $url): string
{
    $url=trim(html_entity_decode($url,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if($url==='')return '';

    // HF141: niemals Dateisystem-Trenner in einer HTTP-URL weiterreichen.
    // Berücksichtigt auch mehrfach maskierte Werte und %5C aus älteren Configs.
    $url=preg_replace('~%5[cC]~','/',$url)??$url;
    $url=preg_replace('~\\\\+~','/',$url)??$url;

    // Fehlendes Schema für lokale Eingaben komfortabel ergänzen.
    if(!preg_match('~^[a-z][a-z0-9+.-]*://~i',$url))$url='http://'.$url;

    $parts=parse_url($url);
    if(!is_array($parts))return '';
    $scheme=strtolower((string)($parts['scheme']??'http'));
    $host=strtolower(trim((string)($parts['host']??'')));
    if($host==='')return '';
    $port=isset($parts['port'])?':'.(int)$parts['port']:'';
    $path=(string)($parts['path']??'');
    $path=preg_replace('~\\\\+~','/',$path)??$path;
    $path=preg_replace('~/+~','/',$path)??$path;
    $path=$path==='/'?'':rtrim($path,'/');

    // Userinfo, Query und Fragment sind für die Lizenzserver-Basis-URL verboten.
    return $scheme.'://'.$host.$port.$path;
}
function setup_is_loopback_license_server_url(string $url): bool
{
    $url=setup_normalize_license_server_url($url);
    $host=strtolower((string)(parse_url($url,PHP_URL_HOST)??''));
    return in_array($host,['localhost','127.0.0.1','::1'],true);
}

/**
 * HF140: Resolve and normalize an explicitly entered local license-server URL.
 * The complete URL is used so non-default ports and custom local paths work.
 */
function setup_local_license_server_url(?string $explicit=null): string
{
    $explicit=setup_normalize_license_server_url((string)$explicit);
    if($explicit!=='')return $explicit;

    $cfg=EnterpriseLicenseConfigStore::load(__DIR__);
    $configured=setup_normalize_license_server_url((string)($cfg['server_url']??''));
    if($configured!=='' && setup_is_loopback_license_server_url($configured))return $configured;

    $host=trim((string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'localhost'));
    if($host==='')$host='localhost';
    $https=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['SERVER_PORT']??'')==='443');
    $scheme=$https?'https':'http';
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/enterpriseManagerLocal/setup.php'));
    $managerDir=rtrim(dirname($script),'/');
    $parent=rtrim(dirname($managerDir),'/');
    return $scheme.'://'.$host.($parent===''?'':$parent).'/easyit-license-server';
}

/**
 * HF138: Read the license-server commissioning state without changing manager state.
 * The server setup itself is performed exclusively on the license-server setup page.
 */
function setup_license_server_state(?string $requestedUrl=null): array
{
    $url=setup_local_license_server_url($requestedUrl);
    $state=[
        'url'=>$url,
        'setup_url'=>$url.'/setup.php',
        'reachable'=>false,
        'ready'=>false,
        'status'=>'unavailable',
        'message'=>'Lizenzserver nicht erreichbar.',
    ];
    try{
        [$httpStatus,$headers,$raw]=Transport::get($url.'/api/v1/health',['Accept: application/json'],5,false);
        $data=json_decode($raw,true);
        if($httpStatus<200||$httpStatus>=300||!is_array($data)||($data['success']??false)!==true){
            $state['message']='Lizenzserver antwortet, liefert aber keinen gültigen Health-Status.';
            return $state;
        }
        $payload=is_array($data['payload']??null)?$data['payload']:[];
        $state['reachable']=true;
        $state['ready']=($payload['ready']??false)===true && (string)($payload['status']??'')==='ready';
        $state['status']=$state['ready']?'ready':'setup_required';
        $state['message']=$state['ready']?'Lizenzserver ist READY.':'Lizenzserver ist erreichbar, aber noch nicht READY. Öffnen Sie jetzt das Lizenzserver-Setup und schließen Sie es vollständig ab.';
    }catch(Throwable $e){
        $state['message']='Lizenzserver nicht erreichbar: '.$e->getMessage();
    }
    return $state;
}

/**
 * Trust-on-first-use is deliberately limited to a loopback license server.
 * The health body must verify against the public key contained in its signed
 * trust bundle before that key is written to persistent manager config.
 */
function setup_configure_local_license_server(?string $requestedUrl=null): array
{
    $root=__DIR__;$url=setup_local_license_server_url($requestedUrl);$parts=parse_url($url);
    $host=strtolower((string)($parts['host']??''));
    if(!in_array($host,['localhost','127.0.0.1','::1'],true))throw new RuntimeException('Die automatische Erstkopplung ist nur mit einem lokalen Lizenzserver zulässig.');
    [$status,$headers,$raw]=Transport::get($url.'/api/v1/health',['Accept: application/json'],15,false);
    $data=json_decode($raw,true);
    if($status<200||$status>=300||!is_array($data)||($data['success']??false)!==true)throw new RuntimeException('Der lokale Lizenzserver ist nicht erreichbar oder liefert keinen gültigen Health-Status.');
    $payload=is_array($data['payload']??null)?$data['payload']:[];
    if(($payload['ready']??false)!==true||(string)($payload['status']??'')!=='ready')throw new RuntimeException('Lizenzserver noch nicht eingerichtet. Bitte zuerst das Lizenzserver-Setup unter '.$url.'/setup.php vollständig abschließen.');
    $bundle=$data['server_trust']??null;
    if(!is_array($bundle)||(string)($bundle['schema']??'')!=='easyit.license.server-trust.v1')throw new RuntimeException('Der lokale Lizenzserver liefert keinen gültigen Vertrauensanker.');
    $keyId=trim((string)($bundle['active_key_id']??''));$public='';
    foreach((array)($bundle['keys']??[]) as $row){
        if(is_array($row)&&(string)($row['key_id']??'')===$keyId&&(string)($row['status']??'')==='active'){$public=trim((string)($row['public_key']??''));break;}
    }
    $pub=base64_decode($public,true);$sig=base64_decode(trim((string)($headers['x-easyit-server-signature']??'')),true);
    if($keyId===''||$pub===false||strlen($pub)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||$sig===false||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES)throw new RuntimeException('Der lokale Lizenzserver liefert keinen verwendbaren Ed25519-Schlüssel.');
    if(trim((string)($headers['x-easyit-server-key-id']??''))!==$keyId||!sodium_crypto_sign_verify_detached($sig,$raw,$pub))throw new RuntimeException('Die signierte Health-Antwort des Lizenzservers ist ungültig.');
    $cfg=[
        'enabled'=>true,'server_url'=>$url,'connect_timeout'=>5,'request_timeout'=>15,'verify_tls'=>false,
        'storage_dir'=>$root.'/storage/licensing','trusted_server_keys'=>[$keyId=>$public],
    ];
    EnterpriseLicenseConfigStore::save($root,$cfg);
    return $cfg;
}

/** Start the freshly created local superadmin session without asking for the same credentials twice. */

/** HF159: Build the project-store connection metadata used for the existing Demo DB preflight. */
function setup_project_store_env(array $form): array
{
    return [
        'PROJECT_DB_DRIVER'=>(string)$form['project_driver'],
        'PROJECT_DB_HOST'=>(string)$form['project_db_host'],
        'PROJECT_DB_PORT'=>(string)$form['project_db_port'],
        'PROJECT_DB_MAINTENANCE_DATABASE'=>(string)$form['project_db_maintenance_database'],
        'PROJECT_DB_DATABASE'=>(string)$form['project_database'],
        'PROJECT_DB_SCHEMA'=>(string)$form['project_db_schema'],
        'PROJECT_DB_USERNAME'=>(string)$form['project_db_username'],
        'PROJECT_DB_PASSWORD'=>(string)$form['project_db_password'],
        'PROJECT_DB_ORACLE_SERVICE'=>(string)$form['project_oracle_service'],
        'PROJECT_DB_CHARSET'=>'utf8mb4',
        'PROJECT_DB_ENCRYPT'=>'true',
        'PROJECT_DB_TRUST_SERVER_CERTIFICATE'=>'false',
        // Needed by the PostgreSQL deletion guard when admin/project share a database.
        'ADMIN_DB_DRIVER'=>(string)$form['admin_driver'],
        'ADMIN_DB_DATABASE'=>(string)$form['db_database'],
        'ADMIN_DB_SCHEMA'=>(string)$form['admin_db_schema'],
    ];
}

function setup_project_store_fingerprint(array $form): string
{
    $parts=[
        strtolower((string)$form['project_driver']),
        (string)$form['project_db_host'],
        (string)$form['project_db_port'],
        (string)$form['project_database'],
        (string)$form['project_db_schema'],
        (string)$form['project_oracle_service'],
        (string)$form['project_db_username'],
    ];
    return hash('sha256',implode('|',$parts));
}

function setup_project_store_exists(array $form): bool
{
    $driver=strtolower((string)$form['project_driver']);
    if(!in_array($driver,['mysql','pgsql','oracle','mssql'],true))return false;
    require_once __DIR__.'/system/app/project_store.php';
    return enterprise_project_store_exists(setup_project_store_env($form),(string)$form['project_database'],$driver);
}

function setup_project_store_delete(array $form): void
{
    $driver=strtolower((string)$form['project_driver']);
    if(!in_array($driver,['mysql','pgsql','oracle','mssql'],true))throw new RuntimeException('Dieser Projektspeicher unterstützt keine Datenbank-Neuanlage über den Installer.');
    require_once __DIR__.'/system/app/project_store.php';
    $name=(string)$form['project_database'];
    if(!enterprise_project_store_delete(setup_project_store_env($form),$name,$driver))throw new RuntimeException('Die vorhandene Projektdatenbank konnte nicht gelöscht werden: '.$name);
    if(enterprise_project_store_exists(setup_project_store_env($form),$name,$driver))throw new RuntimeException('Sicherheitsprüfung fehlgeschlagen: Die Projektdatenbank ist nach dem Löschen weiterhin vorhanden: '.$name);
}

function setup_login_installed_superadmin(int $userId): void
{
    $pdo=enterprise_pdo();enterprise_upgrade($pdo);
    $stmt=$pdo->prepare("SELECT u.id,u.username,u.email,GROUP_CONCAT(r.name) roles FROM users u LEFT JOIN user_roles ur ON ur.user_id=u.id LEFT JOIN roles r ON r.id=ur.role_id WHERE u.id=? GROUP BY u.id LIMIT 1");
    $stmt->execute([$userId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Der angelegte Superadministrator konnte nicht für die Sitzung geladen werden.');
    session_regenerate_id(true);
    $_SESSION['enterprise_user']=[
        'id'=>(int)$row['id'],'username'=>(string)$row['username'],'email'=>(string)($row['email']??''),
        'roles'=>!empty($row['roles'])?array_values(array_filter(explode(',',(string)$row['roles']))):[],
        'permissions'=>enterprise_permissions($pdo,(int)$row['id']),
    ];
    enterprise_audit($pdo,(int)$row['id'],'auth.login','user',(string)$row['id'],['source'=>'one_step_setup']);
}

$licenseServerUrl=setup_local_license_server_url((string)($_POST['license_server_url']??''));
$licenseServerState=setup_license_server_state($licenseServerUrl);

$driverOptions = setup_runtime_driver_options();
$defaultDriver = isset($driverOptions['mysql']) ? 'mysql' : (array_key_first($driverOptions) ?? 'csv');
$unavailableSelections = [];
// RC1.1 legacy installer driver marker: ['mysql','csv','sqlite','pgsql','oracle','mssql']
$form=[
 'admin_driver'=>$defaultDriver,'admin_csv_base'=>'storage/admin-csv','admin_sqlite_base'=>'storage/admin-sqlite',
 'admin_db_host'=>'127.0.0.1','admin_db_port'=>'3306','admin_oracle_service'=>'XEPDB1','admin_db_maintenance_database'=>'postgres','db_database'=>'easyit_license','admin_db_schema'=>'public','admin_db_username'=>'root','admin_db_password'=>'',
 'project_driver'=>$defaultDriver,'project_name'=>'Demo','project_database'=>'easyit_project_demo',
 'project_db_host'=>'127.0.0.1','project_db_port'=>'3306','project_oracle_service'=>'XEPDB1','project_db_maintenance_database'=>'postgres','project_db_schema'=>'easyit_project_demo','project_db_username'=>'root','project_db_password'=>'',
 'admin_username'=>'admin','admin_email'=>'','admin_password'=>'','admin_password_confirm'=>'',
 'products'=>['dataform'],'environment'=>'production','timezone'=>'Europe/Berlin',
];
if($_SERVER['REQUEST_METHOD']==='POST'){
 $adminDriver=strtolower((string)($_POST['admin_driver']??$defaultDriver));if(in_array($adminDriver,['postgres','postgresql'],true))$adminDriver='pgsql';
 $projectDriver=strtolower((string)($_POST['project_driver']??$defaultDriver));if(in_array($projectDriver,['postgres','postgresql'],true))$projectDriver='pgsql';
 if(!isset($driverOptions[$adminDriver]))$unavailableSelections[]='Administrationsspeicher: '.$adminDriver;
 if(!isset($driverOptions[$projectDriver]))$unavailableSelections[]='Projektdatenspeicher: '.$projectDriver;
 $form=[
  'admin_driver'=>isset($driverOptions[$adminDriver])?$adminDriver:$defaultDriver,
  'admin_csv_base'=>trim((string)($_POST['admin_csv_base']??'storage/admin-csv')),
  'admin_sqlite_base'=>trim((string)($_POST['admin_sqlite_base']??'storage/admin-sqlite')),
  'admin_db_host'=>trim((string)($_POST['admin_db_host']??$_POST['db_host']??'127.0.0.1')),
  'admin_db_port'=>(string)($_POST['admin_db_port']??$_POST['db_port']??($adminDriver==='pgsql'?'5432':($adminDriver==='oracle'?'1521':($adminDriver==='mssql'?'1433':'3306')))),
  'db_database'=>trim((string)($_POST['db_database']??'easyit_license')),
  'admin_db_schema'=>trim((string)($_POST['admin_db_schema']??'public')),
  'admin_db_maintenance_database'=>trim((string)($_POST['admin_db_maintenance_database']??'postgres')),
  'admin_oracle_service'=>trim((string)($_POST['admin_oracle_service']??'XEPDB1')),
  'admin_db_username'=>trim((string)($_POST['admin_db_username']??$_POST['db_username']??($adminDriver==='pgsql'?'postgres':($adminDriver==='oracle'?'easyit_admin':'root')))),
  'admin_db_password'=>(string)($_POST['admin_db_password']??$_POST['db_password']??''),
  'project_driver'=>isset($driverOptions[$projectDriver])?$projectDriver:$defaultDriver,
  'project_name'=>trim((string)($_POST['project_name']??'Demo')),
  'project_database'=>trim((string)($_POST['project_database']??'easyit_project_demo')),
  'project_db_host'=>trim((string)($_POST['project_db_host']??$_POST['db_host']??'127.0.0.1')),
  'project_db_port'=>(string)($_POST['project_db_port']??$_POST['db_port']??($projectDriver==='pgsql'?'5432':($projectDriver==='oracle'?'1521':($projectDriver==='mssql'?'1433':'3306')))),
  'project_oracle_service'=>trim((string)($_POST['project_oracle_service']??'XEPDB1')),
  'project_db_schema'=>trim((string)($_POST['project_db_schema']??'easyit_project_demo')),
  'project_db_maintenance_database'=>trim((string)($_POST['project_db_maintenance_database']??'postgres')),
  'project_db_username'=>trim((string)($_POST['project_db_username']??$_POST['db_username']??($projectDriver==='pgsql'?'postgres':($projectDriver==='oracle'?'easyit_project':'root')))),
  'project_db_password'=>(string)($_POST['project_db_password']??$_POST['db_password']??''),
  'admin_username'=>trim((string)($_POST['admin_username']??'admin')),
  'admin_email'=>trim((string)($_POST['admin_email']??'')),'admin_password'=>(string)($_POST['admin_password']??''),
  'admin_password_confirm'=>(string)($_POST['admin_password_confirm']??''),
  'products'=>array_values(array_intersect(['dataform','dialog','nachhilfe','calendar','csv-engine','sqlite-engine','pgsql-engine','oracle-engine','mssql-engine'],(array)($_POST['products']??[]))),
  'environment'=>in_array((string)($_POST['environment']??''),['production','development'],true)?(string)$_POST['environment']:'production',
  'timezone'=>trim((string)($_POST['timezone']??'Europe/Berlin')),
 ];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new RuntimeException('CSRF-Prüfung fehlgeschlagen.');
        $setupAction=(string)($_POST['setup_action']??'install');
        if($setupAction==='check_license_server'){
            if(!setup_is_loopback_license_server_url($licenseServerUrl))throw new RuntimeException('Für die automatische Erstkopplung ist nur ein lokaler Lizenzserver (localhost, 127.0.0.1 oder ::1) zulässig.');
            $licenseServerState=setup_license_server_state($licenseServerUrl);
            if(($licenseServerState['reachable']??false)!==true)throw new RuntimeException((string)($licenseServerState['message']??'Lizenzserver nicht erreichbar.'));
            $cfg=EnterpriseLicenseConfigStore::load(__DIR__);
            $cfg['server_url']=$licenseServerUrl;
            if(!array_key_exists('enabled',$cfg))$cfg['enabled']=false;
            if(!array_key_exists('connect_timeout',$cfg))$cfg['connect_timeout']=5;
            if(!array_key_exists('request_timeout',$cfg))$cfg['request_timeout']=15;
            if(!array_key_exists('verify_tls',$cfg))$cfg['verify_tls']=false;
            EnterpriseLicenseConfigStore::save(__DIR__,$cfg);
            $message='Lizenzserver-Adresse gespeichert. '.(string)($licenseServerState['message']??'');
        } elseif(($status['installed']??false) && $setupAction==='finish_activation'){
            $licenseCfg=setup_configure_local_license_server($licenseServerUrl);
            $api=new LicenseApiClient($licenseCfg);
            $api->bootstrapLocalActivate(trim((string)@file_get_contents(__DIR__.'/VERSION')) ?: 'unknown');
            $adminUserId=(int)($status['lock']['admin_user_id']??0);
            if($adminUserId<1)throw new RuntimeException('Die Installationsmetadaten enthalten keinen Superadministrator.');
            setup_login_installed_superadmin($adminUserId);
            header('Location: app/dashboard.php?setup=complete');exit;
        }
        if($setupAction==='check_license_server'){
            // Connection test only; no manager database or activation changes here.
        } else {
        if($status['installed']??false)throw new RuntimeException('Installation ist bereits abgeschlossen. Verwenden Sie für die Aktivierung die Abschluss-/Recovery-Funktion.');
        if($unavailableSelections!==[])throw new RuntimeException('Nicht verfügbarer Datenbanktreiber wurde übermittelt. Fehlende PDO-Treiber dürfen nicht als Datenquelle gewählt werden: '.implode(', ',$unavailableSelections));
        // HF138: hard preflight. Do not create/modify manager databases until the license server is READY.
        $licenseCfg=setup_configure_local_license_server($licenseServerUrl);
        $password=(string)($_POST['admin_password']??'');
        $confirm=(string)($_POST['admin_password_confirm']??'');
        if($password!==$confirm)throw new RuntimeException('Superadmin-Kennwörter stimmen nicht überein.');
        if($form['project_name']==='')throw new RuntimeException('Projektname ist erforderlich.');
        if($form['admin_driver']==='pgsql' && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$form['admin_db_schema'])!==1)throw new RuntimeException('Ungültiges PostgreSQL-Administrationsschema.');
        if($form['project_driver']==='pgsql' && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/',$form['project_db_schema'])!==1)throw new RuntimeException('Ungültiges PostgreSQL-Projektschema.');
        // HF159: Existing project databases are never replaced implicitly.
        // First submission stops here and asks whether the existing DB shall be reused or recreated.
        $projectDbExists=setup_project_store_exists($form);
        $projectExistingAction=(string)($_POST['project_existing_action']??'');
        $projectConfirmToken=(string)($_POST['project_db_confirmation_token']??'');
        $projectFingerprint=setup_project_store_fingerprint($form);
        $pending=is_array($_SESSION['setup_project_db_confirmation']??null)?$_SESSION['setup_project_db_confirmation']:[];
        $confirmed=$projectDbExists
            && in_array($projectExistingAction,['reuse','recreate'],true)
            && $projectConfirmToken!==''
            && hash_equals((string)($pending['token']??''),$projectConfirmToken)
            && hash_equals((string)($pending['fingerprint']??''),$projectFingerprint)
            && (int)($pending['created_at']??0) >= time()-900;

        if($projectDbExists && !$confirmed){
            $projectDbConfirmationRequired=true;
            $projectDbExistingName=(string)$form['project_database'];
            $projectDbConfirmationToken=bin2hex(random_bytes(24));
            $_SESSION['setup_project_db_confirmation']=[
                'token'=>$projectDbConfirmationToken,
                'fingerprint'=>$projectFingerprint,
                'created_at'=>time(),
            ];
            $message='Die Projektdatenbank „'.$projectDbExistingName.'“ ist bereits vorhanden. Bitte wählen Sie ausdrücklich, ob sie unverändert verwendet oder gelöscht und vollständig neu angelegt werden soll.';
        }else{
            if($projectDbExists && $projectExistingAction==='recreate'){
                setup_project_store_delete($form);
                $message='Die vorhandene Projektdatenbank „'.(string)$form['project_database'].'“ wurde nach ausdrücklicher Bestätigung gelöscht und wird neu angelegt.';
            }
            unset($_SESSION['setup_project_db_confirmation']);
        $result=$installer->install([
            'environment'=>$form['environment'],
            'timezone'=>$form['timezone'],
            'project_name'=>$form['project_name'],
            'database'=>[
                'driver'=>$form['admin_driver'],
                'base_path'=>$form['admin_csv_base'],
                'sqlite_base_path'=>$form['admin_sqlite_base'],
                'host'=>$form['admin_db_host'],
                'service'=>$form['admin_oracle_service'],
                'port'=>(int)$form['admin_db_port'],
                'database'=>$form['db_database'],
                'schema'=>$form['admin_db_schema'],
                'maintenance_database'=>$form['admin_db_maintenance_database'],
                'username'=>$form['admin_db_username'],
                'password'=>$form['admin_db_password'],
            ],
            'project_database'=>[
                'driver'=>$form['project_driver'],
                'database'=>$form['project_database'],
                'schema'=>$form['project_db_schema'],
                'maintenance_database'=>$form['project_db_maintenance_database'],
                'host'=>$form['project_db_host'],
                'service'=>$form['project_oracle_service'],
                'port'=>(int)$form['project_db_port'],
                'username'=>$form['project_db_username'],
                'password'=>$form['project_db_password'],
            ],
            'admin'=>[
                'username'=>$form['admin_username'],
                'email'=>$form['admin_email'],
                'password'=>$password,
            ],
            'products'=>$form['products'],
        ]);
        // HF138: the READY preflight above already established the trusted local server configuration.
        $api=new LicenseApiClient($licenseCfg);
        $api->bootstrapLocalActivate(trim((string)@file_get_contents(__DIR__.'/VERSION')) ?: 'unknown');
        setup_login_installed_superadmin((int)($result['admin_user_id']??0));
        $_SESSION['enterprise_setup_completed_once']=time();
        header('Location: app/dashboard.php?setup=complete');
        exit;
        } // HF159 existing project database decision
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

ob_start();
?>
<section class="hero"><span class="badge">RC1.1 · STAND 4 MSSQL + DF/DS</span><h1>Installation & Administration</h1>
<p>Der Lizenzserver wird zuerst auf seiner eigenen Ein-Seiten-Einrichtung vollständig auf READY gesetzt. Danach richtet dieser Manager seine Datenbanken und den Superadministrator ein, baut die lokale Vertrauensbeziehung auf und führt die Bootstrap-Aktivierung ohne zweiten Anmeldeschritt durch.</p>
<div class="actions">
<a class="button" href="installer/database.php">Datenbank-Assistent öffnen</a>
<a class="button secondary" href="recovery.php">Recovery / Reset</a>
<a class="button secondary" href="app/dashboard.php">Enterprise-Dashboard</a>
</div>
</section>

<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<?php if((string)($_GET['required']??'')==='license-server'):?>
<div class="notice error"><strong>Installation erforderlich.</strong> Das Dashboard kann erst geöffnet werden, nachdem der Lizenzserver vollständig eingerichtet und mit diesem Enterprise Manager gekoppelt wurde.</div>
<?php endif;?>
<section class="card" id="license-server"><h2>Voraussetzung: Lizenzserver</h2>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=e($csrf)?>">
<input type="hidden" name="setup_action" value="check_license_server">
<div class="form-grid">
<label>Lizenzserver-URL
<input name="license_server_url" type="url" value="<?=e($licenseServerUrl)?>" required placeholder="http://localhost/easyit-license-server">
<small>Vollständige lokale URL einschließlich optionalem Port und Pfad, z. B. <code>http://localhost:8080/easyit-license-server</code>.</small>
</label>
<label>Health-Endpunkt
<input value="<?=e(rtrim($licenseServerUrl,'/').'/api/v1/health')?>" readonly>
<small>Wird vom Manager zur READY-Prüfung und für den Vertrauensaufbau verwendet.</small>
</label>
</div>
<p><strong>Status:</strong> <?php if(($licenseServerState['ready']??false)===true):?><strong>READY</strong><?php elseif(($licenseServerState['reachable']??false)===true):?><strong>SETUP_REQUIRED</strong><?php else:?><strong>NICHT ERREICHBAR</strong><?php endif;?></p>
<p><?=e((string)($licenseServerState['message']??''))?></p>
<?php if(($licenseServerState['reachable']??false)===true && ($licenseServerState['ready']??false)!==true):?><div class="notice"><strong>Nächster Schritt:</strong> Öffnen Sie das Lizenzserver-Setup, schließen Sie dort Datenbank und Superadministrator ab und kehren Sie anschließend hierher zurück. Danach genügt "Status neu prüfen".</div><?php endif;?>
<div class="actions">
<button class="button" type="submit">Adresse übernehmen und Status prüfen</button>
<a class="button secondary" href="<?=e((string)($licenseServerState['setup_url']??rtrim($licenseServerUrl,'/').'/setup.php'))?>">Lizenzserver jetzt einrichten</a>
<button class="button secondary" type="submit">Status neu prüfen</button>
</div>
</form>
<div class="notice"><strong>Trennung der Zugangsdaten:</strong> Datenbankzugang und Superadministrator des Lizenzservers werden ausschließlich auf dessen Ein-Seiten-Setup erfasst. Der Enterprise Manager speichert nur die Serveradresse und anschließend den geprüften Vertrauensanker.</div>
</section>

<section class="card"><h2>1. Systemprüfung</h2>
<table><thead><tr><th>Prüfung</th><th>Status</th></tr></thead><tbody>
<?php foreach((array)($status['inspection']['checks']??$status['inspection']['requirements']??[]) as $index=>$value):
if(is_array($value)){
    $name=(string)($value['name']??$index);
    $ok=(bool)($value['passed']??$value['ok']??false);
    $required=(bool)($value['required']??true);
    $detail=trim((string)($value['message']??''));
}else{
    $name=(string)$index;
    $ok=(bool)$value;
    $required=true;
    $detail='';
}
$statusLabel=$ok?'<strong>OK</strong>':($required?'Fehler':'Optional');?>
<tr><td><?=e($name)?><?php if($detail!==''):?><br><small><?=e($detail)?></small><?php endif;?></td><td><?=$statusLabel?></td></tr>
<?php endforeach;?>
<?php foreach((array)($status['inspection']['required_extensions']??[]) as $name=>$ok):?>
<tr><td>Extension <?=e((string)$name)?></td><td><?=$ok?'<strong>OK</strong>':'Fehlt'?></td></tr>
<?php endforeach;?>
</tbody></table></section>

<?php if($status['installed']??false):?>
<section class="card"><h2>Installation abgeschlossen – Wartungsmodus verfügbar</h2>
<p>Der Install-Lock ist gesetzt. Der normale Erstinstallationsweg führt nach erfolgreicher Ein-Schritt-Einrichtung direkt in das Enterprise-Dashboard. Diese Seite bleibt für kontrollierte Wartung und Recovery erreichbar.</p>
<div class="actions">
<a class="button" <?= easyit_button_attributes('system_reparieren') ?> href="installer/database.php">Datenbanken / .env prüfen und reparieren</a>
<a class="button secondary" <?= easyit_button_attributes('suchen') ?> href="health.php">Systemprüfung</a>
<a class="button secondary" href="recovery.php">Recovery / Reset</a>
<a class="button secondary" href="login.php">Zur Anmeldung</a>
</div>
<form method="post" style="margin-top:1rem"><input type="hidden" name="csrf_token" value="<?=e($csrf)?>"><input type="hidden" name="setup_action" value="finish_activation"><input type="hidden" name="license_server_url" value="<?=e($licenseServerUrl)?>"><button class="button" type="submit">Ein-Schritt-Einrichtung abschließen / erneut prüfen</button></form>
<p><strong>Sicherheitsregel:</strong> Bestehende Datenbanken werden vom Datenbank-Assistenten nicht ungefragt überschrieben; registrierte Migrationen werden anhand ihrer Checksums mit <code>SKIP … Checksum OK</code> erkannt.</p>
</section>
<?php else:?>
<form method="post">
<input type="hidden" name="csrf_token" value="<?=e($csrf)?>">
<input type="hidden" name="setup_action" value="install">
<input type="hidden" name="license_server_url" value="<?=e($licenseServerUrl)?>">
<?php if($projectDbConfirmationRequired):?>
<input type="hidden" name="project_db_confirmation_token" value="<?=e($projectDbConfirmationToken)?>">
<?php endif;?>
<section class="card"><h2>2. Administrations- und Projektspeicher</h2>
<p>Administrationsspeicher und Projektdatenspeicher werden unabhängig gewählt. In dieser PHP-Laufzeit verfügbar: <?=e(implode(', ', array_values($driverOptions)))?>. Datenbanktypen mit fehlendem PDO-Treiber werden nicht angeboten.</p>
<h3>Administrationsspeicher</h3>
<div class="form-grid">
<label>Treiber<select name="admin_driver" id="admin_driver">
<?php foreach($driverOptions as $driver=>$label):?><option value="<?=e($driver)?>" <?=$form['admin_driver']===$driver?'selected':''?>><?=e($label)?></option><?php endforeach;?>
</select></label>
<label>Administrationsdatenbank<input name="db_database" value="<?=e($form['db_database'])?>" required pattern="[A-Za-z][A-Za-z0-9_]{1,62}"></label>
<label data-admin-pgsql>PostgreSQL-Schema<input name="admin_db_schema" value="<?=e($form['admin_db_schema'])?>" required pattern="[A-Za-z][A-Za-z0-9_]{0,62}"></label>
<label data-admin-csv>CSV-Basisordner<input name="admin_csv_base" value="<?=e($form['admin_csv_base'])?>" placeholder="storage/admin-csv"></label>
<label data-admin-sqlite>SQLite-Basisordner<input name="admin_sqlite_base" value="<?=e($form['admin_sqlite_base'])?>" placeholder="storage/admin-sqlite"></label>
</div>
<div class="form-grid" data-admin-sql>
<label>Admin-DB Host<input name="admin_db_host" value="<?=e($form['admin_db_host'])?>"></label>
<label>Admin-DB Port<input name="admin_db_port" type="number" min="1" max="65535" value="<?=e($form['admin_db_port'])?>"></label>
<label data-admin-pgsql>PostgreSQL-Wartungsdatenbank<input name="admin_db_maintenance_database" value="<?=e($form['admin_db_maintenance_database'])?>" placeholder="postgres"></label>
<label data-admin-oracle>Oracle Service/PDB<input name="admin_oracle_service" value="<?=e($form['admin_oracle_service'])?>" placeholder="XEPDB1"></label>
<label>Admin-DB Benutzer<input name="admin_db_username" value="<?=e($form['admin_db_username'])?>"></label>
<label>Admin-DB Kennwort<input name="admin_db_password" type="password" value="<?=e($form['admin_db_password'])?>" autocomplete="new-password" data-password-field></label>
</div>
<p data-admin-csv><small>CSV speichert Benutzer, Rollen, Projekte, Audit-Protokoll, Lizenzen, Modulkonfiguration und Migrationsstatus persistent unter <code>Basisordner/Name</code>.</small></p>
<p data-admin-sqlite><small>SQLite speichert die vollständige Enterprise-Administration in <code>Basisordner/Name.sqlite</code>. Benötigt wird <code>pdo_sqlite</code>.</small></p>
<p data-admin-pgsql><small>PostgreSQL verwendet immer die Kombination <strong>Datenbank + Schema</strong>. Die Datenbank und das angegebene Schema werden bei ausreichenden Rechten automatisch angelegt und anschließend verifiziert. Benötigt wird <code>pdo_pgsql</code>; Standardport ist 5432.</small></p>
<p data-admin-mssql><small>Microsoft SQL Server benötigt <code>pdo_sqlsrv</code>; Standardport ist 1433. Verschlüsselte Verbindungen sind die Voreinstellung.</small></p>
<p data-admin-oracle><small>Oracle XE verwendet ein vorhandenes Schema/User im PDB (Standardservice <code>XEPDB1</code>). Benötigt wird <code>pdo_oci</code>; Standardport ist 1521.</small></p>

<h3>Projektdatenspeicher</h3>
<div class="form-grid">
<label>Projektname<input name="project_name" value="<?=e($form['project_name'])?>" required placeholder="Demo"><small>Dieses Projekt wird nach erfolgreicher Installation sofort in der Projektliste registriert.</small></label>
<label>Treiber<select name="project_driver" id="project_driver">
<?php foreach($driverOptions as $driver=>$label):?><option value="<?=e($driver)?>" <?=$form['project_driver']===$driver?'selected':''?>><?=e($label)?></option><?php endforeach;?>
</select></label>
<label>Projekt-Datenbank-/Speichername<input name="project_database" value="<?=e($form['project_database'])?>" required pattern="[A-Za-z][A-Za-z0-9_]{1,62}"><small>Existiert dieser SQL-Projektspeicher bereits, hält der Installer vor Änderungen an und fragt ausdrücklich: vorhandene Datenbank verwenden oder löschen und neu anlegen.</small></label>
<label data-project-pgsql>PostgreSQL-Schema<input name="project_db_schema" value="<?=e($form['project_db_schema'])?>" required pattern="[A-Za-z][A-Za-z0-9_]{0,62}"></label>
</div>
<div class="form-grid" data-project-sql>
<label>Projekt-DB Host<input name="project_db_host" value="<?=e($form['project_db_host'])?>"></label>
<label>Projekt-DB Port<input name="project_db_port" type="number" min="1" max="65535" value="<?=e($form['project_db_port'])?>"></label>
<label data-project-pgsql>PostgreSQL-Wartungsdatenbank<input name="project_db_maintenance_database" value="<?=e($form['project_db_maintenance_database'])?>" placeholder="postgres"></label>
<label data-project-oracle>Oracle Service/PDB<input name="project_oracle_service" value="<?=e($form['project_oracle_service'])?>" placeholder="XEPDB1"></label>
<label>Projekt-DB Benutzer<input name="project_db_username" value="<?=e($form['project_db_username'])?>"></label>
<label>Projekt-DB Kennwort<input name="project_db_password" type="password" value="<?=e($form['project_db_password'])?>" autocomplete="new-password" data-password-field></label>
</div>
<p data-project-csv><small>CSV-Daten werden automatisch im eindeutigen Projektordner unter <code>storage/projects/&lt;storage_key&gt;/csv/</code> gespeichert. Tabellen verwenden <code>|</code> und die Pflicht-ID <code>id</code>.</small></p>
<p data-project-sqlite><small>Die SQLite-Datei wird automatisch im eindeutigen Projektordner unter <code>storage/projects/&lt;storage_key&gt;/sqlite/database.sqlite</code> angelegt.</small></p>
<p data-project-mssql><small>DataForms, Bindings, Tabellen und Datensätze können vollständig in Microsoft SQL Server liegen.</small></p>
<p data-project-pgsql><small>DataForms, Bindings, Tabellen und Datensätze liegen vollständig in der konfigurierten PostgreSQL-Kombination <strong>Datenbank + Schema</strong>. Datenbank und Schema werden bei ausreichenden Rechten automatisch angelegt und verifiziert.</small></p>
<p data-project-oracle><small>DataForms, Bindings, Tabellen und Datensätze liegen vollständig im gewählten Oracle-XE-Schema/User.</small></p>
<div class="notice"><strong>Treiberregel:</strong> Ein SQL-Datenbanktyp wird nur angeboten, wenn der zugehörige PDO-Treiber in der aktuell laufenden PHP-Version registriert ist. CSV bleibt ohne PDO verfügbar.</div>
</section>
<section class="card"><h2>3. Erster Superadministrator</h2>
<div class="form-grid">
<label>Benutzername<input name="admin_username" value="<?=e($form['admin_username'])?>" required></label>
<label>E-Mail<input name="admin_email" type="email" value="<?=e($form['admin_email'])?>"></label>
<label>Kennwort<input name="admin_password" type="password" value="<?=e($form['admin_password'])?>" required data-password-field minlength="10" autocomplete="new-password"></label>
<label>Kennwort wiederholen<input name="admin_password_confirm" type="password" value="<?=e($form['admin_password_confirm'])?>" required data-password-field minlength="10" autocomplete="new-password"></label>
</div></section>

<section class="card"><h2>4. Produkte</h2>
<label><input type="checkbox" name="products[]" value="dataform" <?=in_array('dataform',$form['products'],true)?'checked':''?>> DataForm</label><br>
<label><input type="checkbox" name="products[]" value="dialog" <?=in_array('dialog',$form['products'],true)?'checked':''?>> Dialog</label><br>
<label><input type="checkbox" name="products[]" value="nachhilfe" <?=in_array('nachhilfe',$form['products'],true)?'checked':''?>> Nachhilfe</label><br>
<label><input type="checkbox" name="products[]" value="calendar" <?=in_array('calendar',$form['products'],true)?'checked':''?>> easyIT Calendar</label><br>
<label><input type="checkbox" name="products[]" value="csv-engine" <?=in_array('csv-engine',$form['products'],true)?'checked':''?>> CSV-Engine</label><br>
<?php if(isset($driverOptions['sqlite'])):?><label><input type="checkbox" name="products[]" value="sqlite-engine" <?=in_array('sqlite-engine',$form['products'],true)?'checked':''?>> SQLite-Engine</label><br><?php endif;?>
<?php if(isset($driverOptions['pgsql'])):?><label><input type="checkbox" name="products[]" value="pgsql-engine" <?=in_array('pgsql-engine',$form['products'],true)?'checked':''?>> PostgreSQL-Engine</label><br><?php endif;?>
<?php if(isset($driverOptions['oracle'])):?><label><input type="checkbox" name="products[]" value="oracle-engine" <?=in_array('oracle-engine',$form['products'],true)?'checked':''?>> Oracle-XE-Engine</label><br><?php endif;?>
<?php if(isset($driverOptions['mssql'])):?><label><input type="checkbox" name="products[]" value="mssql-engine" <?=in_array('mssql-engine',$form['products'],true)?'checked':''?>> Microsoft-SQL-Server-Engine</label><?php endif;?>
</section>

<section class="card"><h2>5. Umgebung & Abschluss</h2>
<div class="form-grid">
<label>Umgebung<select name="environment"><option value="production" <?=$form['environment']==='production'?'selected':''?>>production</option><option value="development" <?=$form['environment']==='development'?'selected':''?>>development</option></select></label>
<label>Zeitzone<input name="timezone" value="<?=e($form['timezone'])?>"></label>
</div>
<p>Nach READY des Lizenzservers werden mit diesem Abschluss Datenbanken und Superadministrator eingerichtet, der lokale Lizenzserver kryptografisch gekoppelt, die Installation automatisch aktiviert und die Superadmin-Sitzung geöffnet.</p>
<?php if(($licenseServerState['ready']??false)===true):?>
<?php if($projectDbConfirmationRequired):?>
<div class="notice error setup-db-decision">
<strong>Vorhandene Projektdatenbank erkannt: <?=e($projectDbExistingName)?></strong><br>
Die Installation wurde vor jeder Änderung angehalten. Sie können die vorhandene Demo-/Projektdatenbank unverändert weiterverwenden oder sie nach einer zusätzlichen Sicherheitsabfrage vollständig löschen und neu aufbauen.
</div>
<div class="actions setup-install-actions">
<button class="button setup-install-submit" data-button-skip type="submit" name="project_existing_action" value="reuse">Vorhandene Demo-DB verwenden</button>
<button class="button secondary setup-install-submit setup-danger-submit" data-button-skip type="submit" name="project_existing_action" value="recreate" data-project-db-name="<?=e($projectDbExistingName)?>">Demo-DB löschen und neu anlegen</button>
</div>
<?php else:?>
<div class="actions setup-install-actions">
<button class="button setup-install-submit" data-button-skip type="submit">Installation vollständig abschließen</button>
</div>
<?php endif;?>
<?php else:?>
<div class="actions setup-install-actions">
<button class="button secondary setup-install-submit" data-button-skip type="button" disabled>Abschluss gesperrt – Lizenzserver meldet noch SETUP_REQUIRED</button>
</div>
<?php endif;?>
</section>
</form>
<style>
/* HF157: Der Installationsabschluss ist eine kritische Workflow-Aktion und bleibt
   bewusst als beschrifteter Textbutton sichtbar. Er darf nicht vom globalen
   Bildbutton-Adapter in ein reines Symbol umgewandelt werden. */
.setup-install-actions{margin-top:1rem}
button.setup-install-submit{display:inline-flex;align-items:center;justify-content:center;min-width:280px;min-height:46px;padding:.75rem 1.1rem;font-size:1rem;line-height:1.2;color:#fff;background:var(--brand);border:1px solid var(--brand);border-radius:8px;font-weight:700;cursor:pointer}
button.setup-install-submit.secondary,button.setup-install-submit:disabled{color:var(--brand);background:#fff;border-color:var(--line)}
button.setup-install-submit:disabled{opacity:.7;cursor:not-allowed}
button.setup-danger-submit{color:#8b1e1e!important;border-color:#b53a3a!important;background:#fff5f5!important}
</style>
<script>
(function(){
 var admin=document.getElementById('admin_driver'), project=document.getElementById('project_driver');
 function isSql(v){return v==='mysql'||v==='pgsql'||v==='oracle'||v==='mssql';}
 function syncStores(){
  var av=admin?admin.value:'mysql', pv=project?project.value:'mysql';
  document.querySelectorAll('[data-admin-csv]').forEach(function(el){el.style.display=av==='csv'?'':'none';});
  document.querySelectorAll('[data-project-csv]').forEach(function(el){el.style.display=pv==='csv'?'':'none';});
  document.querySelectorAll('[data-admin-sqlite]').forEach(function(el){el.style.display=av==='sqlite'?'':'none';});
  document.querySelectorAll('[data-project-sqlite]').forEach(function(el){el.style.display=pv==='sqlite'?'':'none';});
  document.querySelectorAll('[data-admin-sql]').forEach(function(el){el.style.display=isSql(av)?'':'none';});
  document.querySelectorAll('[data-project-sql]').forEach(function(el){el.style.display=isSql(pv)?'':'none';});
  document.querySelectorAll('[data-admin-pgsql]').forEach(function(el){el.style.display=av==='pgsql'?'':'none';});
  document.querySelectorAll('[data-admin-oracle]').forEach(function(el){el.style.display=av==='oracle'?'':'none';});
  document.querySelectorAll('[data-admin-mssql]').forEach(function(el){el.style.display=av==='mssql'?'':'none';});
  document.querySelectorAll('[data-project-pgsql]').forEach(function(el){el.style.display=pv==='pgsql'?'':'none';});
  document.querySelectorAll('[data-project-oracle]').forEach(function(el){el.style.display=pv==='oracle'?'':'none';});
  document.querySelectorAll('[data-project-mssql]').forEach(function(el){el.style.display=pv==='mssql'?'':'none';});
  var ap=document.querySelector('input[name=admin_db_port]'), pp=document.querySelector('input[name=project_db_port]');
  if(ap){if(av==='pgsql')ap.value='5432';else if(av==='oracle')ap.value='1521';else if(av==='mssql')ap.value='1433';else if(av==='mysql')ap.value='3306';}
  if(pp){if(pv==='pgsql')pp.value='5432';else if(pv==='oracle')pp.value='1521';else if(pv==='mssql')pp.value='1433';else if(pv==='mysql')pp.value='3306';}
 }
 if(admin)admin.addEventListener('change',syncStores);if(project)project.addEventListener('change',syncStores);syncStores();
})();;
document.querySelectorAll('button.setup-danger-submit').forEach(function(button){
 button.addEventListener('click',function(event){
  var name=button.getAttribute('data-project-db-name')||'die vorhandene Projektdatenbank';
  var ok=window.confirm('SICHERHEITSABFRAGE\n\nDie Datenbank „'+name+'“ wird vollständig gelöscht. Alle darin vorhandenen Daten gehen unwiderruflich verloren.\n\nSoll die Datenbank wirklich gelöscht und neu angelegt werden?');
  if(!ok)event.preventDefault();
 });
});

document.querySelectorAll('input[data-password-field]').forEach(function(input){
 var b=document.createElement('button'); b.type='button'; b.className='password-toggle';
 b.setAttribute('data-button','anzeigen'); b.setAttribute('data-button-context','password_show');
 input.insertAdjacentElement('afterend',b);
 b.addEventListener('click',function(){
  var show=input.type==='password'; input.type=show?'text':'password';
  b.setAttribute('data-button-context',show?'password_hide':'password_show');
  if(window.EasyITButtons && typeof window.EasyITButtons.decorate==='function') window.EasyITButtons.decorate(b);
 });
});
</script>
<style>.password-toggle{margin-left:.35rem;border:1px solid #ccd5e2;border-radius:.4rem;padding:.35rem .55rem;cursor:pointer}</style>
<?php endif;?>
<?php
$content=ob_get_clean();
render_page(['title'=>'Installer 2.0','active'=>'setup','content'=>$content,'help'=>[
'title'=>'Installer 2.0','location'=>'Setup → Installer',
'short'=>'Installiert easyIT Enterprise über einen konsolidierten Ablauf.',
'goal'=>'Einen reproduzierbaren und überprüften Ausgangszustand herstellen.',
'next'=>'Systemprüfung kontrollieren und anschließend Datenbank- und Adminkonto eingeben.',
'steps'=>['Lizenzserver-URL eingeben, Verbindung prüfen und übernehmen.','Lizenzserver auf seiner Ein-Seiten-Einrichtung bis READY abschließen.','Systemprüfung prüfen.','Administrationsspeicher und Projektdatenspeicher unabhängig konfigurieren.','Superadmin-Daten einmalig eingeben.','Produkte auswählen.','Installation, Vertrauensaufbau, Bootstrap-Aktivierung und Anmeldung gemeinsam abschließen.'],
'tips'=>['Das Superadmin-Kennwort ist nicht das Datenbankkennwort.','Ein bestehender Install-Lock verhindert versehentliche Neuinstallation.','Das Kennwort wird nur als Passwort-Hash gespeichert.']
]]);
