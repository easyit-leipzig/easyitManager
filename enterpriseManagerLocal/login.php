<?php
declare(strict_types=1);

require __DIR__.'/system/app/bootstrap.php';
require __DIR__.'/system/ui/layout.php';
require __DIR__.'/system/licensing/Autoload.php';

use EasyIT\Enterprise\Licensing\LicenseApiClient;
require_once __DIR__.'/system/licensing/LicenseConfigStore.php';

if($current=enterprise_user()){
    header('Location: '.enterprise_landing_path($current));
    exit;
}

$error='';$errorCode='';
$success=((string)($_GET['password_changed']??'')==='1')?'Ihr Kennwort wurde geändert. Bitte melden Sie sich mit dem neuen Kennwort an.':'';
$loginIdentity=trim((string)($_POST['identity']??$_POST['email']??''));
$registerValues=[
    'register_email'=>trim((string)($_POST['register_email']??'')),
    'company'=>trim((string)($_POST['company']??'')),
    'first_name'=>trim((string)($_POST['first_name']??'')),
    'last_name'=>trim((string)($_POST['last_name']??'')),
    'phone'=>trim((string)($_POST['phone']??'')),
    'requested_domain'=>trim((string)($_POST['requested_domain']??'')),
];

$registrationCaptcha=static function(bool $renew=false):array{
    $key='enterprise_registration_captcha';
    $current=(array)($_SESSION[$key]??[]);
    if($renew || empty($current['question']) || !isset($current['answer']) || (int)($current['issued_at']??0)<time()-900){
        $a=random_int(2,9);
        $b=random_int(2,9);
        $current=['question'=>$a.' + '.$b,'answer'=>(string)($a+$b),'issued_at'=>time()];
        $_SESSION[$key]=$current;
    }
    return $current;
};
$captcha=$registrationCaptcha();

$cfg=EnterpriseLicenseConfigStore::load(__DIR__);
if(!is_array($cfg))$cfg=[];
$licenseConfigured=!empty($cfg['enabled'])&&!empty($cfg['server_url']);
$localAdminHref='admin-login.php?next='.rawurlencode('app/licensing/remote.php');

$publicError=static function(string $code):string{
    return match($code){
        'ACCOUNT_NOT_ACTIVE'=>'Das Kundenkonto ist nicht aktiv.',
        'ACCOUNT_LOCKED'=>'Das Konto ist nach mehreren Fehlversuchen vorübergehend gesperrt.',
        'ACCOUNT_LOGIN_INVALID'=>'E-Mail-Adresse oder Kennwort ist falsch.',
        'ACCOUNT_ALREADY_EXISTS'=>'Für diese E-Mail-Adresse besteht bereits ein Konto. Verwenden Sie die Anmeldung.',
        'ACCOUNT_EMAIL_INVALID'=>'Die E-Mail-Adresse ist ungültig.',
        'ACCOUNT_COMPANY_REQUIRED'=>'Der Firmenname ist erforderlich.',
        'ACCOUNT_NAME_REQUIRED'=>'Vor- und Nachname sind erforderlich.',
        'ACCOUNT_PASSWORD_MISMATCH'=>'Die Kennwörter stimmen nicht überein.',
        'ACCOUNT_PASSWORD_WEAK'=>'Das Kennwort muss mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie eine Ziffer enthalten.',
        'REGISTRATION_CAPTCHA_INVALID'=>'Die CAPTCHA-Sicherheitsfrage wurde nicht richtig beantwortet. Bitte versuchen Sie es erneut.',
        'ACCOUNT_REQUEST_RATE_LIMIT'=>'Zu viele Registrierungsversuche von dieser Adresse. Bitte später erneut versuchen.',
        'SURFACE_DOMAIN_INVALID'=>'Die angegebene Domain ist ungültig.',
        'SURFACE_DOMAIN_NOT_ALLOWED'=>'Diese Domain ist für das Kundenkonto nicht freigegeben.',
        'SURFACE_DOMAIN_NOT_CONFIGURED'=>'Für das Kundenkonto ist noch keine erlaubte Domain hinterlegt.',
        'SURFACE_DOMAIN_REQUIRED'=>'Die aktuelle Domain konnte nicht bestimmt werden.',
        'SURFACE_IP_NOT_ALLOWED'=>'Die aktuelle IP-Adresse ist für dieses Kundenkonto nicht freigegeben.',
        'LICENSE_EXPIRED','LICENSE_SUSPENDED','LICENSE_REVOKED'=>'Die bisherige Lizenz ist nicht mehr gültig. Der Enterprise-Manager wird im Demo-Modus geöffnet.',
        'SERVER_SIGNATURE_UNTRUSTED','SERVER_SIGNATURE_INVALID'=>'Die Antwort des Lizenzservers konnte nicht vertrauenswürdig bestätigt werden.',
        default=>'Die Anmeldung konnte nicht durchgeführt werden. ('.preg_replace('/[^A-Z0-9_]/','',$code).')',
    };
};

