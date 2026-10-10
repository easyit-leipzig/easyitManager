<?php
declare(strict_types=1);

$base=dirname(__DIR__);
$config=require $base.'/bootstrap.php';

use EasyIT\LicenseServer\Setup\DataSourceSetup;
use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Admin\AdminAuth;

if(session_status()!==PHP_SESSION_ACTIVE){
    session_name('EASYITLICSETUP');
    $secure=(!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')||((string)($_SERVER['SERVER_PORT']??'')==='443');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
}
if(empty($_SESSION['easyit_setup_csrf']))$_SESSION['easyit_setup_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['easyit_setup_csrf'];

function h(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function baseUrl(): string{$script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/setup/index.php'));$dir=rtrim(dirname(dirname($script)),'/');return $dir==='.'?'':$dir;}
function isLoopbackRequest(): bool{return in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);}
function setupMessage(string $code): string{
    return match($code){
        'DATASOURCE_DRIVER_NOT_AVAILABLE'=>'Der gewählte PDO-Treiber ist auf diesem Server nicht verfügbar.',
        'DATASOURCE_HOST_REQUIRED'=>'Host fehlt.',
        'DATASOURCE_PORT_INVALID'=>'Port ist ungültig.',
        'DATASOURCE_DATABASE_INVALID'=>'Datenbankname ist ungültig.',
        'DATASOURCE_SCHEMA_INVALID'=>'Schema ist ungültig.',
        'DATASOURCE_USER_REQUIRED'=>'Datenbankbenutzer fehlt.',
        'DATASOURCE_SQLITE_PATH_REQUIRED'=>'SQLite-Datei fehlt.',
        'DATASOURCE_SQLITE_PATH_INVALID'=>'SQLite-Dateipfad ist ungültig.',
        'DATASOURCE_SQLITE_DIRECTORY_NOT_WRITABLE'=>'Der SQLite-Zielordner ist nicht beschreibbar.',
        'DATASOURCE_CONFIG_DIRECTORY_NOT_WRITABLE'=>'Der Konfigurationsordner ist nicht beschreibbar.',
        'DATASOURCE_CONFIG_WRITE_FAILED'=>'Die Datenquellenkonfiguration konnte nicht gespeichert werden.',
        'SODIUM_EXTENSION_REQUIRED'=>'Die PHP-Erweiterung sodium fehlt. Ohne sodium kann der Lizenzserver nicht eingerichtet werden.',
        'EXISTING_DATABASE_ACTION_REQUIRED'=>'Die Zieldatenbank existiert bereits. Wählen Sie ausdrücklich, ob sie beibehalten oder gelöscht und vollständig neu aufgebaut werden soll.',
        'REBUILD_CONFIRMATION_REQUIRED'=>'Der vollständige Neuaufbau muss zusätzlich bestätigt werden.',
        'ADMIN_USERNAME_INVALID'=>'Der Superadmin-Benutzername ist ungültig (mindestens 3 Zeichen; erlaubt: Buchstaben, Ziffern, Punkt, Unterstrich, @ und -).',
        'ADMIN_PASSWORD_TOO_SHORT'=>'Das Superadmin-Kennwort muss mindestens 10 Zeichen lang sein.',
        'ADMIN_PASSWORD_WEAK'=>'Das Superadmin-Kennwort muss Groß- und Kleinbuchstaben sowie mindestens eine Ziffer enthalten.',
        'ADMIN_PASSWORD_MISMATCH'=>'Die beiden Superadmin-Kennwörter stimmen nicht überein.',
        'ADMIN_SETUP_REQUIRED'=>'Für eine neue bzw. neu aufgebaute Datenbank müssen Superadmin-Benutzername und Kennwort angegeben werden.',
        default=>str_starts_with($code,'DATABASE_BOOTSTRAP_FAILED:')
            ? 'Die gewählte Datenbank existiert nicht und konnte nicht angelegt werden. Der Datenbankbenutzer benötigt CREATE DATABASE bzw. CREATEDB. Details: '.trim(substr($code,strlen('DATABASE_BOOTSTRAP_FAILED:')))
            : (str_starts_with($code,'SCHEMA_BOOTSTRAP_FAILED:')
                ? 'Das konfigurierte PostgreSQL-Schema existiert nicht und konnte nicht angelegt werden. Der Datenbankbenutzer benötigt CREATE-Rechte. Details: '.trim(substr($code,strlen('SCHEMA_BOOTSTRAP_FAILED:')))
                : $code),
    };
}
function validateAdminInput(array $input,bool $required): void{
    $username=trim((string)($input['admin_username']??''));
    $pw=(string)($input['admin_password']??'');$pw2=(string)($input['admin_password_confirm']??'');
    if(!$required && $username==='' && $pw==='' && $pw2==='')return;
    if($username===''||$pw==='')throw new RuntimeException('ADMIN_SETUP_REQUIRED');
    if(!preg_match('/^[A-Za-z0-9._@-]{3,120}$/',$username))throw new RuntimeException('ADMIN_USERNAME_INVALID');
    if(strlen($pw)<10)throw new RuntimeException('ADMIN_PASSWORD_TOO_SHORT');
    if(!preg_match('/[A-Z]/',$pw)||!preg_match('/[a-z]/',$pw)||!preg_match('/[0-9]/',$pw))throw new RuntimeException('ADMIN_PASSWORD_WEAK');
    if($pw!==$pw2)throw new RuntimeException('ADMIN_PASSWORD_MISMATCH');
}

$drivers=DataSourceSetup::availableDrivers();
$values=DataSourceSetup::defaults($base);
$currentDb=(array)($config['database']??[]);
foreach(['driver','host','port','database','schema','user','path'] as $k){
    if(array_key_exists($k,$currentDb)&&$currentDb[$k]!==''&&$currentDb[$k]!==null){
        if($k==='path')$values['sqlite_path']=$currentDb[$k]; else $values[$k]=$currentDb[$k];
    }
}
$error='';$success='';$testResult=null;$status=[];
$remote=(string)($_SERVER['REMOTE_ADDR']??'');$isLoopback=isLoopbackRequest();
$alreadyConfigured=easyit_license_database_configured($config);
$locked=DataSourceSetup::isLocked($base);
$targetExists=null;

// Aktuellen Status nur lesen; Fehler werden als SETUP_REQUIRED dargestellt.
if($alreadyConfigured){
    try{
        $pdo=Connection::create((array)$config['database']);
        $audit=new AuditService($pdo);$auth=new AdminAuth($pdo,$config,$audit);
        $status=['database'=>true,'admin'=>$auth->hasAdmins(),'lock'=>$locked];
    }catch(Throwable){$status=['database'=>false,'admin'=>false,'lock'=>$locked];}
}else{$status=['database'=>false,'admin'=>false,'lock'=>$locked];}
$status['ready']=$status['database']&&$status['admin']&&$status['lock'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    $values=array_merge($values,$_POST);
    $action=(string)($_POST['action']??'test');
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))){$error='Ungültige Setup-Sitzung. Bitte Seite neu laden.';}
    elseif(!$isLoopback && $locked){$error='Die Ersteinrichtung ist abgeschlossen. Ein Neuaufbau ist aus Sicherheitsgründen nur lokal am Lizenzserver zulässig.';}
    else{
        try{
            $db=DataSourceSetup::fromInput($_POST,$base);
            $probe=DataSourceSetup::test($db);$targetExists=(bool)($probe['target_exists']??false);
            if($action==='test'){
                $testResult=$probe;
                $success=$targetExists
                    ? 'Verbindung erfolgreich. Die Zieldatenbank existiert bereits. Vor einer Initialisierung muss unten ausdrücklich „beibehalten“ oder „löschen und neu aufbauen“ gewählt werden.'
                    : 'Verbindung erfolgreich. Die Zieldatenbank existiert noch nicht und kann in einem Vorgang angelegt und eingerichtet werden.';
            }elseif($action==='install'){
                $existingAction=(string)($_POST['existing_database_action']??'');
                if($targetExists&&!in_array($existingAction,['keep','rebuild'],true))throw new RuntimeException('EXISTING_DATABASE_ACTION_REQUIRED');
                $rebuild=$targetExists&&$existingAction==='rebuild';
                if($rebuild && (string)($_POST['confirm_rebuild']??'')!=='yes')throw new RuntimeException('REBUILD_CONFIRMATION_REQUIRED');

                // Vor dem destruktiven Schritt Superadmin-Daten prüfen.
                $adminWillBeRequired=!$targetExists||$rebuild;
                validateAdminInput($_POST,$adminWillBeRequired);

                $summary=DataSourceSetup::install($db,$config,$base,$rebuild);
                $effective=$config;$effective['database']=array_merge((array)($effective['database']??[]),$db,['configured'=>true]);
                $pdo=Connection::create($db);$audit=new AuditService($pdo);$auth=new AdminAuth($pdo,$effective,$audit);
                $adminCreated=false;
                if(!$auth->hasAdmins()){
                    validateAdminInput($_POST,true);
                    if(!$isLoopback)throw new RuntimeException('Die integrierte Superadmin-Ersteinrichtung ist nur lokal zulässig.');
                    $auth->setupTrustedLocal((string)$_POST['admin_username'],(string)$_POST['admin_password'],(string)($_POST['admin_display_name']??''));
                    $adminCreated=true;
                }
                $locked=DataSourceSetup::isLocked($base);$alreadyConfigured=true;
                $status=['database'=>true,'admin'=>$auth->hasAdmins(),'lock'=>$locked];$status['ready']=$status['database']&&$status['admin']&&$status['lock'];
                $schema=(array)($summary['schema']??[]);
                if($rebuild){
                    $success='Lizenzserver in einem Vorgang vollständig neu aufgebaut. Vorhandene Tabellen wurden gelöscht, das Schema neu erstellt und der Superadministrator eingerichtet.';
                }elseif($targetExists){
                    $success='Vorhandene Datenbank beibehalten und nicht-destruktiv geprüft/ergänzt. '.($adminCreated?'Der Superadministrator wurde eingerichtet.':'Der vorhandene Superadministrator wurde beibehalten.');
                }else{
                    $success='Lizenzserver in einem Vorgang eingerichtet: Datenbank angelegt, Schema provisioniert und Superadministrator erstellt.';
                }
                $testResult=['summary'=>$summary,'target_exists'=>true,'schema'=>$schema];
            }
        }catch(Throwable $e){$error=setupMessage($e->getMessage());if($error===$e->getMessage()&&$e->getPrevious())$error.=' · '.$e->getPrevious()->getMessage();}
    }
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; base-uri 'self'; frame-ancestors 'none'");
$version=is_file($base.'/VERSION')?trim((string)file_get_contents($base.'/VERSION')):'HF137';
$baseUrl=baseUrl();
?>
<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>easyIT Lizenzserver – Einrichtung</title>
<style>
:root{font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:#17233a;background:#eef3f9}*{box-sizing:border-box}body{margin:0}.wrap{max-width:1100px;margin:32px auto;padding:0 22px}.card{background:#fff;border:1px solid #b9c9de;border-radius:16px;padding:26px;box-shadow:0 10px 30px #1b35550d}h1{margin:.1rem 0 .5rem;font-size:2rem}h2{margin:1.4rem 0 .7rem;font-size:1.25rem}.muted{color:#5e6d83}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.full{grid-column:1/-1}label{display:block;font-weight:700}input,select{width:100%;margin-top:7px;padding:12px 13px;border:1px solid #b8c5d8;border-radius:9px;font:inherit;background:#fff}.choices{display:grid;gap:9px;margin-top:10px}.choices label,.confirm{display:flex;align-items:flex-start;gap:9px;font-weight:600}.choices input,.confirm input{width:auto;margin:3px 0 0}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:20px}button,a.btn{border:0;border-radius:9px;padding:12px 16px;font-weight:750;text-decoration:none;cursor:pointer}.primary{background:#1769c2;color:#fff}.secondary{background:#e8eef7;color:#17345d}.danger{background:#a72b21;color:#fff}.ok,.err,.warn,.status{border-radius:10px;padding:13px 15px;margin:15px 0}.ok{background:#e9f7ef;border:1px solid #7ac39a}.err{background:#fff0ef;border:1px solid #de8a82}.warn{background:#fff8df;border:1px solid #dfc46f}.status{background:#f5f8fc;border:1px solid #c9d5e6}.status-grid{display:grid;grid-template-columns:1fr auto;gap:7px 16px}.pass{color:#06752a;font-weight:800}.fail{color:#a42920;font-weight:800}code{background:#eef2f6;padding:.14rem .35rem;border-radius:4px}.hidden{display:none}@media(max-width:760px){.grid{grid-template-columns:1fr}.full{grid-column:auto}}
</style></head><body><div class="wrap"><div class="card">
<p class="muted">easyIT License Server · <?=h($version)?></p><h1>Lizenzserver vollständig einrichten</h1>
<p>Auf dieser Seite werden Datenquelle, Datenbankzustand und erster Superadministrator gemeinsam eingerichtet. Es ist kein Wechsel in eine zweite Einrichtungsseite erforderlich.</p>
<div class="status"><strong>Aktueller Status</strong><div class="status-grid"><span>Datenbank erreichbar</span><span class="<?=$status['database']?'pass':'fail'?>"><?=$status['database']?'OK':'SETUP_REQUIRED'?></span><span>Superadministrator vorhanden</span><span class="<?=$status['admin']?'pass':'fail'?>"><?=$status['admin']?'OK':'FEHLT'?></span><span>Setup abgeschlossen</span><span class="<?=$status['ready']?'pass':'fail'?>"><?=$status['ready']?'READY':'SETUP_REQUIRED'?></span></div></div>
<?php if($error!==''):?><div class="err"><strong>Einrichtung nicht möglich:</strong> <?=h($error)?></div><?php endif;?>
<?php if($success!==''):?><div class="ok"><strong>Erfolgreich:</strong> <?=h($success)?></div><?php endif;?>
<?php if(!$drivers):?><div class="err"><strong>Kein unterstützter PDO-Treiber verfügbar.</strong> Aktivieren Sie mindestens <code>pdo_mysql</code>, <code>pdo_pgsql</code> oder <code>pdo_sqlite</code>.</div>
<?php else:?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=h($csrf)?>">
<h2>1. Zentrale Datenquelle</h2><div class="grid">
<label class="full">Datenquelle<select name="driver" id="driver" required><?php foreach($drivers as $code=>$meta):?><option value="<?=h($code)?>" <?=$values['driver']===$code?'selected':''?>><?=h($meta['label'])?></option><?php endforeach;?></select></label>
<div data-sql><label>Host<input name="host" value="<?=h($values['host'])?>"></label></div><div data-sql><label>Port<input name="port" type="number" min="1" max="65535" value="<?=h($values['port'])?>"></label></div>
<div data-sql><label>Datenbank<input name="database" value="<?=h($values['database'])?>"></label></div><div data-pg><label>Schema<input name="schema" value="<?=h($values['schema'])?>"></label></div>
<div data-sql><label>Benutzer<input name="user" value="<?=h($values['user'])?>" autocomplete="username"></label></div><div data-sql><label>Kennwort<input name="password" type="password" value="<?=h($values['password'])?>" autocomplete="new-password"></label></div>
<div data-sqlite class="full"><label>SQLite-Datei<input name="sqlite_path" value="<?=h($values['sqlite_path'])?>"></label></div></div>

<h2>2. Vorhandene Ziel-/Demo-Datenbank</h2>
<div class="warn"><strong>Keine automatische Löschung mehr:</strong> Existiert die gewählte Datenbank bereits, muss ausdrücklich entschieden werden, was damit geschieht.</div>
<div class="choices">
<label><input type="radio" name="existing_database_action" value="keep" <?=($values['existing_database_action']??'')==='keep'?'checked':''?>> Vorhandene Datenbank beibehalten; vorhandene Daten nicht löschen und nur fehlende Strukturen ergänzen.</label>
<label><input type="radio" name="existing_database_action" value="rebuild" <?=($values['existing_database_action']??'')==='rebuild'?'checked':''?>> Vorhandene Datenbank löschen bzw. alle Tabellen entfernen und vollständig neu aufbauen.</label>
<label class="confirm"><input type="checkbox" name="confirm_rebuild" value="yes" <?=($values['confirm_rebuild']??'')==='yes'?'checked':''?>> Ich bestätige, dass beim gewählten Neuaufbau alle vorhandenen Daten in der Ziel-Datenbank verloren gehen dürfen.</label>
</div>

<h2>3. Superadministrator</h2><div class="grid">
<label>Benutzername<input name="admin_username" value="<?=h($values['admin_username']??'superadmin')?>" autocomplete="username"></label>
<label>Anzeigename<input name="admin_display_name" value="<?=h($values['admin_display_name']??'Superadministrator')?>"></label>
<label>Kennwort<input type="password" name="admin_password" minlength="10" autocomplete="new-password"></label>
<label>Kennwort wiederholen<input type="password" name="admin_password_confirm" minlength="10" autocomplete="new-password"></label>
<div class="full muted">Mindestens 10 Zeichen sowie Groß- und Kleinbuchstaben und eine Ziffer. Wird eine bestehende Datenbank beibehalten und enthält sie bereits einen Superadministrator, bleibt dieser unverändert.</div></div>

<div class="actions"><button class="secondary" name="action" value="test">Verbindung und Datenbank prüfen</button><button class="primary" name="action" value="install">Einrichtung jetzt vollständig abschließen</button><?php if($status['ready']):?><a class="btn secondary" href="<?=h($baseUrl.'/admin/')?>">Lizenzverwaltung öffnen</a><?php endif;?></div>
</form>
<?php if(is_array($testResult)):?><div class="ok"><strong>Prüfergebnis:</strong> Zieldatenbank <?=!empty($testResult['target_exists'])?'vorhanden':'nicht vorhanden'?>.<?php if(!empty($testResult['server_version'])):?> Server-Version: <?=h($testResult['server_version'])?>.<?php endif;?></div><?php endif;?>
<script>(()=>{const d=document.getElementById('driver');if(!d)return;const refresh=()=>{const v=d.value;document.querySelectorAll('[data-sql]').forEach(e=>e.classList.toggle('hidden',v==='sqlite'));document.querySelectorAll('[data-pg]').forEach(e=>e.classList.toggle('hidden',v!=='pgsql'));document.querySelectorAll('[data-sqlite]').forEach(e=>e.classList.toggle('hidden',v!=='sqlite'));const p=document.querySelector('input[name=port]');if(p&&!p.dataset.touched)p.value=v==='pgsql'?'5432':'3306'};d.addEventListener('change',refresh);document.querySelector('input[name=port]')?.addEventListener('input',e=>e.target.dataset.touched='1');refresh()})();</script>
<?php endif;?></div></div></body></html>
