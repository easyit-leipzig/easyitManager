<?php
declare(strict_types=1);

require dirname(__DIR__).'/system/app/bootstrap.php';
require dirname(__DIR__).'/system/ui/layout.php';
require dirname(__DIR__).'/system/licensing/Autoload.php';

use EasyIT\Enterprise\Licensing\LicenseApiClient;

$user=enterprise_require_auth('../');
if(empty($user['customer'])){
    http_response_code(403);
    render_page(['title'=>'Mein Konto','active'=>'account','base'=>'../','content'=>'<div class="notice error">Diese Seite ist nur für Kundenkonten vorgesehen.</div>','app_nav'=>true,'user'=>$user]);
    exit;
}
$isDemo=enterprise_is_demo($user);

$error='';$message='';$profile=[];
$root=dirname(__DIR__);require_once $root.'/system/licensing/LicenseConfigStore.php';$cfg=EnterpriseLicenseConfigStore::load($root);if(!is_array($cfg))$cfg=[];
$token=(string)($_SESSION['enterprise_customer_account_token']??'');$currentInstallationId='';
try{
    if($token==='')throw new RuntimeException('ACCOUNT_SESSION_INVALID');
    $api=new LicenseApiClient($cfg);if(!$isDemo)$currentInstallationId=$api->installationId();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        enterprise_check_csrf((string)($_POST['csrf']??''));$action=(string)($_POST['action']??'');
        if($isDemo&&!in_array($action,['save_profile','change_password','submit_license_request','cancel_license_request'],true))throw new RuntimeException('ACCOUNT_LICENSE_REQUIRED');
        if($action==='save_profile'){
            $fields=['legal_name','contact_name','phone','mobile','website','street','postal_code','city','state_region','country_code','vat_id','billing_email','technical_email'];$data=[];foreach($fields as $f)$data[$f]=trim((string)($_POST[$f]??''));
            $profile=$api->updateAccountProfile($token,$data);$message='Ihre Stammdaten wurden gespeichert.';
        }elseif($action==='submit_license_request'){
            $profile=$api->submitAccountLicenseRequest($token,[
                'request_type'=>(string)($_POST['request_type']??($isDemo?'new':'change')),
                'products'=>(array)($_POST['products']??[]),
                'max_installations'=>(string)($_POST['max_installations']??'1'),
                'max_dataforms'=>(string)($_POST['max_dataforms']??'5'),
                'term_months'=>(string)($_POST['term_months']??'12'),
                'modules'=>(array)($_POST['modules']??[]),
                'capabilities'=>(array)($_POST['capabilities']??[]),
                'desired_domains'=>(string)($_POST['desired_domains']??''),
                'desired_networks'=>(string)($_POST['desired_networks']??''),
                'notes'=>(string)($_POST['license_request_notes']??''),
            ]);$message='Ihre differenzierte Lizenzanfrage wurde an den Lizenzserver übermittelt.';
        }elseif($action==='cancel_license_request'){
            $profile=$api->cancelAccountLicenseRequest($token,(string)($_POST['request_id']??''));$message='Die offene Lizenzanfrage wurde zurückgezogen.';
        }elseif($action==='change_password'){
            $api->changeAccountPassword($token,(string)($_POST['current_password']??''),(string)($_POST['new_password']??''),(string)($_POST['new_password_confirm']??''));
            unset($_SESSION['enterprise_user'],$_SESSION['enterprise_customer_license_payload'],$_SESSION['enterprise_customer_account_token'],$_SESSION['enterprise_customer_account_expires_at']);
            session_regenerate_id(true);
            header('Location: ../login.php?password_changed=1',true,303);exit;
        }elseif($action==='add_domain'){
            $profile=$api->addAccountDomain($token,trim((string)($_POST['domain']??'')));$message='Domain wurde hinzugefügt.';
        }elseif($action==='remove_domain'){
            $profile=$api->removeAccountDomain($token,(string)($_POST['rule_id']??''));$message='Domain wurde entfernt.';
        }elseif($action==='add_network'){
            $profile=$api->addAccountNetwork($token,trim((string)($_POST['cidr']??'')));$message='IP-/Netzfreigabe wurde hinzugefügt.';
        }elseif($action==='remove_network'){
            $profile=$api->removeAccountNetwork($token,(string)($_POST['rule_id']??''));$message='IP-/Netzfreigabe wurde entfernt.';
        }elseif($action==='retire_installation'){
            if(!enterprise_can($user,'customer.installations.manage'))throw new RuntimeException('ACCOUNT_ROLE_PERMISSION_DENIED');
            $iid=(string)($_POST['installation_id']??'');$profile=$api->retireAccountInstallation($token,$iid);$message='Installation wurde dauerhaft stillgelegt; ihr Installationsplatz ist wieder frei.';
        }else throw new RuntimeException('ACCOUNT_ACTION_INVALID');
    }
    if(!$profile)$profile=$api->accountProfile($token);
    $profileMode=(string)($profile['manager_mode']??($isDemo?'demo':'licensed'));
    $isDemo=$profileMode==='demo';
    if(isset($profile['license_policy'])){$_SESSION['enterprise_user']['license_policy']=$profile['license_policy'];$_SESSION['enterprise_customer_license_payload']['license_policy']=$profile['license_policy'];}
    if(isset($profile['modules'])){$_SESSION['enterprise_user']['modules']=$profile['modules'];$_SESSION['enterprise_customer_license_payload']['modules']=$profile['modules'];}
    $_SESSION['enterprise_user']['manager_mode']=$profileMode;
    $_SESSION['enterprise_user']['customer_key']=(string)($profile['customer_key']??$_SESSION['enterprise_user']['customer_key']??'');
    $_SESSION['enterprise_user']['license']=(array)($profile['license']??[]);
    if(isset($profile['role_policy']))$_SESSION['enterprise_user']['role_policy']=$profile['role_policy'];
    $surfacePolicy=(array)($profile['surface_policy']??['allowed'=>true,'error_code'=>null]);
    $surfaceAllowed=!array_key_exists('allowed',$surfacePolicy)||!empty($surfacePolicy['allowed']);
    $_SESSION['enterprise_user']['surface_policy']=$surfacePolicy;
    $_SESSION['enterprise_user']['surface_allowed']=$surfaceAllowed;
    $_SESSION['enterprise_user']['permissions']=($isDemo||!$surfaceAllowed)?[]:enterprise_customer_permissions((array)($_SESSION['enterprise_user']['modules']??[]),(array)($_SESSION['enterprise_user']['license_policy']??[]),(array)($_SESSION['enterprise_user']['role_policy']??[]));
    $user=$_SESSION['enterprise_user'];
}catch(Throwable $e){
    $code=$e->getMessage();
    $error=match($code){
        'ACCOUNT_SESSION_INVALID','ACCOUNT_SESSION_EXPIRED'=>'Ihre zentrale Kundenanmeldung ist abgelaufen. Bitte melden Sie sich erneut an.',
        'ACCOUNT_LICENSE_REQUIRED'=>'Diese Funktion steht erst mit einer gültigen Lizenz zur Verfügung. Ihr Demo-Zugang bleibt aktiv.',
        'CUSTOMER_SURFACE_SELF_SERVICE_DISABLED'=>'Die Pflege der Domain-/IP-Freigaben wurde vom Lizenzadministrator nicht freigegeben.',
        'ACCOUNT_PASSWORD_MISMATCH'=>'Die neuen Kennwörter stimmen nicht überein.',
        'ACCOUNT_PASSWORD_WEAK'=>'Das neue Kennwort muss mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie eine Ziffer enthalten.',
        'ACCOUNT_PASSWORD_CURRENT_INVALID'=>'Das aktuelle Kennwort ist falsch.',
        'ACCOUNT_PASSWORD_UNCHANGED'=>'Das neue Kennwort muss sich vom bisherigen Kennwort unterscheiden.',
        'SURFACE_DOMAIN_NOT_ALLOWED'=>'Diese Domain ist nicht freigegeben.',
        'SURFACE_DOMAIN_INVALID'=>'Die Domain ist ungültig.',
        'SURFACE_IP_INVALID'=>'Die IP-/Netzangabe ist ungültig.',
        'SURFACE_DOMAIN_LAST_REQUIRED'=>'Die letzte aktive Domainfreigabe kann nicht entfernt werden, solange die Domainprüfung verpflichtend ist.',
        'SURFACE_DOMAIN_CURRENT_REQUIRED'=>'Diese Domain wird für die aktuelle Enterprise-Oberfläche benötigt. Fügen Sie zuerst eine weitere passende Domainfreigabe hinzu.',
        'SURFACE_IP_LAST_REQUIRED'=>'Die letzte aktive IP-/Netzfreigabe kann nicht entfernt werden, solange die IP-Prüfung verpflichtend ist.',
        'SURFACE_IP_CURRENT_REQUIRED'=>'Diese IP-/Netzfreigabe wird für den aktuellen Zugriff benötigt. Fügen Sie zuerst eine weitere passende IP-/Netzfreigabe hinzu.',
        'INSTALLATION_TERMINAL'=>'Diese Installation ist bereits dauerhaft stillgelegt oder ersetzt.',
        'INSTALLATION_NOT_FOUND'=>'Die Installation wurde nicht gefunden.',
        'INSTALLATION_LICENSE_MISMATCH'=>'Die Installation gehört nicht zu Ihrer aktuellen Lizenz.',
        'ACCOUNT_ROLE_PERMISSION_DENIED'=>'Für die Installationsverwaltung fehlt die Berechtigung.',
        'LICENSE_REQUEST_PERMISSION_DENIED'=>'Nur ein Kundenadministrator darf eine Lizenzanfrage stellen.',
        'LICENSE_REQUEST_TYPE_INVALID'=>'Der Typ der Lizenzanfrage ist ungültig.',
        'LICENSE_REQUEST_INSTALLATIONS_INVALID'=>'Die gewünschte Anzahl Installationen ist ungültig.',
        'LICENSE_REQUEST_DATAFORMS_INVALID'=>'Die gewünschte DataForm-Anzahl ist ungültig.',
        'LICENSE_REQUEST_TERM_INVALID'=>'Die gewünschte Laufzeit ist ungültig.',
        'LICENSE_REQUEST_MODULE_REQUIRED'=>'Bitte wählen Sie mindestens ein benötigtes Modul.',
        'LICENSE_REQUEST_NOTES_TOO_LONG'=>'Die Zusatzhinweise dürfen höchstens 4000 Zeichen umfassen.',
        'LICENSE_REQUEST_NOT_FOUND'=>'Die Lizenzanfrage wurde nicht gefunden oder ist nicht mehr offen.',
        default=>'Die Kontodaten konnten nicht verarbeitet werden. ('.preg_replace('/[^A-Z0-9_]/','',$code).')',
    };
}
$c=(array)($profile['customer']??[]);$lic=(array)($profile['license']??($user['license']??[]));$policy=(array)($profile['license_policy']??($user['license_policy']??[]));$modules=(array)($profile['modules']??($user['modules']??[]));$domains=(array)($profile['domains']??[]);$networks=(array)($profile['networks']??[]);$installations=(array)($profile['installations']??[]);$dataforms=(array)($profile['dataforms']??[]);$installationUsage=(array)($profile['installation_usage']??[]);$licenseRequests=(array)($profile['license_requests']??[]);$licenseRequestCatalog=(array)($profile['license_request_catalog']??[]);$requestProducts=(array)($licenseRequestCatalog['products']??[]);$requestModules=(array)($licenseRequestCatalog['modules']??[]);$requestCapabilities=(array)($licenseRequestCatalog['capabilities']??[]);
$surfacePolicy=(array)($profile['surface_policy']??($user['surface_policy']??['allowed'=>true,'error_code'=>null]));
$surfaceAllowed=!array_key_exists('allowed',$surfacePolicy)||!empty($surfacePolicy['allowed']);
$currentModuleMap=array_fill_keys(array_map('strval',$modules),true);
$currentCapabilityCodes=(array)($policy['capabilities']??[]);
if((string)($policy['capability_policy']??'')==='legacy_unrestricted')$currentCapabilityCodes=array_values(array_filter(array_map(static fn(array $row):string=>(string)($row['capability_code']??''),$requestCapabilities)));
$currentCapabilityMap=array_fill_keys(array_map('strval',$currentCapabilityCodes),true);
$currentMaxInstall=max(1,(int)($installationUsage['max_installations']??1));
$currentMaxDataforms=array_key_exists('max_dataforms',$policy)?$policy['max_dataforms']:5;
$currentMaxDataformsValue=$currentMaxDataforms===null?'':(string)$currentMaxDataforms;
$canRequestLicense=(string)($user['role']??'customer_admin')==='customer_admin';
$f=static function(string $name,string $label,array $c,string $type='text'):string{return'<label>'.$label.'<input type="'.$type.'" name="'.$name.'" value="'.e((string)($c[$name]??'')).'"></label>';};
$fmtTime=static function(mixed $value):string{$ts=(int)($value??0);return $ts>0?date('d.m.Y H:i',$ts):'—';};
$statusText=static function(string $status):string{return match($status){'active'=>'aktiv','blocked'=>'gesperrt','retired'=>'stillgelegt','replaced'=>'ersetzt','pending'=>'offen','approved'=>'freigegeben','rejected'=>'abgelehnt','released'=>'freigegeben/gelöscht','revoked'=>'widerrufen','suspended'=>'gesperrt','expired'=>'abgelaufen','none'=>'nicht belegt','missing'=>'nicht vorhanden',default=>$status!==''?$status:'—'};};
$tokenText=static function(string $status):string{return match($status){'valid'=>'gültig / ausstellbar','pending'=>'noch nicht verfügbar','slot_inactive'=>'gesperrt (Lizenzplatz)','installation_inactive'=>'gesperrt (Installation)','invalid'=>'nicht verfügbar',default=>'—'};};
ob_start();
render_breadcrumbs([['label'=>'Enterprise','href'=>'dashboard.php'],['label'=>'Mein Konto','href'=>'']]);
?>
<section class="hero"><span class="badge"><?=$isDemo?'Demo-Konto':'Kundenkonto'?></span><h1>Mein Konto</h1><p>Hier pflegen Sie Ihre eigenen Unternehmens- und Kontaktdaten. Ihr Kundenschlüssel ist Ihre registrierte E-Mail-Adresse. Lizenzumfang und Freigaben werden zentral bestimmt.</p></section>
<?php if(!$isDemo&&!$surfaceAllowed):?><div class="notice error"><strong>Lizenz aktiviert – Herkunftsfreigabe noch offen.</strong><br>Die vergebene Lizenz ist dieser Installation bereits zugeordnet. Produktive Funktionen bleiben gesperrt, bis die aktuelle Domain/IP freigegeben ist. Ergänzen Sie unten unter <strong>Erlaubte Domains</strong> bzw. <strong>Erlaubte IP-Adressen / Netze</strong> die passende Freigabe.</div><?php endif;?>
<?php if($message):?><div class="notice success"><?=e($message)?></div><?php endif;?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>
<?php if($isDemo):?><div class="notice"><strong>Demo-Version aktiv.</strong> Sie können Ihre Stammdaten und Ihr Kennwort verwalten. Produktive Installationen, DataForms, Domains/IP-Freigaben für die Runtime und weitere lizenzpflichtige Funktionen werden erst nach Freischaltung einer gültigen Lizenz aktiviert.</div><?php endif;?>
<div class="metric-grid">
<div class="metric"><strong><?=e((string)($profile['customer_key']??$user['customer_key']??$c['customer_key']??'—'))?></strong><span>Kundenschlüssel</span></div>
<div class="metric"><strong><?=$isDemo?'Demo':e((string)($lic['number']??'—'))?></strong><span>Lizenz</span></div>
<div class="metric"><strong><?=$isDemo?'keine aktive Lizenz':e((string)($lic['status']??'—'))?></strong><span>Status</span></div>
<?php if(!$isDemo):?>
<div class="metric"><strong><?=e((string)($policy['used_dataforms']??0))?> / <?=array_key_exists('max_dataforms',$policy)?($policy['max_dataforms']===null?'∞':e((string)$policy['max_dataforms'])):'—'?></strong><span>DataForms</span></div>
<div class="metric"><strong><?=e((string)($installationUsage['used_installations']??count(array_filter($installations,fn($i)=>in_array((string)($i['status']??''),['active','blocked'],true)))))?> / <?=array_key_exists('max_installations',$installationUsage)?e((string)$installationUsage['max_installations']):'—'?></strong><span>Installationen</span></div>
<div class="metric"><strong><?=e(implode(', ',array_map('strval',$modules))?:'—')?></strong><span>Module</span></div>
<?php endif;?>
</div>
<section class="card" id="license-request">
<h2><?= $isDemo?'Lizenz beantragen':'Lizenz erweitern' ?></h2>
<p><?= $isDemo?'Stellen Sie hier Ihren Erstantrag. Der Antrag erzeugt noch keine Lizenz; die Freigabe erfolgt durch den Lizenzadministrator.':'Beantragen Sie hier zusätzliche Installationen, DataForms, Produkte, Module, Funktionen oder eine längere Laufzeit. Bei einer Erweiterung bleiben bereits lizenzierte Bestandteile erhalten.' ?></p>
<?php $pendingRequest=null;foreach($licenseRequests as $lr){if((string)($lr['status']??'')==='pending'){$pendingRequest=$lr;break;}} ?>
<?php if($pendingRequest):?><div class="notice"><strong>Offene Anfrage vorhanden.</strong> Antrag <?=e((string)$pendingRequest['request_id'])?> vom <?=e($fmtTime($pendingRequest['requested_at']??null))?> wartet auf Bearbeitung. Eine neue Anfrage ersetzt die bisher offene Anfrage.</div><?php endif;?>
<?php if(!$canRequestLicense):?>
<div class="notice error"><strong>Keine Antragsberechtigung.</strong> Nur ein Kundenadministrator kann eine Lizenz beantragen oder erweitern.</div>
<?php else:?>
<form method="post" class="form-grid" id="license-extension-form">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="submit_license_request">
<label>Antragstyp<select name="request_type"><?php if($isDemo):?><option value="new">Erstlizenz</option><?php else:?><option value="extension" selected>Lizenz erweitern</option><option value="change">Lizenzumfang neu festlegen</option><?php endif;?></select></label>
<label><?= $isDemo?'Gewünschte Laufzeit':'Gewünschte Verlängerung' ?><select name="term_months"><option value="1">1 Monat</option><option value="3">3 Monate</option><option value="6">6 Monate</option><option value="12" selected>12 Monate</option><option value="24">24 Monate</option><option value="36">36 Monate</option><option value="60">60 Monate</option><option value="0">unbefristet anfragen</option></select></label>
<label>Max. Installationen<input type="number" name="max_installations" min="1" max="1000" value="<?=e((string)($isDemo?1:$currentMaxInstall))?>" required><?php if(!$isDemo):?><small>Aktuell: <?=e((string)$currentMaxInstall)?></small><?php endif;?></label>
<label>Max. DataForms<input type="number" name="max_dataforms" min="0" max="100000" value="<?=e((string)($isDemo?'5':$currentMaxDataformsValue))?>" placeholder="leer = unbegrenzt"><?php if(!$isDemo):?><small>Aktuell: <?=$currentMaxDataforms===null?'unbegrenzt':e((string)$currentMaxDataforms)?></small><?php endif;?></label>
<div class="full" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.75rem 0 1rem"><button class="button" type="submit"><?= $isDemo?'Lizenzanfrage senden':'Lizenzerweiterung beantragen' ?></button><small>Sie können den Antrag bereits mit den aktuellen Einstellungen absenden oder unten zusätzliche Produkte, Module und Funktionen auswählen.</small></div>
<fieldset class="full"><legend>Produkte</legend><div class="check-grid"><?php foreach($requestProducts as $rp):$code=(string)($rp['code']??'');$checked=$isDemo?($code==='dataform'):isset($currentModuleMap[$code]);?><label><input type="checkbox" name="products[]" value="<?=e($code)?>"<?=$checked?' checked':''?>> <?=e((string)($rp['name']??$code))?></label><?php endforeach;?></div></fieldset>
<fieldset class="full"><legend>Module</legend><div class="check-grid"><?php foreach($requestModules as $rm):$code=(string)($rm['module_code']??'');$checked=$isDemo?($code==='dataform'):isset($currentModuleMap[$code]);?><label><input type="checkbox" name="modules[]" value="<?=e($code)?>"<?=$checked?' checked':''?>> <?=e((string)($rm['name']??$code))?> <small>(<?=e($code)?>)</small></label><?php endforeach;?></div></fieldset>
<details class="full"><summary><strong>Funktionen, Domains/IPs und Zusatzhinweise</strong></summary>
<div style="padding-top:1rem">
<fieldset class="full"><legend>Gewünschte Funktionen</legend><?php $lastCategory='';$defaultCaps=['dataset.read','dataset.create','dataset.update','dataset.delete','dataform.search','dataform.filter','dataform.pagination','relation.read','relation.createChild'];foreach($requestCapabilities as $rc):$cat=(string)($rc['category']??'sonstiges');if($cat!==$lastCategory){if($lastCategory!=='')echo'</div>';echo'<h4>'.e(ucfirst($cat)).'</h4><div class="check-grid">';$lastCategory=$cat;}$code=(string)($rc['capability_code']??'');$checked=$isDemo?in_array($code,$defaultCaps,true):isset($currentCapabilityMap[$code]);?><label><input type="checkbox" name="capabilities[]" value="<?=e($code)?>"<?=$checked?' checked':''?>> <?=e((string)($rc['name']??$code))?> <small>(<?=e($code)?>)</small></label><?php endforeach;if($lastCategory!=='')echo'</div>';?></fieldset>
<label class="full">Gewünschte Domains <small>eine Domain pro Zeile; z. B. kunde.example.de oder *.kunde.example.de</small><textarea name="desired_domains" rows="4" placeholder="kunde.example.de"></textarea></label>
<label class="full">Gewünschte IP-Adressen / Netze <small>eine Adresse oder ein CIDR-Netz pro Zeile</small><textarea name="desired_networks" rows="4" placeholder="203.0.113.10&#10;203.0.113.0/24"></textarea></label>
<label class="full">Zusatzhinweise / Einsatzzweck<textarea name="license_request_notes" rows="5" maxlength="4000" placeholder="Geplanter Einsatz, besondere Anforderungen, gewünschte Erweiterungen ..."></textarea></label>
</div></details>
<div class="full"><button class="button" type="submit"><?= $isDemo?'Lizenzanfrage senden':'Lizenzerweiterung beantragen' ?></button></div>
</form>
<?php endif;?>
<?php if($licenseRequests):?><h3>Bisherige Lizenzanfragen</h3><div class="table-wrap"><table><thead><tr><th>Antrag</th><th>Typ</th><th>Umfang</th><th>Status</th><th>Datum</th><th>Entscheidung</th><th>Aktion</th></tr></thead><tbody><?php foreach($licenseRequests as $lr):?><tr><td><code><?=e((string)($lr['request_id']??''))?></code></td><td><?=e((string)($lr['request_type']??''))?></td><td><?=e((string)($lr['max_installations']??''))?> Installationen<br><?=($lr['max_dataforms']??null)===null?'∞':e((string)$lr['max_dataforms'])?> DataForms<br><small><?=e(implode(', ',(array)($lr['requested_modules']??[])))?></small></td><td><?=e($statusText((string)($lr['status']??'')))?></td><td><?=e($fmtTime($lr['requested_at']??null))?></td><td><?=e((string)($lr['decision_note']??'—'))?></td><td><?php if((string)($lr['status']??'')==='pending'):?><form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="cancel_license_request"><input type="hidden" name="request_id" value="<?=e((string)$lr['request_id'])?>"><button class="button" type="submit">Zurückziehen</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div><?php endif;?>
</section>
<section class="card"><h2>Stammdaten</h2><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="save_profile">
<?=$f('legal_name','Rechtlicher Firmenname',$c)?><?=$f('contact_name','Ansprechpartner',$c)?><label>Kunden-E-Mail / Kundenschlüssel<input type="email" value="<?=e((string)($profile['customer_key']??$user['customer_key']??$c['customer_key']??''))?>" readonly></label><?=$f('phone','Telefon',$c)?><?=$f('mobile','Mobil',$c)?><?=$f('website','Website',$c)?><?=$f('street','Straße/Hausnummer',$c)?><?=$f('postal_code','PLZ',$c)?><?=$f('city','Ort',$c)?><?=$f('state_region','Bundesland/Region',$c)?><?=$f('country_code','Land (ISO)',$c)?><?=$f('vat_id','USt-ID',$c)?><?=$f('billing_email','Rechnungs-E-Mail',$c,'email')?><?=$f('technical_email','Technische E-Mail',$c,'email')?>
<div class="full"><button class="button" type="submit">Stammdaten speichern</button></div></form></section>
<section class="card"><h2>Kennwort ändern</h2><p>Nach erfolgreicher Änderung werden alle zentralen Kundenanmeldungen dieses Kontos widerrufen. Sie müssen sich anschließend mit dem neuen Kennwort erneut anmelden.</p><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="change_password"><label>Aktuelles Kennwort<input type="password" name="current_password" autocomplete="current-password" required></label><label>Neues Kennwort<input type="password" name="new_password" minlength="10" autocomplete="new-password" required></label><label>Neues Kennwort wiederholen<input type="password" name="new_password_confirm" minlength="10" autocomplete="new-password" required></label><div class="full"><button class="button" type="submit">Kennwort ändern</button></div></form></section>
<?php if(!$isDemo):?>
<section class="card"><h2>Installationen</h2><p><strong>Aktiv</strong> und <strong>gesperrt</strong> belegen weiterhin einen Installationsplatz. <strong>Stillgelegt</strong> und <strong>ersetzt</strong> sind terminal und geben den Platz dauerhaft frei. Eine Stilllegung kann nicht rückgängig gemacht werden.</p><div class="table-wrap"><table><thead><tr><th>Installation</th><th>Produkt</th><th>Status</th><th>Lizenzplatz</th><th>Aktiviert</th><th>Letzter Kontakt</th><th>Aktion</th></tr></thead><tbody><?php if(!$installations):?><tr><td colspan="7">Noch keine Installation registriert.</td></tr><?php endif;?><?php foreach($installations as $i):$iid=(string)($i['installation_id']??'');$istatus=(string)($i['status']??'');$consumes=in_array($istatus,['active','blocked'],true);$current=$iid!==''&&hash_equals($currentInstallationId,$iid);?><tr><td><code><?=e($iid)?></code><?php if($current):?><br><small>aktuell verwendete Installation</small><?php endif;?></td><td><?=e((string)($i['product_code']??'—'))?><?php if(trim((string)($i['product_version']??''))!==''):?><br><small>Version <?=e((string)$i['product_version'])?></small><?php endif;?></td><td><?=e($statusText($istatus))?></td><td><?=$consumes?'belegt':'frei'?></td><td><?=e($fmtTime($i['activated_at']??null))?></td><td><?=e($fmtTime($i['last_seen_at']??null))?></td><td><?php if(enterprise_can($user,'customer.installations.manage')&&$consumes):?><form method="post" onsubmit="return confirm('Installation dauerhaft stilllegen? Der Installationsschlüssel und laufende Runtime-Sitzungen werden sofort widerrufen. Diese Aktion kann nicht rückgängig gemacht werden.');"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="retire_installation"><input type="hidden" name="installation_id" value="<?=e($iid)?>"><button class="button" type="submit">Stilllegen</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card"><h2>Registrierte DataForms</h2><p>Die Liste zeigt den zentral bekannten DataForm-Bestand innerhalb Ihres wirksamen Projekt-/DataForm-Zugriffs. Ein aktiver Lizenzplatz wird nur bei <strong>Lizenzplatz: belegt</strong> gezählt.</p><div class="table-wrap"><table><thead><tr><th>DataForm</th><th>Projekt</th><th>Registrierungsstatus</th><th>Lizenzplatz</th><th>Tokenstatus</th><th>Installation</th><th>Registriert</th><th>Letzter Kontakt</th></tr></thead><tbody><?php if(!$dataforms):?><tr><td colspan="8">Keine sichtbaren DataForms registriert.</td></tr><?php endif;?><?php foreach($dataforms as $df):?><tr><td><strong><?=e((string)($df['name']??'—'))?></strong><br><code><?=e((string)($df['dataform_uuid']??''))?></code><br><small>lokale ID <?=e((string)($df['local_dataform_id']??''))?></small></td><td><?=e((string)($df['project_id']??'—'))?></td><td><?=e($statusText((string)($df['status']??'')))?></td><td><?=!empty($df['consumes_license_slot'])?'belegt':'frei'?><br><small><?=e($statusText((string)($df['usage_status']??'none')))?></small></td><td><?=e($tokenText((string)($df['token_status']??'')))?></td><td><code><?=e((string)($df['installation_id']??''))?></code><br><small><?=e($statusText((string)($df['installation_status']??'missing')))?></small></td><td><?=e($fmtTime($df['registered_at']??null))?></td><td><?=e($fmtTime($df['last_seen_at']??null))?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card"><h2>Erlaubte Domains</h2><p>Die Oberfläche darf nur aus den zentral freigegebenen Herkunftsbereichen verwendet werden.</p>
<?php if((int)($c['surface_self_service']??0)===1):?><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="add_domain"><label class="full">Domain<input name="domain" placeholder="kunde.example.de oder *.kunde.example.de" required></label><div class="full"><button class="button" type="submit">Domain hinzufügen</button></div></form><?php endif;?>
<div class="table-wrap"><table><thead><tr><th>Domain</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach($domains as $d):?><tr><td><code><?=e((string)$d['domain_pattern'])?></code></td><td><?=e((string)$d['status'])?></td><td><?php if((int)($c['surface_self_service']??0)===1):?><form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="remove_domain"><input type="hidden" name="rule_id" value="<?=e((string)$d['rule_id'])?>"><button class="button" type="submit">Entfernen</button></form><?php else:?>nur Superadmin<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="card"><h2>Erlaubte IP-Adressen / Netze</h2><p>IPv4- und IPv6-Adressen können einzeln oder als CIDR-Netz freigegeben werden, zum Beispiel <code>203.0.113.10</code>, <code>203.0.113.0/24</code> oder <code>2001:db8::/32</code>.</p>
<?php if((int)($c['surface_self_service']??0)===1):?><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="add_network"><label class="full">IP / CIDR<input name="cidr" placeholder="203.0.113.10 oder 203.0.113.0/24" required></label><div class="full"><button class="button" type="submit">IP / Netz hinzufügen</button></div></form><?php endif;?>
<div class="table-wrap"><table><thead><tr><th>IP / Netz</th><th>Status</th><th>Aktion</th></tr></thead><tbody><?php foreach($networks as $n):?><tr><td><code><?=e((string)$n['cidr'])?></code></td><td><?=e((string)$n['status'])?></td><td><?php if((int)($c['surface_self_service']??0)===1):?><form method="post"><input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="remove_network"><input type="hidden" name="rule_id" value="<?=e((string)$n['rule_id'])?>"><button class="button" type="submit">Entfernen</button></form><?php else:?>nur Superadmin<?php endif;?></td></tr><?php endforeach;?></tbody></table></div></section>
<?php endif;?>
<?php
$content=ob_get_clean();
render_page(['title'=>'Mein Konto','active'=>'account','base'=>'../','content'=>$content,'app_nav'=>true,'user'=>$_SESSION['enterprise_user'],'help'=>['title'=>'Mein Konto','location'=>'Enterprise → Mein Konto','short'=>'Eigene Kundendaten verwalten und differenzierte Lizenzanfragen stellen.','goal'=>'Kundendaten aktuell halten, gewünschten Lizenzumfang strukturiert anfragen und den Bearbeitungsstatus verfolgen.']]);