if(isset($_SESSION['enterprise_login_error_code'])){
    $errorCode=(string)$_SESSION['enterprise_login_error_code'];
    $error=$publicError($errorCode);
    unset($_SESSION['enterprise_login_error_code']);
}

$localAdminLogin=static function(string $identity,string $password):array{
    $pdo=enterprise_pdo();
    enterprise_upgrade($pdo);
    $stmt=$pdo->prepare('SELECT id,username,email,password_hash,is_active FROM users WHERE username=? LIMIT 1');
    $stmt->execute([$identity]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row || !(bool)($row['is_active']??false) || !password_verify($password,(string)($row['password_hash']??''))){
        throw new RuntimeException('ADMIN_LOGIN_INVALID');
    }
    $rolesStmt=$pdo->prepare('SELECT r.name FROM roles r INNER JOIN user_roles ur ON ur.role_id=r.id WHERE ur.user_id=? ORDER BY r.name');
    $rolesStmt->execute([(int)$row['id']]);
    $roles=array_values(array_map('strval',array_column($rolesStmt->fetchAll(PDO::FETCH_ASSOC),'name')));
    if(!in_array('admin',$roles,true) && !in_array('superadmin',$roles,true)){
        throw new RuntimeException('ADMIN_LOGIN_NOT_ALLOWED');
    }
    $user=[
        'id'=>(int)$row['id'],
        'username'=>(string)$row['username'],
        'email'=>(string)($row['email']??''),
        'roles'=>$roles,
        'permissions'=>enterprise_permissions($pdo,(int)$row['id']),
    ];
    session_regenerate_id(true);
    $_SESSION['enterprise_user']=$user;
    enterprise_audit($pdo,(int)$row['id'],'auth.login','user',(string)$row['id']);
    enterprise_event_dispatch('auth.user.logged_in',['user_id'=>(int)$row['id'],'username'=>(string)$row['username']],['source'=>'login.public.local_admin']);
    return $user;
};

