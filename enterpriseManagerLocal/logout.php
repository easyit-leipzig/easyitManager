<?php
declare(strict_types=1);
require __DIR__.'/system/app/bootstrap.php';

$u=enterprise_user();
if(is_array($u) && !empty($u['customer'])){
    try{
        require __DIR__.'/system/licensing/Autoload.php';
        require_once __DIR__.'/system/licensing/LicenseConfigStore.php';
        $cfgFile=EnterpriseLicenseConfigStore::persistentPath(__DIR__);
        if(!is_file($cfgFile))$cfgFile=EnterpriseLicenseConfigStore::legacyPath(__DIR__);
        $cfg=is_file($cfgFile)?require $cfgFile:[];
        $token=(string)($_SESSION['enterprise_customer_account_token']??'');
        if(is_array($cfg)&&$token!==''){
            $api=new \EasyIT\Enterprise\Licensing\LicenseApiClient($cfg);
            $api->logoutAccount($token);
        }
    }catch(Throwable){}
}elseif(is_array($u)){
    try{
        $id=is_numeric($u['id']??null)?(int)$u['id']:null;
        enterprise_audit(enterprise_pdo(),$id,'auth.logout','user',(string)($u['id']??''));
        enterprise_event_dispatch('auth.user.logged_out',['user_id'=>$id,'username'=>(string)($u['username']??'')],['source'=>'logout']);
    }catch(Throwable){}
}
$_SESSION=[];
if(ini_get('session.use_cookies')){
    $p=session_get_cookie_params();
    setcookie(session_name(),'',time()-42000,$p['path']??'/',$p['domain']??'',(bool)($p['secure']??false),(bool)($p['httponly']??true));
}
session_destroy();
header('Location: login.php');exit;