$acceptPayload=static function(array $payload,string $email):array{
    $account=(array)($payload['account']??[]);
    $mode=(string)($payload['manager_mode']??(empty($payload['license'])?'demo':'licensed'));
    if(!in_array($mode,['demo','licensed'],true))throw new RuntimeException('SERVER_RESPONSE_INVALID');
    $license=(array)($payload['license']??[]);
    $policy=(array)($payload['license_policy']??[]);
    $rolePolicy=(array)($payload['role_policy']??[]);
    $surfacePolicy=(array)($payload['surface_policy']??['allowed'=>true,'error_code'=>null]);
    $surfaceAllowed=!array_key_exists('allowed',$surfacePolicy) || !empty($surfacePolicy['allowed']);
    $modules=array_values(array_map('strval',(array)($payload['modules']??[])));
    $token=(string)($payload['account_session_token']??'');
    if(empty($account['customer_user_id'])||$token==='')throw new RuntimeException('SERVER_RESPONSE_INVALID');
    if($mode==='licensed'&&empty($license['id']))throw new RuntimeException('SERVER_RESPONSE_INVALID');
    if($mode==='demo'){$license=[];$modules=[];$policy=array_replace(['mode'=>'demo','capability_policy'=>'demo','capabilities'=>[]],$policy);}
    $permissions=($mode==='demo'||!$surfaceAllowed)?[]:enterprise_customer_permissions($modules,$policy,$rolePolicy);
    session_regenerate_id(true);
    $_SESSION['enterprise_user']=[
        'id'=>(string)$account['customer_user_id'],
        'username'=>(string)($account['display_name']??$email),
        'email'=>$email,
        'roles'=>['customer'],
        'permissions'=>array_values(array_unique($permissions)),
        'customer'=>true,
        'customer_id'=>(string)($account['customer_id']??''),
        'customer_key'=>(string)($payload['customer_key']??$account['customer_key']??$email),
        'customer_number'=>(string)($account['customer_number']??''),
        'customer_name'=>(string)($account['customer_name']??''),
        'manager_mode'=>$mode,
        'license'=>$license,
        'modules'=>$modules,
        'license_policy'=>$policy,
        'role_policy'=>$rolePolicy,
        'surface_policy'=>$surfacePolicy,
        'surface_allowed'=>$surfaceAllowed,
        'authenticated_at'=>time(),
    ];
    $_SESSION['enterprise_customer_license_payload']=$payload;
    if($mode==='licensed' && !empty($license['id'])){
        $_SESSION['enterprise_license_transition_notice']=$surfaceAllowed
            ? 'Die vergebene Lizenz wurde erkannt und für diese Installation aktiviert.'
            : 'Die vergebene Lizenz wurde erkannt und für diese Installation aktiviert. Die aktuelle Domain ist jedoch noch nicht für den produktiven Zugriff freigegeben.';
    }
    $_SESSION['enterprise_customer_account_token']=$token;
    $_SESSION['enterprise_customer_account_expires_at']=(int)($payload['account_session_expires_at']??0);
    return $_SESSION['enterprise_user'];
};

if($_SERVER['REQUEST_METHOD']==='POST'){
    $action=(string)($_POST['action']??'login');
    try{
        enterprise_check_csrf((string)($_POST['csrf_token']??''));
        if($action==='login'){
            $identity=trim((string)($_POST['identity']??$_POST['email']??''));
            if($identity==='')throw new RuntimeException('LOGIN_IDENTITY_REQUIRED');
            if(!filter_var($identity,FILTER_VALIDATE_EMAIL)){
                $user=$localAdminLogin($identity,(string)($_POST['password']??''));
                header('Location: '.enterprise_landing_path($user));
                exit;
            }
            if(empty($cfg['enabled'])||empty($cfg['server_url']))throw new RuntimeException('LICENSE_SERVER_NOT_CONFIGURED');
            $api=new LicenseApiClient($cfg);
            $email=strtolower($identity);
            $payload=$api->loginAccount($email,(string)($_POST['password']??''));
            $user=$acceptPayload($payload,$email);
            header('Location: '.enterprise_landing_path($user));
            exit;
        }
        if(empty($cfg['enabled'])||empty($cfg['server_url']))throw new RuntimeException('LICENSE_SERVER_NOT_CONFIGURED');
        $api=new LicenseApiClient($cfg);
        if($action==='register'){
            $registerValues['register_email']=strtolower(trim((string)($_POST['register_email']??'')));
            $captchaState=(array)($_SESSION['enterprise_registration_captcha']??[]);
            unset($_SESSION['enterprise_registration_captcha']);
            $captchaAnswer=trim((string)($_POST['register_captcha']??''));
            if(empty($captchaState['answer']) || $captchaAnswer==='' || !hash_equals((string)$captchaState['answer'],$captchaAnswer)){
                throw new RuntimeException('REGISTRATION_CAPTCHA_INVALID');
            }
            $payload=$api->registerAccount([
                'email'=>$registerValues['register_email'],
                'company'=>$registerValues['company'],
                'first_name'=>$registerValues['first_name'],
                'last_name'=>$registerValues['last_name'],
                'phone'=>$registerValues['phone'],
                'requested_domain'=>$registerValues['requested_domain'],
                'password'=>(string)($_POST['register_password']??''),
                'password_confirm'=>(string)($_POST['register_password_confirm']??''),
            ]);
            $user=$acceptPayload($payload,$registerValues['register_email']);
            header('Location: '.enterprise_landing_path($user));
            exit;
        }
        throw new RuntimeException('LOGIN_ACTION_INVALID');
    }catch(Throwable $e){
        $code=$e->getMessage();$errorCode=$code;
        if($code==='LICENSE_SERVER_NOT_CONFIGURED')$error='Der zentrale Lizenzserver ist für Kundenanmeldungen noch nicht konfiguriert. Ein lokaler Administrator kann sich weiterhin mit seinem Admin-Benutzernamen anmelden.';
        elseif($code==='ADMIN_LOGIN_INVALID')$error='Admin-Benutzername oder Kennwort ist falsch.';
        elseif($code==='ADMIN_LOGIN_NOT_ALLOWED')$error='Dieses lokale Konto besitzt keine Administratorrolle.';
        elseif($code==='LOGIN_IDENTITY_REQUIRED')$error='Bitte Kunden-E-Mail oder lokalen Admin-Benutzernamen eingeben.';
        elseif($code==='LOGIN_ACTION_INVALID')$error='Unbekannte Anmeldeaktion.';
        else $error=$publicError($code);
        if($action==='register')$captcha=$registrationCaptcha(true);
    }
}

$captcha=$registrationCaptcha();
ob_start();
?>
<section class="hero">
    <span class="badge">easyIT Enterprise · Anmeldung</span>
    <h1>Enterprise-Manager</h1>
    <p>Kunden melden sich mit ihrer Kunden-E-Mail-Adresse an. Lokale Administratoren und Superadministratoren können sich auf derselben Seite mit ihrem Admin-Benutzernamen anmelden. Eine gültige Kundenlizenz ist für die Kundenanmeldung nicht erforderlich: ohne Lizenz wird der Manager im Demo-Modus geöffnet.</p>
</section>
<?php if(!$licenseConfigured):?>
<div class="notice error" role="alert">
    <strong>Lizenzserver noch nicht konfiguriert.</strong> Kundenanmeldung und Registrierung benötigen den Lizenzserver. Ein lokaler Administrator kann sich bereits mit seinem Admin-Benutzernamen anmelden.
    <div class="actions" style="margin-top:.75rem"><a class="button secondary" <?=easyit_button_attributes('einstellungen','license_server_setup')?> href="<?=e($localAdminHref)?>">Lokale Administration öffnen</a></div>
</div>
<?php elseif($error):?><div class="notice error" role="alert"><?=e($error)?>
<?php if(in_array($errorCode,['SERVER_SIGNATURE_UNTRUSTED','SERVER_SIGNATURE_INVALID'],true)):?>
<div class="actions" style="margin-top:.75rem"><a class="button secondary" <?=easyit_button_attributes('einstellungen','license_server_trust')?> href="<?=e($localAdminHref)?>">Vertrauensbeziehung in lokaler Administration prüfen</a></div>
<?php endif;?>
</div><?php endif;?>
<?php if($success):?><div class="notice success" role="status"><?=e($success)?></div><?php endif;?>

<div class="cards product-grid" style="align-items:start">
<section class="card login-card">
    <h2>Anmelden</h2>
    <form method="post" class="form-grid single-column" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?=e(enterprise_csrf())?>">
        <input type="hidden" name="action" value="login">
        <label>Kunden-E-Mail oder lokaler Admin-Benutzername
            <input type="text" name="identity" value="<?=e($loginIdentity)?>" required autofocus autocomplete="username">
            <small>Kunde: E-Mail-Adresse. Lokaler Admin/Superadmin: Benutzername, z. B. <code>admin</code>.</small>
        </label>
        <label>Kennwort
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <div class="actions"><button class="button" <?=easyit_button_attributes('anmelden')?> type="submit">Anmelden</button></div>
    </form>
    <p><small>Für Kunden gilt ohne aktive Lizenz der Demo-Modus. Lokale Administratoren werden unabhängig von der Kundenlizenz gegen den lokalen Administrationsspeicher authentifiziert.</small></p>
    <div class="actions"><a class="button secondary" <?=easyit_button_attributes('einstellungen','local_admin_login')?> href="admin-login.php">Lokale Administration direkt öffnen</a></div>
</section>

<section class="card login-card">
    <h2>Registrieren</h2>
    <p>Die Registrierung legt sofort ein aktives Kundenkonto an. Die E-Mail-Adresse wird als Kundenschlüssel verwendet. Eine Lizenz wird dabei nicht automatisch erzeugt.</p>
    <form method="post" class="form-grid single-column" autocomplete="on">
        <input type="hidden" name="csrf_token" value="<?=e(enterprise_csrf())?>">
        <input type="hidden" name="action" value="register">
        <label>Kunden-E-Mail / Kundenschlüssel<input type="email" name="register_email" value="<?=e($registerValues['register_email'])?>" required autocomplete="email"></label>
        <label>Firma / Organisation<input name="company" value="<?=e($registerValues['company'])?>" required autocomplete="organization"></label>
        <label>Vorname<input name="first_name" value="<?=e($registerValues['first_name'])?>" required autocomplete="given-name"></label>
        <label>Nachname<input name="last_name" value="<?=e($registerValues['last_name'])?>" required autocomplete="family-name"></label>
        <label>Telefon (optional)<input name="phone" value="<?=e($registerValues['phone'])?>" autocomplete="tel"></label>
        <label>Erlaubte Domain (optional)<input name="requested_domain" value="<?=e($registerValues['requested_domain'])?>" placeholder="kunde.example.de"><small>Bleibt das Feld leer, wird die aktuelle Enterprise-Domain übernommen.</small></label>
        <label>Kennwort<input type="password" name="register_password" minlength="10" required autocomplete="new-password"><small>Mindestens 10 Zeichen, Groß-/Kleinbuchstaben und eine Ziffer.</small></label>
        <label>Kennwort wiederholen<input type="password" name="register_password_confirm" minlength="10" required autocomplete="new-password"></label>
        <label>CAPTCHA-Sicherheitsfrage: <?=e((string)($captcha['question']??''))?> = ?
            <input type="number" name="register_captcha" inputmode="numeric" required autocomplete="off" aria-describedby="registration-captcha-help">
            <small id="registration-captcha-help">Bitte das Ergebnis der Rechnung eingeben. Nach einem Fehlversuch wird eine neue Aufgabe erzeugt.</small>
        </label>
        <div class="actions"><button class="button" <?=easyit_button_attributes('registrieren')?> type="submit">Registrieren und Demo öffnen</button></div>
    </form>
</section>
</div>

<section class="card"><h2>Lokale Administration</h2><p>Nur für Installation, Recovery und technische Administration dieser Enterprise-Instanz.</p><p><a class="button secondary" href="admin-login.php">Lokale Administration öffnen</a></p></section>
<?php
$content=ob_get_clean();
render_page([
    'title'=>'Anmeldung','active'=>'home','base'=>'','content'=>$content,'app_nav'=>false,
    'help'=>[
        'title'=>'Kundenanmeldung','location'=>'Enterprise → Anmeldung',
        'short'=>'Bestehende Kunden melden sich mit ihrem E-Mail-Kundenschlüssel an; neue Kunden registrieren sich direkt auf derselben Seite.',
        'goal'=>'Anmeldung unabhängig vom Lizenzstatus ermöglichen und ohne gültige Lizenz sicher auf den Demo-Modus begrenzen.',
        'steps'=>['Mit Kunden-E-Mail und Kennwort anmelden.','Falls kein Konto existiert: direkt registrieren.','Ohne gültige Lizenz den Demo-Modus verwenden; nach zentraler Lizenzfreigabe wird automatisch der lizenzierte Umfang aktiv.']
    ]
]);
