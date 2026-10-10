<?php
declare(strict_types=1);

namespace EasyIT\LicenseServer\Admin;

use DateTimeImmutable;
use DateTimeZone;
use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Service\DataFormRegistryService;
use EasyIT\LicenseServer\Service\CustomerCredentialService;
use EasyIT\LicenseServer\Service\DataFormTokenService;
use EasyIT\LicenseServer\Security\ServerSigner;
use EasyIT\LicenseServer\Service\LicenseService;
use EasyIT\LicenseServer\Service\InstallationLifecycleService;
use EasyIT\LicenseServer\Service\BootstrapActivationService;
use EasyIT\LicenseServer\Service\SurfacePolicyService;
use EasyIT\LicenseServer\Support\Id;
use PDO;

final class AdminApp
{
    private PDO $pdo;
    private AdminAuth $auth;
    private AuditService $audit;
    private LicenseService $licenses;
    private DataFormRegistryService $dataformRegistry;
    private ServerSigner $signer;
    private string $base;

    public static function run(array $cfg): never
    {
        try {
            $app=new self($cfg);
            $app->dispatch();
        } catch (\Throwable $e) {
            self::renderBootstrapFailure($e);
        }
        exit;
    }

    private static function renderBootstrapFailure(\Throwable $e): never
    {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        $message=$e->getMessage();
        if(!str_starts_with($message,'DATABASE_BOOTSTRAP_FAILED:')){
            $message='Datenbankverbindung nicht verfügbar: '.$message;
        }
        $safe=htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>easyIT Lizenzserver</title></head><body style="font-family:system-ui,sans-serif;max-width:900px;margin:4rem auto;padding:0 1.5rem"><h1>Lizenzserver-Datenquelle nicht verfügbar</h1><p>'.$safe.'</p><p>Die zentrale Datenquelle wird ab HF110 über den Einrichtungsassistenten festgelegt.</p><p><a href="../setup/">Datenquelle prüfen / Lizenzserver einrichten</a></p></body></html>';
        exit;
    }

    private function __construct(private array $cfg)
    {
        $this->pdo=Connection::create($cfg['database']);
        (new MigrationRunner($this->pdo))->migrate();
        $this->audit=new AuditService($this->pdo);
        $this->licenses=new LicenseService($this->pdo);
        $this->signer=new ServerSigner($cfg['security']['server_key_id'],$cfg['security']['server_private_key_file'],$cfg['security']['server_public_key_file'],$cfg['security']['server_keyring_file']??((string)$cfg['app']['base_path'].'/secure/keys/server-keyring.json'));
        $tokens=new DataFormTokenService($this->pdo,$this->signer,$this->licenses);
        $this->dataformRegistry=new DataFormRegistryService($this->pdo,$this->licenses,$this->audit,new SurfacePolicyService($this->pdo,$cfg['network']??[]),$tokens);
        $this->auth=new AdminAuth($this->pdo,$cfg,$this->audit);
        $this->auth->startSession();
        $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/admin/index.php'));
        $this->base=rtrim(dirname($script),'/');if($this->base==='.'||$this->base==='')$this->base='/admin';
        $this->securityHeaders();
    }

    private function dispatch(): never
    {
        $page=(string)($_GET['page']??'dashboard');$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
        try{
            if(!$this->auth->hasAdmins()){
                if($method==='POST' && $page==='setup'){$this->postSetup();}
                $this->renderSetup();
            }
            if($page==='login'){
                if($method==='POST')$this->postLogin();
                $this->renderLogin();
            }
            if($page==='logout'){
                if($method!=='POST')$this->redirect('dashboard');
                $this->auth->verifyCsrf((string)($_POST['csrf']??''));$this->auth->logout();$this->redirect('login');
            }
            $user=$this->auth->user();if(!$user)$this->redirect('login');
            if($method==='POST'){
                $this->auth->verifyCsrf((string)($_POST['csrf']??''));
                $this->handlePost($page,$user);
            }
            match($page){
                'dashboard'=>$this->renderDashboard($user),
                'access_requests'=>$this->renderAccessRequests($user),
                'license_requests'=>$this->renderLicenseRequests($user),
                'project_licenses'=>$this->renderProjectLicenses($user),
                'customers'=>$this->renderCustomers($user),
                'customer'=>$this->renderCustomer($user),
                'customer_surface'=>$this->renderCustomerSurface($user),
                'licenses'=>$this->renderLicenses($user),
                'license'=>$this->renderLicense($user),
                'dataforms'=>$this->renderRegisteredDataforms($user),
                'dataform_policy'=>$this->renderDataformPolicy($user),
                'installations'=>$this->renderInstallations($user),
                'audit'=>$this->renderAudit($user),
                default=>$this->renderNotFound($user),
            };
        }catch(\Throwable $e){
            $code=$e->getMessage();
            if($code==='ADMIN_LOGIN_REQUIRED')$this->redirect('login');
            $this->auth->flash('error',$this->publicError($code));
            if($method==='POST')$this->redirect((string)($_POST['return_page']??'dashboard'),$this->returnParams());
            $u=$this->auth->user();
            if($u)$this->renderError($u,$this->publicError($code));
            $this->renderLogin($this->publicError($code));
        }
    }

    private function handlePost(string $page,array $user): void
    {
        $action=(string)($_POST['action']??'');

        if($page==='dashboard' && $action==='create_bootstrap_activation'){
            $this->createBootstrapActivation($user);
        }
        if($page==='customers' && $action==='create_customer'){
            $this->createCustomer($user);
        }
        if($page==='customer' && $action==='update_customer'){
            $this->updateCustomer($user);
        }
        if($page==='licenses' && $action==='create_license'){
            $this->createLicense($user);
        }
        if($page==='license' && $action==='update_license'){
            $this->updateLicense($user);
        }
        if($page==='license' && $action==='rotate_key'){
            $this->rotateLicenseKey($user);
        }
        if($page==='license' && $action==='release_dataform'){
            $this->releaseDataform($user);
        }
        if($page==='license' && $action==='set_installation_status'){
            $this->setInstallationStatus($user);
        }
        if($page==='dataform_policy' && $action==='save_dataform_policy'){
            $this->saveDataformPolicy($user);
        }
        if($page==='installations' && $action==='set_installation_status'){$this->setInstallationStatus($user);}
        if($page==='customer_surface' && $action==='add_domain'){$this->adminAddDomain($user);}
        if($page==='customer_surface' && $action==='remove_domain'){$this->adminRemoveDomain($user);}
        if($page==='customer_surface' && $action==='add_network'){$this->adminAddNetwork($user);}
        if($page==='customer_surface' && $action==='remove_network'){$this->adminRemoveNetwork($user);}
        if($page==='customer_surface' && $action==='create_customer_user'){$this->createCustomerUser($user);}
        if($page==='customer_surface' && $action==='set_customer_user_status'){$this->setCustomerUserStatus($user);}
        if($page==='customer_surface' && $action==='reset_customer_user_password'){$this->resetCustomerUserPassword($user);}
        if($page==='access_requests' && $action==='approve_access_request'){$this->approveAccessRequest($user);}
        if($page==='access_requests' && $action==='reject_access_request'){$this->rejectAccessRequest($user);}
        if($page==='license_requests' && $action==='approve_license_request'){$this->approveLicenseRequest($user);}
        if($page==='license_requests' && $action==='reject_license_request'){$this->rejectLicenseRequest($user);}
        if($page==='project_licenses' && $action==='approve_project_product_request'){$this->approveProjectProductRequest($user);}
        if($page==='project_licenses' && $action==='reject_project_product_request'){$this->rejectProjectProductRequest($user);}
        if($page==='dataforms' && $action==='approve_dataform'){$this->approveRegisteredDataform($user);}
        if($page==='dataforms' && $action==='reject_dataform'){$this->rejectRegisteredDataform($user);}

        throw new \RuntimeException('ADMIN_ACTION_INVALID');
    }

    private function postSetup(): never
    {
        $this->auth->verifyCsrf((string)($_POST['csrf']??''));
        $token=(string)($_POST['setup_token']??'');$username=(string)($_POST['username']??'');$display=(string)($_POST['display_name']??'');$pw=(string)($_POST['password']??'');$pw2=(string)($_POST['password_confirm']??'');
        if($pw!==$pw2)throw new \RuntimeException('ADMIN_PASSWORD_MISMATCH');
        $this->auth->setup($token,$username,$pw,$display);
        $this->auth->flash('success','Der erste Superadmin wurde angelegt. Bitte anmelden.');
        $this->redirect('login');
    }

    private function postLogin(): never
    {
        $this->auth->verifyCsrf((string)($_POST['csrf']??''));
        $username=(string)($_POST['username']??'');$password=(string)($_POST['password']??'');
        if(!$this->auth->login($username,$password)){$this->auth->flash('error','Anmeldung fehlgeschlagen. Benutzer, Kennwort oder Sperrstatus prüfen.');$this->redirect('login');}
        $this->redirect('dashboard');
    }

    private function approveAccessRequest(array $user): never
    {
        $requestId=(string)($_POST['request_id']??'');
        $st=$this->pdo->prepare("SELECT * FROM customer_access_requests WHERE request_id=? AND status='pending' LIMIT 1");$st->execute([$requestId]);$r=$st->fetch();if(!$r)throw new \RuntimeException('ACCESS_REQUEST_NOT_FOUND');
        $maxInstall=max(1,(int)($_POST['max_installations']??1));$maxDf=$this->parseMaxDataforms((string)($_POST['max_dataforms']??'5'));
        $lease=max(60,(int)($_POST['lease_seconds']??3600));$grace=max($lease,(int)($_POST['grace_seconds']??86400));
        $validUntil=$this->dateEndTimestamp((string)($_POST['valid_until']??''));if($validUntil===null)$validUntil=time()+365*86400;
        $selectedModules=array_fill_keys(array_map('strval',(array)($_POST['modules']??['dataform'])),true);
        $selectedCaps=array_fill_keys(array_map('strval',(array)($_POST['capabilities']??[])),true);
        $customerId=Id::make('CUS');$customerNumber=$customerId;
        $licenseId=Id::make('LICID');$licenseNumber='LIC-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(5)));$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $customerUserId=Id::make('CUSU');$now=time();$display=trim((string)$r['first_name'].' '.(string)$r['last_name']);$email=strtolower((string)$r['email']);
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO customers (customer_id,customer_number,customer_key,name,company,email,status,legal_name,contact_name,phone,surface_domain_required,surface_ip_required,surface_self_service,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$customerId,$customerNumber,$email,$display,(string)$r['company'],$email,'active',(string)$r['company'],$display,$r['phone'],1,0,1,$now,$now]);
            $this->pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$licenseId,$licenseNumber,password_hash($secret,PASSWORD_DEFAULT),$customerId,'active',$now-60,$validUntil,$maxInstall,$lease,$grace,1,$now,$now]);
            $this->setModules($licenseId,$selectedModules);$this->setPolicy($licenseId,$maxDf,$selectedCaps);
            $this->pdo->prepare('INSERT INTO customer_users (customer_user_id,customer_id,username,email,license_id,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$customerUserId,$customerId,$email,$email,$licenseId,(string)$r['password_hash'],$display,'customer_admin','active',0,null,null,$now,$now]);
            if(!empty($r['requested_domain'])){$rule=Id::make('DOM');$this->pdo->prepare("INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute([$rule,$customerId,(string)$r['requested_domain'],$now,$now]);}
            $this->pdo->prepare("UPDATE customer_access_requests SET status='approved',reviewed_at=?,reviewed_by_admin_id=?,customer_id=?,customer_user_id=?,license_id=?,decision_note=?,updated_at=? WHERE request_id=?")->execute([$now,$user['id'],$customerId,$customerUserId,$licenseId,trim((string)($_POST['decision_note']??''))?:null,$now,$requestId]);
            $this->audit->write('ACCOUNT_ACCESS_APPROVED','access_request',$requestId,'admin',$user['id'],null,['email'=>$email],['customer_id'=>$customerId,'license_id'=>$licenseId,'max_dataforms'=>$maxDf]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->auth->putSecretOnce('license_key_'.$licenseId,$licenseNumber.'.'.$secret);
        $this->auth->flash('success','Zugang freigegeben. Kundenkonto und Lizenz '.$licenseNumber.' wurden angelegt.');
        $this->redirect('license',['id'=>$licenseId]);
    }

    private function rejectAccessRequest(array $user): never
    {
        $requestId=(string)($_POST['request_id']??'');$now=time();
        $st=$this->pdo->prepare("UPDATE customer_access_requests SET status='rejected',reviewed_at=?,reviewed_by_admin_id=?,decision_note=?,updated_at=? WHERE request_id=? AND status='pending'");
        $st->execute([$now,$user['id'],trim((string)($_POST['decision_note']??''))?:null,$now,$requestId]);if($st->rowCount()===0)throw new \RuntimeException('ACCESS_REQUEST_NOT_FOUND');
        $this->audit->write('ACCOUNT_ACCESS_REJECTED','access_request',$requestId,'admin',$user['id']);$this->auth->flash('success','Zugangsanforderung wurde abgelehnt.');$this->redirect('access_requests');
    }

    private function renderAccessRequests(array $user): never
    {
        $pending=$this->pdo->query("SELECT * FROM customer_access_requests WHERE status='pending' ORDER BY requested_at ASC")->fetchAll();
        $recent=$this->pdo->query("SELECT * FROM customer_access_requests WHERE status<>'pending' ORDER BY COALESCE(reviewed_at,updated_at) DESC LIMIT 50")->fetchAll();
        $modules=$this->pdo->query("SELECT module_code,name FROM modules WHERE active=1 ORDER BY module_code")->fetchAll();
        $caps=$this->pdo->query("SELECT capability_code,name,category FROM capability_registry WHERE active=1 ORDER BY category,capability_code")->fetchAll();
        $defaultCaps=['dataset.read','dataset.create','dataset.update','dataset.delete','dataform.search','dataform.filter','dataform.pagination','relation.read','relation.createChild'];
        $body='<h1>Zugangsanforderungen</h1>'.$this->alerts().'<p>Neue Kundenkonten werden erst nach manueller Freigabe und Lizenzvergabe aktiv.</p>';
        if(!$pending){$body.='<section><p class="muted">Keine offenen Anforderungen.</p></section>';}else foreach($pending as $r){
            $body.='<section><div class="title-row"><div><h2>'.self::h($r['company']).'</h2><p><strong>'.self::h($r['first_name'].' '.$r['last_name']).'</strong> · '.self::h($r['email']).' · '.self::h($r['phone']??'').'</p><p>Gewünschte Domain: <code>'.self::h($r['requested_domain']??'–').'</code> · Antrag: '.$this->fmtTime($r['requested_at']).'</p></div>'.$this->badge('pending').'</div>';
            $body.='<form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="approve_access_request"><input type="hidden" name="request_id" value="'.self::h($r['request_id']).'"><div class="grid3"><label>Max. Installationen<input type="number" min="1" name="max_installations" value="1"></label><label>Max. DataForms<input type="number" min="0" name="max_dataforms" value="5"></label><label>Gültig bis<input type="date" name="valid_until" value="'.gmdate('Y-m-d',time()+365*86400).'"></label><label>Lease (Sek.)<input type="number" min="60" name="lease_seconds" value="3600"></label><label>Grace (Sek.)<input type="number" min="60" name="grace_seconds" value="86400"></label><label>Entscheidungsnotiz<input name="decision_note"></label></div><h3>Module</h3><div class="checks">';
            foreach($modules as $m)$body.=$this->check('modules[]',(string)$m['module_code'],(string)$m['name'].' · '.$m['module_code'],(string)$m['module_code']==='dataform');
            $body.='</div><h3>Funktionen</h3>'.$this->capabilityChecks($caps,$defaultCaps).'<button class="primary">Freigeben und Lizenz erzeugen</button></form>';
            $body.='<form method="post" class="padtop"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="reject_access_request"><input type="hidden" name="request_id" value="'.self::h($r['request_id']).'"><label>Ablehnungsnotiz<input name="decision_note"></label><button class="warn">Anforderung ablehnen</button></form></section>';
        }
        $body.='<section><h2>Zuletzt bearbeitet</h2><table><thead><tr><th>E-Mail</th><th>Firma</th><th>Status</th><th>Bearbeitet</th></tr></thead><tbody>';foreach($recent as $r)$body.='<tr><td>'.self::h($r['email']).'</td><td>'.self::h($r['company']).'</td><td>'.$this->badge($r['status']).'</td><td>'.$this->fmtTime($r['reviewed_at']).'</td></tr>';$body.='</tbody></table></section>';
        $this->renderAdmin('Zugangsanforderungen',$body,$user);
    }

    private function approveLicenseRequest(array $user): never
    {
        $requestId=(string)($_POST['request_id']??'');
        $st=$this->pdo->prepare("SELECT r.*,c.name customer_name FROM customer_license_requests r JOIN customers c ON c.customer_id=r.customer_id WHERE r.request_id=? AND r.status='pending' LIMIT 1");
        $st->execute([$requestId]);$r=$st->fetch();if(!$r)throw new \RuntimeException('LICENSE_REQUEST_NOT_FOUND');
        $customerId=(string)$r['customer_id'];$this->customerById($customerId);$requestType=(string)($r['request_type']??'change');
        $maxInstall=max(1,(int)($_POST['max_installations']??$r['max_installations']??1));
        $maxDf=$this->parseMaxDataforms((string)($_POST['max_dataforms']??($r['max_dataforms']??'')));
        $lease=max(60,(int)($_POST['lease_seconds']??3600));$grace=max($lease,(int)($_POST['grace_seconds']??86400));
        $validUntil=$this->dateEndTimestamp((string)($_POST['valid_until']??''));
        $selectedModules=array_fill_keys(array_map('strval',(array)($_POST['modules']??[])),true);$selectedCaps=array_fill_keys(array_map('strval',(array)($_POST['capabilities']??[])),true);
        $domains=$this->adminNormalizedLines((string)($_POST['approved_domains']??''),'domain');$networks=$this->adminNormalizedLines((string)($_POST['approved_networks']??''),'network');$decision=trim((string)($_POST['decision_note']??''));$now=time();
        $current=$this->pdo->prepare("SELECT * FROM licenses WHERE customer_id=? AND status='active' AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) ORDER BY created_at DESC LIMIT 1");
        $current->execute([$customerId,$now,$now]);$lic=$current->fetch();$newLicense=false;$secret='';$isExtension=$requestType==='extension'&&is_array($lic);$legacyUnrestricted=false;

        // HF108: Eine Erweiterung darf bestehende Freigaben niemals reduzieren.
        if($isExtension){
            $licenseId=(string)$lic['license_id'];
            $maxInstall=max($maxInstall,max(1,(int)($lic['max_installations']??1)));
            $currentPolicy=$this->licenses->policySummary($licenseId);$currentMaxDf=$currentPolicy['max_dataforms']??null;
            if($currentMaxDf===null)$maxDf=null;elseif($maxDf!==null)$maxDf=max((int)$currentMaxDf,$maxDf);
            foreach($this->licenses->modules($licenseId) as $moduleCode)$selectedModules[(string)$moduleCode]=true;
            $currentGlobal=$this->licenses->globalCapabilityMap($licenseId);$legacyUnrestricted=$currentGlobal===null;
            if(is_array($currentGlobal))foreach($currentGlobal as $capabilityCode=>$enabled)if($enabled)$selectedCaps[(string)$capabilityCode]=true;
            if(($lic['valid_until']??null)===null)$validUntil=null;elseif($validUntil!==null)$validUntil=max((int)$lic['valid_until'],$validUntil);
        }

        $this->pdo->beginTransaction();
        try{
            if($lic){
                $licenseId=(string)$lic['license_id'];
                $this->pdo->prepare('UPDATE licenses SET valid_until=?,max_installations=?,lease_seconds=?,grace_seconds=?,generation=generation+1,updated_at=? WHERE license_id=?')->execute([$validUntil,$maxInstall,$lease,$grace,$now,$licenseId]);
            }else{
                $newLicense=true;$licenseId=Id::make('LICID');$number='LIC-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(5)));$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
                $this->pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$licenseId,$number,password_hash($secret,PASSWORD_DEFAULT),$customerId,'active',$now-60,$validUntil,$maxInstall,$lease,$grace,1,$now,$now]);
                $this->pdo->prepare("UPDATE customer_users SET license_id=?,updated_at=? WHERE customer_id=? AND (license_id IS NULL OR license_id='')")->execute([$licenseId,$now,$customerId]);
            }
            if($isExtension){$this->enableModules($licenseId,$selectedModules);$this->extendPolicy($licenseId,$maxDf,$selectedCaps,$legacyUnrestricted);}else{$this->setModules($licenseId,$selectedModules);$this->setPolicy($licenseId,$maxDf,$selectedCaps);}
            foreach($domains as $domain){try{$this->pdo->prepare("INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute([Id::make('DOM'),$customerId,$domain,$now,$now]);}catch(\Throwable){$this->pdo->prepare("UPDATE customer_allowed_domains SET status='active',updated_at=? WHERE customer_id=? AND domain_pattern=?")->execute([$now,$customerId,$domain]);}}
            foreach($networks as $cidr){try{$this->pdo->prepare("INSERT INTO customer_allowed_networks (rule_id,customer_id,cidr,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute([Id::make('NET'),$customerId,$cidr,$now,$now]);}catch(\Throwable){$this->pdo->prepare("UPDATE customer_allowed_networks SET status='active',updated_at=? WHERE customer_id=? AND cidr=?")->execute([$now,$customerId,$cidr]);}}
            $this->pdo->prepare("UPDATE customer_license_requests SET status='approved',reviewed_at=?,reviewed_by_admin_id=?,decision_note=?,resulting_license_id=?,updated_at=? WHERE request_id=? AND status='pending'")->execute([$now,$user['id'],$decision!==''?$decision:null,$licenseId,$now,$requestId]);
            $this->audit->write('LICENSE_REQUEST_APPROVED','license_request',$requestId,'admin',(string)$user['id'],null,[],['license_id'=>$licenseId,'request_type'=>$requestType,'extension_additive'=>$isExtension,'new_license'=>$newLicense,'max_installations'=>$maxInstall,'max_dataforms'=>$maxDf,'modules'=>array_keys($selectedModules)]);$this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        if($newLicense){$lic=$this->licenseById($licenseId);$this->auth->putSecretOnce('license_key_'.$licenseId,(string)$lic['license_number'].'.'.$secret);$this->auth->flash('success','Lizenzanfrage wurde genehmigt und eine neue Lizenz angelegt. Der Aktivierungsschlüssel wird einmalig angezeigt.');}
        elseif($isExtension)$this->auth->flash('success','Lizenzerweiterung wurde genehmigt. Bestehende Freigaben wurden beibehalten und der zusätzliche Umfang aktiviert.');
        else $this->auth->flash('success','Lizenzanfrage wurde genehmigt und die bestehende aktive Lizenz aktualisiert.');
        $this->redirect('license',['id'=>$licenseId]);
    }

    private function rejectLicenseRequest(array $user): never
    {
        $requestId=(string)($_POST['request_id']??'');$now=time();$st=$this->pdo->prepare("UPDATE customer_license_requests SET status='rejected',reviewed_at=?,reviewed_by_admin_id=?,decision_note=?,updated_at=? WHERE request_id=? AND status='pending'");$st->execute([$now,$user['id'],trim((string)($_POST['decision_note']??''))?:null,$now,$requestId]);if($st->rowCount()===0)throw new \RuntimeException('LICENSE_REQUEST_NOT_FOUND');$this->audit->write('LICENSE_REQUEST_REJECTED','license_request',$requestId,'admin',(string)$user['id']);$this->auth->flash('success','Lizenzanfrage wurde abgelehnt.');$this->redirect('license_requests');
    }

    private function renderLicenseRequests(array $user): never
    {
        $pending=$this->pdo->query("SELECT r.*,c.name customer_name,c.company FROM customer_license_requests r JOIN customers c ON c.customer_id=r.customer_id WHERE r.status='pending' ORDER BY r.requested_at ASC")->fetchAll();$recent=$this->pdo->query("SELECT r.*,c.name customer_name FROM customer_license_requests r JOIN customers c ON c.customer_id=r.customer_id WHERE r.status<>'pending' ORDER BY COALESCE(r.reviewed_at,r.updated_at) DESC LIMIT 80")->fetchAll();$modules=$this->moduleRows();$caps=$this->capabilityRows();
        $body='<h1>Lizenzanfragen</h1>'.$this->alerts().'<p>Differenzierte Lizenzwünsche registrierter Kunden. Eine Anfrage ändert den Lizenzstatus erst nach Ihrer ausdrücklichen Freigabe.</p>';
        if(!$pending)$body.='<section><p class="muted">Keine offenen Lizenzanfragen.</p></section>';else foreach($pending as $r){
            $products=$this->jsonList((string)$r['requested_products']);$reqModules=$this->jsonList((string)$r['requested_modules']);$reqCaps=$this->jsonList((string)$r['requested_capabilities']);$domains=$this->jsonList((string)$r['requested_domains']);$networks=$this->jsonList((string)$r['requested_networks']);$term=(int)($r['term_months']??12);$requestType=(string)($r['request_type']??'change');
            $baseTs=time();$currentLicense=null;
            if($requestType==='extension'){$q=$this->pdo->prepare("SELECT * FROM licenses WHERE customer_id=? AND status='active' AND (valid_until IS NULL OR valid_until>=?) ORDER BY created_at DESC LIMIT 1");$q->execute([(string)$r['customer_id'],time()]);$currentLicense=$q->fetch();if(is_array($currentLicense)&&($currentLicense['valid_until']??null)!==null)$baseTs=max($baseTs,(int)$currentLicense['valid_until']);}
            $validDate=$term>0?gmdate('Y-m-d',strtotime('+'.$term.' months',$baseTs)):'';if(is_array($currentLicense)&&($currentLicense['valid_until']??null)===null)$validDate='';
            $typeLabel=$requestType==='extension'?'Erweiterung':($requestType==='new'?'Erstlizenz':'Änderung');
            $body.='<section><div class="title-row"><div><h2>'.self::h($r['customer_key']).'</h2><p><strong>'.self::h($r['customer_name']).'</strong> · Typ '.self::h($typeLabel).' · Antrag '.$this->fmtTime($r['requested_at']).'</p><p>Produkte: '.self::h(implode(', ',$products)?:'–').' · gewünschte Module: '.self::h(implode(', ',$reqModules)?:'–').'</p><p>Kontingent: '.self::h($r['max_installations']).' Installationen · '.self::h($r['max_dataforms']===null?'∞':$r['max_dataforms']).' DataForms · '.self::h($requestType==='extension'?'Verlängerung ':'Laufzeit ').self::h($term===0?'unbefristet':$term.' Monate').'</p>'.($requestType==='extension'?'<p><strong>Additive Erweiterung:</strong> Bereits aktive Module, Funktionen und Kontingente bleiben bei der Freigabe erhalten.</p>':'').(!empty($r['notes'])?'<p><strong>Hinweise:</strong> '.nl2br(self::h($r['notes'])).'</p>':'').'</div>'.$this->badge('pending').'</div>';
            $body.='<form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="approve_license_request"><input type="hidden" name="request_id" value="'.self::h($r['request_id']).'"><div class="grid3"><label>Max. Installationen<input type="number" min="1" name="max_installations" value="'.self::h($r['max_installations']).'"></label><label>Max. DataForms<input type="number" min="0" name="max_dataforms" value="'.self::h($r['max_dataforms']??'').'"></label><label>Gültig bis<input type="date" name="valid_until" value="'.self::h($validDate).'"></label><label>Lease (Sek.)<input type="number" min="60" name="lease_seconds" value="3600"></label><label>Grace (Sek.)<input type="number" min="60" name="grace_seconds" value="86400"></label><label>Entscheidungsnotiz<input name="decision_note"></label></div><h3>Module</h3><div class="checks">';foreach($modules as $m)$body.=$this->check('modules[]',(string)$m['module_code'],(string)$m['name'].' · '.$m['module_code'],in_array((string)$m['module_code'],$reqModules,true));$body.='</div><h3>Funktionen</h3>'.$this->capabilityChecks($caps,$reqCaps).'<div class="grid2"><label>Freizugebende Domains<textarea name="approved_domains" rows="5">'.self::h(implode("\n",$domains)).'</textarea></label><label>Freizugebende IPs / Netze<textarea name="approved_networks" rows="5">'.self::h(implode("\n",$networks)).'</textarea></label></div><button class="primary">'.self::h($requestType==='extension'?'Erweiterung freigeben':'Anfrage freigeben').'</button></form>';
            $body.='<form method="post" class="padtop"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="reject_license_request"><input type="hidden" name="request_id" value="'.self::h($r['request_id']).'"><label>Ablehnungsnotiz<input name="decision_note"></label><button class="warn">Anfrage ablehnen</button></form></section>';
        }
        $body.='<section><h2>Zuletzt bearbeitet</h2><table><thead><tr><th>Kundenschlüssel</th><th>Kunde</th><th>Typ</th><th>Status</th><th>Bearbeitet</th><th>Lizenz</th></tr></thead><tbody>';foreach($recent as $r)$body.='<tr><td>'.self::h($r['customer_key']).'</td><td>'.self::h($r['customer_name']).'</td><td>'.self::h($r['request_type']).'</td><td>'.$this->badge($r['status']).'</td><td>'.$this->fmtTime($r['reviewed_at']??$r['updated_at']).'</td><td>'.self::h($r['resulting_license_id']??'–').'</td></tr>';$body.='</tbody></table></section>';$this->renderAdmin('Lizenzanfragen',$body,$user);
    }

    private function approveProjectProductRequest(array $user): never
    {
        $requestId=(string)($_POST['request_id']??'');
        $st=$this->pdo->prepare("SELECT r.*,p.name product_name,p.loader_module,p.default_modules,p.default_capabilities,c.name customer_name FROM project_product_requests r JOIN customers c ON c.customer_id=r.customer_id JOIN product_catalog p ON p.product_code=r.product_code WHERE r.request_id=? AND r.status='pending' LIMIT 1");$st->execute([$requestId]);$r=$st->fetch();if(!$r)throw new \RuntimeException('PROJECT_PRODUCT_REQUEST_NOT_FOUND');
        $customerId=(string)$r['customer_id'];$now=time();$validUntil=$this->dateEndTimestamp((string)($_POST['valid_until']??''));if($validUntil===null){$months=(int)($r['term_months']??12);$validUntil=$months>0?(new \DateTimeImmutable('@'.$now))->modify('+'.$months.' months')->setTime(23,59,59)->getTimestamp():null;}
        $moduleList=array_values(array_unique(array_merge($this->jsonList((string)($r['default_modules']??'')),$this->jsonList((string)($r['requested_modules']??'')),[(string)$r['loader_module']] )));$modules=array_fill_keys($moduleList,true);
        $capList=array_values(array_unique(array_merge($this->jsonList((string)($r['default_capabilities']??'')),$this->jsonList((string)($r['requested_capabilities']??'')))));$caps=array_fill_keys($capList,true);
        $decision=trim((string)($_POST['decision_note']??''));
        $current=$this->pdo->prepare("SELECT * FROM licenses WHERE customer_id=? AND status='active' AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) ORDER BY created_at DESC LIMIT 1");$current->execute([$customerId,$now,$now]);$lic=$current->fetch();$newLicense=false;$secret='';
        $this->pdo->beginTransaction();
        try{
            if($lic){$licenseId=(string)$lic['license_id'];$existingUntil=$lic['valid_until']!==null?(int)$lic['valid_until']:null;$nextUntil=$existingUntil===null?null:($validUntil===null?$existingUntil:max($existingUntil,$validUntil));$this->pdo->prepare('UPDATE licenses SET valid_until=?,generation=generation+1,updated_at=? WHERE license_id=?')->execute([$nextUntil,$now,$licenseId]);}
            else{$newLicense=true;$licenseId=Id::make('LICID');$number='LIC-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(5)));$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$this->pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$licenseId,$number,password_hash($secret,PASSWORD_DEFAULT),$customerId,'active',$now-60,$validUntil,1,3600,86400,1,$now,$now]);$this->pdo->prepare("UPDATE customer_users SET license_id=?,updated_at=? WHERE customer_id=? AND (license_id IS NULL OR license_id='')")->execute([$licenseId,$now,$customerId]);}
            $this->enableModules($licenseId,$modules);$this->enableCapabilities($licenseId,$caps,(string)$r['product_code']==='dataform'?5:null,!$newLicense);
            $find=$this->pdo->prepare('SELECT entitlement_id FROM project_product_entitlements WHERE customer_id=? AND installation_id=? AND project_id=? AND product_code=? LIMIT 1');$find->execute([$customerId,$r['installation_id'],$r['project_id'],$r['product_code']]);$existing=$find->fetch();
            $entitlementId=$existing?(string)$existing['entitlement_id']:Id::make('PENT');$modulesJson=json_encode(array_keys($modules),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$capsJson=json_encode(array_keys($caps),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($existing)$this->pdo->prepare("UPDATE project_product_entitlements SET license_id=?,project_name=?,project_slug=?,status='active',valid_from=?,valid_until=?,modules=?,capabilities=?,approved_request_id=?,updated_at=? WHERE entitlement_id=?")->execute([$licenseId,$r['project_name'],$r['project_slug'],$now-60,$validUntil,$modulesJson,$capsJson,$requestId,$now,$entitlementId]);
            else $this->pdo->prepare("INSERT INTO project_product_entitlements (entitlement_id,customer_id,license_id,installation_id,project_id,project_name,project_slug,product_code,status,valid_from,valid_until,modules,capabilities,approved_request_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?, 'active',?,?,?,?,?,?,?)")->execute([$entitlementId,$customerId,$licenseId,$r['installation_id'],$r['project_id'],$r['project_name'],$r['project_slug'],$r['product_code'],$now-60,$validUntil,$modulesJson,$capsJson,$requestId,$now,$now]);
            $this->pdo->prepare("UPDATE project_product_requests SET status='approved',reviewed_at=?,reviewed_by_admin_id=?,decision_note=?,resulting_license_id=?,entitlement_id=?,updated_at=? WHERE request_id=? AND status='pending'")->execute([$now,$user['id'],$decision!==''?$decision:null,$licenseId,$entitlementId,$now,$requestId]);
            $this->audit->write('PROJECT_PRODUCT_LICENSE_APPROVED','project_product_request',$requestId,'admin',(string)$user['id'],null,[],['license_id'=>$licenseId,'entitlement_id'=>$entitlementId,'project_id'=>$r['project_id'],'product_code'=>$r['product_code']]);$this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        if($newLicense){$licRow=$this->licenseById($licenseId);$this->auth->putSecretOnce('license_key_'.$licenseId,(string)$licRow['license_number'].'.'.$secret);$this->auth->flash('success','Projektlizenz wurde freigegeben. Eine neue Basislizenz wurde angelegt; der Aktivierungsschlüssel wird einmalig angezeigt.');}
        else $this->auth->flash('success','Projektbezogene Produktfreigabe wurde aktiviert.');
        $this->redirect('project_licenses');
    }

    private function rejectProjectProductRequest(array $user): never
    {
        $requestId=(string)($_POST['request_id']??'');$now=time();$st=$this->pdo->prepare("UPDATE project_product_requests SET status='rejected',reviewed_at=?,reviewed_by_admin_id=?,decision_note=?,updated_at=? WHERE request_id=? AND status='pending'");$st->execute([$now,$user['id'],trim((string)($_POST['decision_note']??''))?:null,$now,$requestId]);if($st->rowCount()===0)throw new \RuntimeException('PROJECT_PRODUCT_REQUEST_NOT_FOUND');$this->audit->write('PROJECT_PRODUCT_LICENSE_REJECTED','project_product_request',$requestId,'admin',(string)$user['id']);$this->auth->flash('success','Projektbezogene Produktanfrage wurde abgelehnt.');$this->redirect('project_licenses');
    }

    private function renderProjectLicenses(array $user): never
    {
        $pending=$this->pdo->query("SELECT r.*,c.name customer_name,c.company,p.name product_name,p.description product_description FROM project_product_requests r JOIN customers c ON c.customer_id=r.customer_id JOIN product_catalog p ON p.product_code=r.product_code WHERE r.status='pending' ORDER BY r.requested_at ASC")->fetchAll();
        $active=$this->pdo->query("SELECT e.*,c.customer_key,c.name customer_name,p.name product_name FROM project_product_entitlements e JOIN customers c ON c.customer_id=e.customer_id JOIN product_catalog p ON p.product_code=e.product_code WHERE e.status='active' ORDER BY c.customer_key,e.project_name,p.sort_order")->fetchAll();
        $body='<h1>Projektlizenzen</h1>'.$this->alerts().'<p>Produktmodule werden pro Enterprise-Installation und Projekt freigegeben. Eine Produktkachel darf erst nach einer aktiven Projektfreigabe produktiv geladen werden.</p>';
        if(!$pending)$body.='<section><p class="muted">Keine offenen Projekt-/Produktanfragen.</p></section>';else foreach($pending as $r){$mods=$this->jsonList((string)$r['requested_modules']);$caps=$this->jsonList((string)$r['requested_capabilities']);$term=(int)($r['term_months']??12);$date=$term>0?gmdate('Y-m-d',strtotime('+'.$term.' months')):'';
            $body.='<section><div class="title-row"><div><h2>'.self::h($r['product_name']).' → '.self::h($r['project_name']).'</h2><p><strong>'.self::h($r['customer_key']).'</strong> · Installation <code>'.self::h($r['installation_id']).'</code> · Projekt-ID <code>'.self::h($r['project_id']).'</code></p><p>'.self::h($r['product_description']??'').'</p><p>Module: '.self::h(implode(', ',$mods)?:'–').' · Funktionen: '.self::h(implode(', ',$caps)?:'–').'</p><p>Hinweis: '.self::h($r['notes']??'–').' · Antrag: '.$this->fmtTime($r['requested_at']).'</p></div>'.$this->badge('pending').'</div>';
            $body.='<form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="approve_project_product_request"><input type="hidden" name="request_id" value="'.self::h($r['request_id']).'"><div class="grid3"><label>Gültig bis<input type="date" name="valid_until" value="'.self::h($date).'"></label><label class="span2">Freigabenotiz<input name="decision_note" placeholder="optional"></label></div><button class="primary">Für dieses Projekt freigeben</button></form>';
            $body.='<form method="post" class="padtop"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="reject_project_product_request"><input type="hidden" name="request_id" value="'.self::h($r['request_id']).'"><label>Ablehnungsnotiz<input name="decision_note"></label><button class="warn">Anfrage ablehnen</button></form></section>';}
        $body.='<section><h2>Aktive Projektfreigaben</h2><table><thead><tr><th>Kunde</th><th>Installation</th><th>Projekt</th><th>Produkt</th><th>Gültig bis</th><th>Lizenz</th></tr></thead><tbody>';if(!$active)$body.='<tr><td colspan="6">Noch keine aktiven Projektfreigaben.</td></tr>';foreach($active as $e)$body.='<tr><td>'.self::h($e['customer_key']).'</td><td><code>'.self::h($e['installation_id']).'</code></td><td>'.self::h($e['project_name']).'<br><code>'.self::h($e['project_id']).'</code></td><td>'.self::h($e['product_name']).'</td><td>'.($e['valid_until']?$this->fmtTime($e['valid_until']):'unbegrenzt').'</td><td><code>'.self::h($e['license_id']).'</code></td></tr>';$body.='</tbody></table></section>';
        $this->renderAdmin('Projektlizenzen',$body,$user);
    }

    private function adminNormalizedLines(string $raw,string $type): array
    {
        $svc=new SurfacePolicyService($this->pdo,$this->cfg['network']??[]);$out=[];foreach(preg_split('/[\\r\\n,;]+/',$raw)?:[] as $line){$line=trim($line);if($line==='')continue;$v=$type==='domain'?$svc->normalizeDomainPattern($line):$svc->normalizeCidr($line);$out[$v]=true;}return array_keys($out);
    }
    private function jsonList(string $json): array{if(trim($json)==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?array_values(array_map('strval',$v)):[];}catch(\Throwable){return[];}}

    private function createCustomer(array $user): never
    {
        $name=trim((string)($_POST['name']??''));if($name==='')throw new \RuntimeException('NAME_REQUIRED');
        $company=trim((string)($_POST['company']??''));$email=strtolower(trim((string)($_POST['email']??'')));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('EMAIL_INVALID');
        $id=Id::make('CUS');$now=time();
        $st=$this->pdo->prepare('INSERT INTO customers (customer_id,customer_number,customer_key,name,company,email,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?)');
        $st->execute([$id,$id,$email,$name,$company!==''?$company:null,$email,'active',$now,$now]);
        $this->audit->write('CUSTOMER_CREATED','customer',$id,'admin',$user['id'],null,[],['customer_key'=>$email,'name'=>$name]);
        $this->auth->flash('success','Kunde '.$email.' wurde angelegt.');$this->redirect('customer',['id'=>$id]);
    }
    private function updateCustomer(array $user): never
    {
        $id=(string)($_POST['customer_id']??'');$old=$this->customerById($id);
        $name=trim((string)($_POST['name']??''));if($name==='')throw new \RuntimeException('NAME_REQUIRED');
        $fields=['company','legal_name','contact_name','email','phone','mobile','website','street','postal_code','city','state_region','country_code','vat_id','billing_email','technical_email','notes'];$v=[];
        foreach($fields as $f)$v[$f]=trim((string)($_POST[$f]??''));
        $v['email']=strtolower($v['email']);if(!filter_var($v['email'],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('EMAIL_INVALID');
        foreach(['billing_email','technical_email'] as $f)if($v[$f]!==''&&!filter_var($v[$f],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('EMAIL_INVALID');
        $status=(string)($_POST['status']??'active');if(!in_array($status,['active','inactive','blocked'],true))throw new \RuntimeException('STATUS_INVALID');
        $domainReq=isset($_POST['surface_domain_required'])?1:0;$ipReq=isset($_POST['surface_ip_required'])?1:0;$selfService=isset($_POST['surface_self_service'])?1:0;
        $oldKey=strtolower(trim((string)($old['customer_key']??$old['email']??'')));$newKey=$v['email'];$now=time();
        if($newKey!==$oldKey){
            $q=$this->pdo->prepare('SELECT customer_id FROM customers WHERE customer_key=? AND customer_id<>? LIMIT 1');$q->execute([$newKey,$id]);if($q->fetch())throw new \RuntimeException('CUSTOMER_KEY_EXISTS');
            $q=$this->pdo->prepare('SELECT customer_user_id FROM customer_users WHERE customer_id<>? AND (LOWER(COALESCE(email,username))=? OR LOWER(username)=?) LIMIT 1');$q->execute([$id,$newKey,$newKey]);if($q->fetch())throw new \RuntimeException('CUSTOMER_LOGIN_EXISTS');
        }
        $sql='UPDATE customers SET customer_key=?,name=?,'.implode(',',array_map(fn($f)=>$f.'=?',$fields)).',status=?,surface_domain_required=?,surface_ip_required=?,surface_self_service=?,updated_at=? WHERE customer_id=?';
        $args=[$newKey,$name];foreach($fields as $f)$args[]=$v[$f]!==''?$v[$f]:null;$args[]=$status;$args[]=$domainReq;$args[]=$ipReq;$args[]=$selfService;$args[]=$now;$args[]=$id;
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare($sql)->execute($args);
            if($newKey!==$oldKey&&$oldKey!=='')$this->pdo->prepare("UPDATE customer_users SET username=?,email=?,updated_at=? WHERE customer_id=? AND role='customer_admin' AND (LOWER(COALESCE(email,username))=? OR LOWER(username)=?)")->execute([$newKey,$newKey,$now,$id,$oldKey,$oldKey]);
            $this->audit->write('CUSTOMER_UPDATED','customer',$id,'admin',$user['id'],null,['name'=>$old['name'],'status'=>$old['status'],'customer_key'=>$old['customer_key']??null],['name'=>$name,'status'=>$status,'customer_key'=>$newKey,'surface_domain_required'=>$domainReq,'surface_ip_required'=>$ipReq,'surface_self_service'=>$selfService]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->auth->flash('success','Kundendaten wurden gespeichert.');$this->redirect('customer',['id'=>$id]);
    }
    private function createBootstrapActivation(array $user): never
    {
        $key=(new BootstrapActivationService($this->pdo,$this->audit))->issue((string)$user['id']);
        $this->auth->putSecretOnce('bootstrap_activation_key',$key);
        $this->auth->flash('success','Ein einmaliger Bootstrap-Aktivierungsschlüssel wurde erzeugt. Er registriert nur die Enterprise-Manager-Installation und benötigt kein Kundenkonto.');
        $this->redirect('dashboard');
    }

    private function createLicense(array $user): never
    {
        $customerId=(string)($_POST['customer_id']??'');$this->customerById($customerId);
        $number=trim((string)($_POST['license_number']??''));if($number==='')$number='LIC-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(5)));
        if(!preg_match('/^[A-Za-z0-9._-]{4,64}$/',$number))throw new \RuntimeException('LICENSE_NUMBER_INVALID');
        $maxInstall=max(1,(int)($_POST['max_installations']??1));$maxDf=$this->parseMaxDataforms((string)($_POST['max_dataforms']??''));
        $lease=max(60,(int)($_POST['lease_seconds']??3600));$grace=max($lease,(int)($_POST['grace_seconds']??86400));
        $validUntil=$this->dateEndTimestamp((string)($_POST['valid_until']??''));if($validUntil===null)$validUntil=time()+365*86400;
        $selectedModules=array_fill_keys(array_map('strval',(array)($_POST['modules']??[])),true);
        $selectedCaps=array_fill_keys(array_map('strval',(array)($_POST['capabilities']??[])),true);
        $id=Id::make('LICID');$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$now=time();
        $this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('INSERT INTO licenses (license_id,license_number,activation_secret_hash,customer_id,status,valid_from,valid_until,max_installations,lease_seconds,grace_seconds,generation,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$id,$number,password_hash($secret,PASSWORD_DEFAULT),$customerId,'active',$now-60,$validUntil,$maxInstall,$lease,$grace,1,$now,$now]);
            $this->setModules($id,$selectedModules);
            $this->setPolicy($id,$maxDf,$selectedCaps);
            $this->pdo->prepare("UPDATE customer_users SET license_id=?,updated_at=? WHERE customer_id=? AND (license_id IS NULL OR license_id='')")->execute([$id,$now,$customerId]);
            $this->audit->write('LICENSE_CREATED','license',$id,'admin',$user['id'],null,[],['license_number'=>$number,'max_installations'=>$maxInstall,'max_dataforms'=>$maxDf]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->auth->putSecretOnce('license_key_'.$id,$number.'.'.$secret);
        $this->auth->flash('success','Lizenz '.$number.' wurde angelegt. Der Aktivierungsschlüssel wird einmalig angezeigt.');
        $this->redirect('license',['id'=>$id]);
    }

    private function updateLicense(array $user): never
    {
        $id=(string)($_POST['license_id']??'');$old=$this->licenseById($id);$status=(string)($_POST['status']??'active');if(!in_array($status,['active','suspended','revoked'],true))throw new \RuntimeException('STATUS_INVALID');
        $validUntil=$this->dateEndTimestamp((string)($_POST['valid_until']??''));$maxInstall=max(1,(int)($_POST['max_installations']??1));$maxDf=$this->parseMaxDataforms((string)($_POST['max_dataforms']??''));$lease=max(60,(int)($_POST['lease_seconds']??3600));$grace=max($lease,(int)($_POST['grace_seconds']??86400));
        $selectedModules=array_fill_keys(array_map('strval',(array)($_POST['modules']??[])),true);$selectedCaps=array_fill_keys(array_map('strval',(array)($_POST['capabilities']??[])),true);$now=time();
        $this->pdo->beginTransaction();
        try{
            $st=$this->pdo->prepare('UPDATE licenses SET status=?,valid_until=?,max_installations=?,lease_seconds=?,grace_seconds=?,generation=generation+1,updated_at=? WHERE license_id=?');
            $st->execute([$status,$validUntil,$maxInstall,$lease,$grace,$now,$id]);
            $this->setModules($id,$selectedModules);$this->setPolicy($id,$maxDf,$selectedCaps);
            $this->audit->write('LICENSE_POLICY_UPDATED','license',$id,'admin',$user['id'],null,['status'=>$old['status'],'generation'=>$old['generation']],['status'=>$status,'max_installations'=>$maxInstall,'max_dataforms'=>$maxDf]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->auth->flash('success','Lizenz und Funktionsumfang wurden gespeichert. Laufende Runtimes erhalten beim nächsten Renew die neue Generation.');$this->redirect('license',['id'=>$id]);
    }

    private function rotateLicenseKey(array $user): never
    {
        $id=(string)($_POST['license_id']??'');$lic=$this->licenseById($id);$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$now=time();
        $st=$this->pdo->prepare('UPDATE licenses SET activation_secret_hash=?,generation=generation+1,updated_at=? WHERE license_id=?');$st->execute([password_hash($secret,PASSWORD_DEFAULT),$now,$id]);
        $this->audit->write('LICENSE_KEY_ROTATED','license',$id,'admin',$user['id']);$this->auth->putSecretOnce('license_key_'.$id,(string)$lic['license_number'].'.'.$secret);
        $this->auth->flash('success','Aktivierungsschlüssel wurde neu erzeugt. Der alte Schlüssel ist ungültig.');$this->redirect('license',['id'=>$id]);
    }

    private function releaseDataform(array $user): never
    {
        $id=(string)($_POST['license_id']??'');$this->licenseById($id);$project=(string)($_POST['project_id']??'');$df=(string)($_POST['dataform_id']??'');if($project===''||$df==='')throw new \RuntimeException('DATAFORM_REQUIRED');
        $reg=$this->pdo->prepare('SELECT dataform_uuid,status FROM license_dataforms WHERE license_id=? AND project_id=? AND local_dataform_id=? LIMIT 1');$reg->execute([$id,$project,$df]);$registered=$reg->fetch();
        if($registered && in_array((string)$registered['status'],['pending','approved'],true))throw new \RuntimeException('DATAFORM_SLOT_BOUND_TO_APPROVAL');
        $released=$this->licenses->releaseDataform($id,$project,$df);$this->pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([time(),$id]);
        $this->audit->write('DATAFORM_LICENSE_SLOT_RELEASED','license',$id,'admin',$user['id'],null,[],['project_id'=>$project,'dataform_id'=>$df,'released'=>$released]);
        $this->auth->flash('success',$released?'DataForm-Lizenzplatz wurde freigegeben.':'DataForm war bereits freigegeben.');$this->redirect('license',['id'=>$id]);
    }

    private function approveRegisteredDataform(array $user): never
    {
        $uuid=(string)($_POST['dataform_uuid']??'');
        $result=$this->dataformRegistry->approve($uuid,(string)$user['id']);
        $pol=(array)($result['license_policy']??[]);
        $msg='DataForm wurde freigegeben und der Lizenzplatz verbindlich belegt.';
        if(($result['already_approved']??false)===true)$msg='DataForm war bereits freigegeben; es wurde kein weiterer Lizenzplatz belegt.';
        if(isset($pol['used_dataforms'],$pol['max_dataforms']))$msg.=' Belegt: '.$pol['used_dataforms'].' / '.($pol['max_dataforms']===null?'∞':$pol['max_dataforms']).'.';
        $this->auth->flash('success',$msg);
        $this->redirect('dataforms');
    }

    private function rejectRegisteredDataform(array $user): never
    {
        $uuid=(string)($_POST['dataform_uuid']??'');
        $note=trim((string)($_POST['decision_note']??''));
        $result=$this->dataformRegistry->reject($uuid,(string)$user['id'],$note);
        $this->auth->flash('success',($result['already_rejected']??false)?'DataForm war bereits abgelehnt.':'DataForm wurde abgelehnt. Ein eventuell aus Phase 1 belegter Legacy-Lizenzplatz wurde freigegeben.');
        $this->redirect('dataforms');
    }

    private function setInstallationStatus(array $user): never
    {
        $iid=(string)($_POST['installation_id']??'');$status=(string)($_POST['status']??'');if($iid===''||!in_array($status,['active','blocked','retired','replaced'],true))throw new \RuntimeException('STATUS_INVALID');
        $result=(new InstallationLifecycleService($this->pdo,$this->audit))->transition($iid,$status,'admin',(string)$user['id']);
        $this->auth->flash('success',($result['changed']??false)?'Installationsstatus wurde auf '.$status.' gesetzt.':'Installationsstatus war bereits '.$status.'.');
        $ret=(string)($_POST['return_page']??'installations');$params=[];if(!empty($_POST['license_id']))$params=['id'=>(string)$_POST['license_id']];$this->redirect($ret,$params);
    }

    private function saveDataformPolicy(array $user): never
    {
        $licenseId=(string)($_POST['license_id']??'');$project=(string)($_POST['project_id']??'');$df=(string)($_POST['dataform_id']??'');$this->licenseById($licenseId);if($project===''||$df==='')throw new \RuntimeException('DATAFORM_REQUIRED');
        $registry=$this->pdo->prepare("SELECT dataform_uuid,status FROM license_dataforms WHERE license_id=? AND project_id=? AND local_dataform_id=? LIMIT 1");$registry->execute([$licenseId,$project,$df]);$registryRow=$registry->fetch();if(!$registryRow||(string)$registryRow['status']!=='approved')throw new \RuntimeException('DATAFORM_POLICY_REQUIRES_APPROVED');
        $global=$this->licenses->globalCapabilityMap($licenseId);$all=$this->capabilityRows();$selected=array_fill_keys(array_map('strval',(array)($_POST['capabilities']??[])),true);$now=time();
        $this->pdo->beginTransaction();
        try{
            $del=$this->pdo->prepare('DELETE FROM license_dataform_capabilities WHERE license_id=? AND project_id=? AND dataform_id=?');$del->execute([$licenseId,$project,$df]);
            $ins=$this->pdo->prepare('INSERT INTO license_dataform_capabilities (license_id,project_id,dataform_id,capability_code,enabled,created_at,updated_at) VALUES (?,?,?,?,?,?,?)');
            foreach($all as $r){$code=(string)$r['capability_code'];$globallyAllowed=$global===null || (($global[$code]??false)===true);if($globallyAllowed && !isset($selected[$code]))$ins->execute([$licenseId,$project,$df,$code,0,$now,$now]);}
            $this->pdo->prepare('UPDATE licenses SET generation=generation+1,updated_at=? WHERE license_id=?')->execute([$now,$licenseId]);
            $this->audit->write('DATAFORM_CAPABILITY_POLICY_UPDATED','license',$licenseId,'admin',$user['id'],null,[],['project_id'=>$project,'dataform_id'=>$df]);$this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $this->auth->flash('success','DataForm-spezifische Funktionsbegrenzung wurde gespeichert.');$this->redirect('dataform_policy',['license'=>$licenseId,'project'=>$project,'dataform'=>$df]);
    }

    private function renderSetup(?string $error=null): never
    {
        $configured=$this->auth->setupTokenConfigured();$tokenFile=(string)(($this->cfg['admin']['setup_token_file']??'') ?: (($this->cfg['app']['base_path']??dirname(__DIR__,2)).'/secure/admin-setup.token'));
        $body='<div class="auth-card"><h1>easyIT Lizenzverwaltung einrichten</h1><p>Es existiert noch kein Administrator.</p>';
        if(!$configured)$body.='<div class="alert error">Das einmalige Setup-Token konnte nicht erzeugt werden. Stelle sicher, dass das Verzeichnis von <code>'.self::h($tokenFile).'</code> für PHP beschreibbar ist, oder setze <code>admin.setup_token</code> außerhalb des Webroots.</div>';
        else $body.='<div class="alert success">Das einmalige Setup-Token ist bereit. Bei automatischer Erzeugung liegt es ausschließlich lokal unter <code>'.self::h($tokenFile).'</code>. Öffne diese Datei über das Dateisystem bzw. den Hosting-Dateimanager und kopiere den Token.</div>';
        $body.='<form method="post" action="?page=setup"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><label>Setup-Token<input type="password" name="setup_token" required autocomplete="off"></label><label>Benutzername<input name="username" required autocomplete="username"></label><label>Anzeigename<input name="display_name"></label><label>Kennwort<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><label>Kennwort wiederholen<input type="password" name="password_confirm" minlength="10" required autocomplete="new-password"></label><button class="primary" type="submit"'.($configured?'':' disabled').'>Superadmin anlegen</button></form><p class="hint">Das Kennwort muss mindestens 10 Zeichen sowie Groß-, Kleinbuchstaben und eine Ziffer enthalten. Das Setup-Token wird nach erfolgreicher Einrichtung automatisch gelöscht.</p></div>';
        $this->renderPublic('Ersteinrichtung',$body,$error);
    }

    private function renderLogin(?string $error=null): never
    {
        $flash=$this->alerts();$body='<div class="auth-card"><h1>easyIT Lizenzverwaltung</h1>'.$flash.'<form method="post" action="?page=login"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><label>Benutzername<input name="username" required autofocus autocomplete="username"></label><label>Kennwort<input type="password" name="password" required autocomplete="current-password"></label><button class="primary" type="submit">Anmelden</button></form></div>';
        $this->renderPublic('Anmeldung',$body,$error);
    }

    private function renderDashboard(array $user): never
    {
        $counts=[
            'customers'=>(int)($this->pdo->query('SELECT COUNT(*) c FROM customers')->fetch()['c']??0),
            'licenses'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM licenses WHERE status='active' AND license_id<>'".BootstrapActivationService::LICENSE_ID."'")->fetch()['c']??0),
            'installations'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM installations WHERE status IN ('active','blocked')")->fetch()['c']??0),
            'runtimes'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM runtime_sessions WHERE status='active'")->fetch()['c']??0),
            'dataforms'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM license_dataform_usage WHERE status='active'")->fetch()['c']??0),
            'requests'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM customer_access_requests WHERE status='pending'")->fetch()['c']??0),
            'license_requests'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM customer_license_requests WHERE status='pending'")->fetch()['c']??0),
            'project_license_requests'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM project_product_requests WHERE status='pending'")->fetch()['c']??0),
            'pending_dataforms'=>(int)($this->pdo->query("SELECT COUNT(*) c FROM license_dataforms WHERE status='pending'")->fetch()['c']??0),
        ];
        $soon=time()+30*86400;$st=$this->pdo->prepare("SELECT l.license_id,l.license_number,l.valid_until,c.name FROM licenses l JOIN customers c ON c.customer_id=l.customer_id WHERE l.status='active' AND l.valid_until IS NOT NULL AND l.valid_until<=? ORDER BY l.valid_until ASC LIMIT 10");$st->execute([$soon]);$exp=$st->fetchAll();
        $body='<h1>Lizenz-Dashboard</h1>'.$this->alerts().'<div class="cards">'.$this->card('Kunden',$counts['customers']).$this->card('Aktive Lizenzen',$counts['licenses']).$this->card('Installationen',$counts['installations']).$this->card('Belegte DataForms',$counts['dataforms']).$this->card('Offene Zugänge',$counts['requests']).$this->card('Lizenzanfragen',$counts['license_requests']).$this->card('Projektlizenzen offen',$counts['project_license_requests']).$this->card('DF-Freigaben offen',$counts['pending_dataforms']).$this->card('Aktive Runtimes',$counts['runtimes']).'</div>';
        $publicKey=$this->signer->publicKeyEncoded();$decoded=base64_decode($publicKey,true);$fingerprint=$decoded===false?'—':strtoupper(implode(':',str_split(hash('sha256',$decoded),2)));
        $body.='<section><h2>Server-Signaturschlüssel</h2><p>Dieser öffentliche Ed25519-Schlüssel ist der Vertrauensanker für Enterprise-Manager. Der private Schlüssel verbleibt ausschließlich auf dem Lizenzserver.</p><div class="grid2"><label>Server-Key-ID<input readonly value="'.self::h($this->signer->keyId()).'"></label><label>SHA-256-Fingerprint<input readonly value="'.self::h($fingerprint).'"></label></div><label>Öffentlicher Ed25519-Schlüssel<textarea readonly rows="4">'.self::h($publicKey).'</textarea></label><p class="hint">Bei einer frischen Installation wird das Schlüsselpaar automatisch beim ersten Serverstart erzeugt. Diesen öffentlichen Wert darfst du in den Enterprise-Manager übernehmen; den privaten Schlüssel niemals kopieren.</p></section>';
        $bootstrapOnce=$this->auth->takeSecretOnce('bootstrap_activation_key');
        $body.='<section><h2>Enterprise-Manager erstmalig aktivieren</h2><p>Der Superadmin benötigt dafür <strong>kein Kundenkonto</strong>. Dieser Schlüssel registriert ausschließlich die Installation und vergibt noch keine Kunden-, Produkt- oder DataForm-Lizenz.</p>';
        if($bootstrapOnce!==null)$body.='<div class="secret"><strong>Recovery-Bootstrap-Aktivierungsschlüssel – wird nur jetzt angezeigt:</strong><code>'.self::h($bootstrapOnce).'</code><p>Nur für Recovery: im Enterprise-Manager unter „Recovery: Installation manuell aktivieren“ verwenden. Die normale Erstinstallation benötigt keinen sichtbaren Aktivierungsschlüssel.</p></div>';
        $body.='<form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="create_bootstrap_activation"><button class="primary" onclick="return confirm(&quot;Einmaligen Bootstrap-Aktivierungsschlüssel erzeugen?&quot;)">Recovery-Bootstrap-Schlüssel erzeugen</button></form><p class="hint">Die normale lokale Erstinstallation aktiviert sich automatisch in einem Schritt. Diese Funktion ist nur für Recovery oder bewusst manuelle Kopplung vorgesehen. Sobald sich auf dieser Installation ein Kunde mit aktiver Lizenz anmeldet, wird die Installation automatisch und kontrolliert an diese Kundenlizenz gebunden. Eine bereits an eine andere Kundenlizenz gebundene Installation wird niemals automatisch umgehängt.</p></section>';
        $body.='<section><h2>Lizenzen mit Ablauf innerhalb 30 Tagen</h2>';
        if(!$exp)$body.='<p class="muted">Keine.</p>';else{$body.='<table><thead><tr><th>Lizenz</th><th>Kunde</th><th>Gültig bis</th></tr></thead><tbody>';foreach($exp as $r)$body.='<tr><td><a href="?page=license&amp;id='.self::u($r['license_id']).'">'.self::h($r['license_number']).'</a></td><td>'.self::h($r['name']).'</td><td>'.$this->fmtTime($r['valid_until']).'</td></tr>';$body.='</tbody></table>';}$body.='</section>';
        $this->renderAdmin('Dashboard',$body,$user);
    }

    private function adminAddDomain(array $user): never{$id=(string)($_POST['customer_id']??'');$this->customerById($id);$svc=new SurfacePolicyService($this->pdo,$this->cfg['network']??[]);$v=$svc->normalizeDomainPattern((string)($_POST['domain']??''));$now=time();try{$this->pdo->prepare('INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,?,?,?)')->execute([Id::make('DOM'),$id,$v,'active',$now,$now]);}catch(\Throwable){$this->pdo->prepare("UPDATE customer_allowed_domains SET status='active',updated_at=? WHERE customer_id=? AND domain_pattern=?")->execute([$now,$id,$v]);}$this->audit->write('CUSTOMER_DOMAIN_ADDED','customer',$id,'admin',$user['id'],null,[],['domain'=>$v]);$this->redirect('customer_surface',['id'=>$id]);}
    private function adminRemoveDomain(array $user): never{$id=(string)($_POST['customer_id']??'');$this->customerById($id);$this->pdo->prepare("UPDATE customer_allowed_domains SET status='removed',updated_at=? WHERE rule_id=? AND customer_id=?")->execute([time(),(string)($_POST['rule_id']??''),$id]);$this->audit->write('CUSTOMER_DOMAIN_REMOVED','customer',$id,'admin',$user['id']);$this->redirect('customer_surface',['id'=>$id]);}
    private function adminAddNetwork(array $user): never{$id=(string)($_POST['customer_id']??'');$this->customerById($id);$svc=new SurfacePolicyService($this->pdo,$this->cfg['network']??[]);$v=$svc->normalizeCidr((string)($_POST['cidr']??''));$now=time();try{$this->pdo->prepare('INSERT INTO customer_allowed_networks (rule_id,customer_id,cidr,status,created_at,updated_at) VALUES (?,?,?,?,?,?)')->execute([Id::make('NET'),$id,$v,'active',$now,$now]);}catch(\Throwable){$this->pdo->prepare("UPDATE customer_allowed_networks SET status='active',updated_at=? WHERE customer_id=? AND cidr=?")->execute([$now,$id,$v]);}$this->audit->write('CUSTOMER_NETWORK_ADDED','customer',$id,'admin',$user['id'],null,[],['cidr'=>$v]);$this->redirect('customer_surface',['id'=>$id]);}
    private function adminRemoveNetwork(array $user): never{$id=(string)($_POST['customer_id']??'');$this->customerById($id);$this->pdo->prepare("UPDATE customer_allowed_networks SET status='removed',updated_at=? WHERE rule_id=? AND customer_id=?")->execute([time(),(string)($_POST['rule_id']??''),$id]);$this->audit->write('CUSTOMER_NETWORK_REMOVED','customer',$id,'admin',$user['id']);$this->redirect('customer_surface',['id'=>$id]);}
    private function createCustomerUser(array $user): never{$id=(string)($_POST['customer_id']??'');$this->customerById($id);$un=strtolower(trim((string)($_POST['username']??'')));$dn=trim((string)($_POST['display_name']??''));$pw=(string)($_POST['password']??'');if(!filter_var($un,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('ACCOUNT_EMAIL_INVALID');if(strlen($pw)<10||!preg_match('/[A-Z]/',$pw)||!preg_match('/[a-z]/',$pw)||!preg_match('/[0-9]/',$pw))throw new \RuntimeException('ADMIN_PASSWORD_WEAK');$ls=$this->pdo->prepare("SELECT license_id FROM licenses WHERE customer_id=? AND status='active' AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) ORDER BY created_at DESC LIMIT 1");$now=time();$ls->execute([$id,$now,$now]);$licenseId=(string)($ls->fetchColumn()?:'');$uid=Id::make('CUSU');$this->pdo->prepare('INSERT INTO customer_users (customer_user_id,customer_id,username,email,license_id,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$uid,$id,$un,$un,$licenseId!==''?$licenseId:null,password_hash($pw,PASSWORD_DEFAULT),$dn!==''?$dn:$un,'customer_admin','active',0,null,null,$now,$now]);$this->audit->write('CUSTOMER_USER_CREATED','customer_user',$uid,'admin',$user['id'],null,[],['customer_id'=>$id,'email'=>$un]);$this->auth->flash('success','Kundenbenutzer wurde angelegt.');$this->redirect('customer_surface',['id'=>$id]);}
    private function setCustomerUserStatus(array $user): never{$id=(string)($_POST['customer_id']??'');$uid=(string)($_POST['customer_user_id']??'');$status=(string)($_POST['status']??'');if(!in_array($status,['active','disabled'],true))throw new \RuntimeException('STATUS_INVALID');$this->pdo->prepare('UPDATE customer_users SET status=?,updated_at=? WHERE customer_user_id=? AND customer_id=?')->execute([$status,time(),$uid,$id]);$this->audit->write('CUSTOMER_USER_STATUS_CHANGED','customer_user',$uid,'admin',$user['id'],null,[],['status'=>$status]);$this->redirect('customer_surface',['id'=>$id]);}
    private function resetCustomerUserPassword(array $user): never{$id=(string)($_POST['customer_id']??'');$uid=(string)($_POST['customer_user_id']??'');$this->customerById($id);$svc=new CustomerCredentialService($this->pdo,$this->audit);$svc->resetPassword($id,$uid,(string)($_POST['new_password']??''),(string)($_POST['new_password_confirm']??''),'admin',(string)$user['id']);$this->auth->flash('success','Kennwort wurde zurückgesetzt. Alle bestehenden Kundenanmeldungen dieses Benutzers wurden widerrufen.');$this->redirect('customer_surface',['id'=>$id]);}

    private function renderCustomers(array $user): never
    {
        $rows=$this->pdo->query('SELECT c.*, (SELECT COUNT(*) FROM licenses l WHERE l.customer_id=c.customer_id) license_count FROM customers c ORDER BY c.created_at DESC')->fetchAll();
        $body='<div class="title-row"><h1>Kunden</h1></div>'.$this->alerts().'<div class="split"><section><h2>Kunde anlegen</h2><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="create_customer"><label>Name<input name="name" required></label><label>Firma<input name="company"></label><label>E-Mail / Kundenschlüssel<input type="email" name="email" required></label><button class="primary">Kunde anlegen</button></form></section><section class="wide"><h2>Vorhandene Kunden</h2><table><thead><tr><th>Kundenschlüssel</th><th>Name</th><th>Firma</th><th>Status</th><th>Lizenzen</th></tr></thead><tbody>';
        foreach($rows as $r)$body.='<tr><td><a href="?page=customer&amp;id='.self::u($r['customer_id']).'">'.self::h((string)($r['customer_key']??$r['customer_number'])).'</a></td><td>'.self::h($r['name']).'</td><td>'.self::h($r['company']??'').'</td><td>'.$this->badge($r['status']).'</td><td>'.(int)$r['license_count'].'</td></tr>';
        $body.='</tbody></table></section></div>';$this->renderAdmin('Kunden',$body,$user);
    }

    private function renderCustomer(array $user): never
    {
        $id=(string)($_GET['id']??'');$r=$this->customerById($id);$st=$this->pdo->prepare('SELECT license_id,license_number,status,valid_until FROM licenses WHERE customer_id=? ORDER BY created_at DESC');$st->execute([$id]);$lics=$st->fetchAll();
        $field=fn(string $name,string $label,string $type='text')=>'<label>'.$label.'<input type="'.$type.'" name="'.$name.'" value="'.self::h($r[$name]??'').'"></label>';
        $body='<div class="title-row"><h1>Kunde '.self::h((string)($r['customer_key']??$r['customer_number'])).'</h1><div><a class="button" href="?page=customer_surface&amp;id='.self::u($id).'">Domains / IP / Kundenlogin</a> <a class="button" href="?page=customers">Zurück</a></div></div>'.$this->alerts().'<section><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="update_customer"><input type="hidden" name="customer_id" value="'.self::h($id).'"><div class="grid2"><label>Name<input name="name" required value="'.self::h($r['name']).'"></label>'.$field('company','Firma / Kurzname').$field('legal_name','Rechtlicher Firmenname').$field('contact_name','Ansprechpartner').$field('email','Allgemeine E-Mail','email').$field('phone','Telefon').$field('mobile','Mobil').$field('website','Website').$field('street','Straße / Hausnummer').$field('postal_code','PLZ').$field('city','Ort').$field('state_region','Bundesland / Region').$field('country_code','Land (ISO-Code)').$field('vat_id','USt-ID').$field('billing_email','Rechnungs-E-Mail','email').$field('technical_email','Technische E-Mail','email').'<label>Status<select name="status">'.$this->options(['active'=>'Aktiv','inactive'=>'Inaktiv','blocked'=>'Gesperrt'],(string)$r['status']).'</select></label></div><label>Interne Notizen<textarea name="notes" rows="4" style="width:100%">'.self::h($r['notes']??'').'</textarea></label><h3>Herkunftsprüfung</h3><div class="checks">'.$this->check('surface_domain_required','1','Domain muss freigegeben sein',(int)($r['surface_domain_required']??1)===1).$this->check('surface_ip_required','1','Quell-IP / Netz muss freigegeben sein',(int)($r['surface_ip_required']??0)===1).$this->check('surface_self_service','1','Kunde darf Domains/IPs im Kundenportal selbst pflegen',(int)($r['surface_self_service']??0)===1).'</div><button class="primary">Speichern</button></form></section><section><h2>Lizenzen</h2><table><thead><tr><th>Lizenz</th><th>Status</th><th>Gültig bis</th></tr></thead><tbody>';
        foreach($lics as $l)$body.='<tr><td><a href="?page=license&amp;id='.self::u($l['license_id']).'">'.self::h($l['license_number']).'</a></td><td>'.$this->badge($l['status']).'</td><td>'.$this->fmtTime($l['valid_until']).'</td></tr>';
        $body.='</tbody></table></section>';$this->renderAdmin('Kunde',$body,$user);
    }

    private function renderCustomerSurface(array $user): never
    {
        $id=(string)($_GET['id']??'');$c=$this->customerById($id);$d=$this->pdo->prepare("SELECT * FROM customer_allowed_domains WHERE customer_id=? AND status='active' ORDER BY domain_pattern");$d->execute([$id]);$domains=$d->fetchAll();$n=$this->pdo->prepare("SELECT * FROM customer_allowed_networks WHERE customer_id=? AND status='active' ORDER BY cidr");$n->execute([$id]);$nets=$n->fetchAll();$u=$this->pdo->prepare('SELECT * FROM customer_users WHERE customer_id=? ORDER BY created_at');$u->execute([$id]);$users=$u->fetchAll();
        $body='<div class="title-row"><div><h1>Herkunft & Kundenportal</h1><p class="muted">'.self::h((string)($c['customer_key']??$c['customer_number']).' – '.$c['name']).'</p></div><a class="button" href="?page=customer&amp;id='.self::u($id).'">Zurück</a></div>'.$this->alerts().'<div class="grid2"><section><h2>Erlaubte Domains</h2><p class="hint">Exakt: <code>example.de</code>, Subdomains: <code>*.example.de</code></p><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="add_domain"><input type="hidden" name="customer_id" value="'.self::h($id).'"><input name="domain" required placeholder="example.de"><button class="primary">Domain hinzufügen</button></form><table><tbody>';
        foreach($domains as $r)$body.='<tr><td>'.self::h($r['domain_pattern']).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="remove_domain"><input type="hidden" name="customer_id" value="'.self::h($id).'"><input type="hidden" name="rule_id" value="'.self::h($r['rule_id']).'"><button>Entfernen</button></form></td></tr>';
        $body.='</tbody></table></section><section><h2>Erlaubte IPs / Netze</h2><p class="hint"><code>203.0.113.10</code> oder <code>203.0.113.0/24</code>; IPv6 wird ebenfalls unterstützt.</p><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="add_network"><input type="hidden" name="customer_id" value="'.self::h($id).'"><input name="cidr" required placeholder="203.0.113.0/24"><button class="primary">IP/Netz hinzufügen</button></form><table><tbody>';
        foreach($nets as $r)$body.='<tr><td>'.self::h($r['cidr']).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="remove_network"><input type="hidden" name="customer_id" value="'.self::h($id).'"><input type="hidden" name="rule_id" value="'.self::h($r['rule_id']).'"><button>Entfernen</button></form></td></tr>';
        $body.='</tbody></table></section></div><section><h2>Kundenportal-Benutzer</h2><p>Kundenportal: <code>/easyit-license-server/customer/</code></p><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="create_customer_user"><input type="hidden" name="customer_id" value="'.self::h($id).'"><div class="grid3"><label>Benutzername<input name="username" required></label><label>Anzeigename<input name="display_name" required></label><label>Initialkennwort<input type="password" name="password" minlength="10" required></label></div><button class="primary">Portal-Benutzer anlegen</button></form><table><thead><tr><th>Benutzer</th><th>Name</th><th>Status</th><th>Letzte Anmeldung</th><th></th></tr></thead><tbody>';
        foreach($users as $r)$body.='<tr><td>'.self::h($r['username']).'</td><td>'.self::h($r['display_name']).'</td><td>'.$this->badge($r['status']).'</td><td>'.$this->fmtTime($r['last_login_at']).'</td><td><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="set_customer_user_status"><input type="hidden" name="customer_id" value="'.self::h($id).'"><input type="hidden" name="customer_user_id" value="'.self::h($r['customer_user_id']).'"><select class="compact" name="status">'.$this->options(['active'=>'Aktiv','disabled'=>'Deaktiviert'],(string)$r['status']).'</select><button class="small">Setzen</button></form><form method="post" class="stacked compact-reset"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="reset_customer_user_password"><input type="hidden" name="customer_id" value="'.self::h($id).'"><input type="hidden" name="customer_user_id" value="'.self::h($r['customer_user_id']).'"><input type="password" name="new_password" minlength="10" placeholder="Neues Kennwort" required><input type="password" name="new_password_confirm" minlength="10" placeholder="Wiederholen" required><button class="small warn">Kennwort zurücksetzen</button></form></td></tr>';
        $body.='</tbody></table></section>';$this->renderAdmin('Herkunft & Kundenportal',$body,$user);
    }

    private function renderLicenses(array $user): never
    {
        $customers=$this->pdo->query("SELECT customer_id,customer_number,customer_key,name FROM customers WHERE status='active' ORDER BY name")->fetchAll();$mods=$this->moduleRows();$caps=$this->capabilityRows();
        $q='SELECT l.*,c.customer_number,c.customer_key,c.name FROM licenses l JOIN customers c ON c.customer_id=l.customer_id ORDER BY l.created_at DESC';$rows=$this->pdo->query($q)->fetchAll();
        $body='<h1>Lizenzen</h1>'.$this->alerts().'<section><details><summary><strong>Neue Lizenz anlegen</strong></summary><form method="post" class="padtop"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="create_license"><div class="grid3"><label>Kunde<select name="customer_id" required><option value="">– auswählen –</option>';foreach($customers as $c)$body.='<option value="'.self::h($c['customer_id']).'">'.self::h((string)($c['customer_key']??$c['customer_number']).' – '.$c['name']).'</option>';$body.='</select></label><label>Lizenznummer (optional)<input name="license_number" placeholder="automatisch"></label><label>Gültig bis<input type="date" name="valid_until" value="'.gmdate('Y-m-d',time()+365*86400).'"></label><label>Max. Installationen<input type="number" min="1" name="max_installations" value="1"></label><label>Max. DataForms<input type="number" min="0" name="max_dataforms" placeholder="leer = unbegrenzt"></label><label>Lease Sekunden<input type="number" min="60" name="lease_seconds" value="3600"></label><label>Grace Sekunden<input type="number" min="60" name="grace_seconds" value="86400"></label></div><h3>Module</h3><div class="checks">';foreach($mods as $m)$body.=$this->check('modules[]',$m['module_code'],$m['name'],in_array($m['module_code'],['dataform','designer'],true));$body.='</div><h3>Funktionen</h3>'.$this->capabilityChecks($caps,array_column($caps,'capability_code')).'<button class="primary">Lizenz erzeugen</button></form></details></section>';
        $body.='<section><h2>Lizenzbestand</h2><table><thead><tr><th>Lizenz</th><th>Kunde</th><th>Status</th><th>DataForms</th><th>Installationen</th><th>Gültig bis</th><th>Aktivierung</th></tr></thead><tbody>';foreach($rows as $r){$pol=$this->licenses->policySummary((string)$r['license_id']);$body.='<tr><td><a href="?page=license&amp;id='.self::u($r['license_id']).'">'.self::h($r['license_number']).'</a></td><td>'.self::h(($r['customer_key']??$r['customer_number']).' – '.$r['name']).'</td><td>'.$this->badge($r['status']).'</td><td>'.$pol['used_dataforms'].' / '.($pol['max_dataforms']===null?'∞':(int)$pol['max_dataforms']).'</td><td>'.(int)$r['max_installations'].'</td><td>'.$this->fmtTime($r['valid_until']).'</td><td><form method="post" class="inline"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="rotate_key"><input type="hidden" name="license_id" value="'.self::h($r['license_id']).'"><button class="small warn" onclick="return confirm(\'Neuen Aktivierungsschlüssel erzeugen? Der bisherige Aktivierungsschlüssel wird sofort ungültig.\')">Schlüssel erzeugen</button></form></td></tr>';}$body.='</tbody></table></section>';
        $this->renderAdmin('Lizenzen',$body,$user);
    }

    private function renderLicense(array $user): never
    {
        $id=(string)($_GET['id']??'');$lic=$this->licenseById($id);$pol=$this->licenses->policySummary($id);$mods=$this->moduleRows();$capRows=$this->capabilityRows();$global=$this->licenses->globalCapabilityMap($id);$enabledCaps=$global===null?array_column($capRows,'capability_code'):array_keys(array_filter($global));
        $st=$this->pdo->prepare('SELECT module_code,enabled FROM license_modules WHERE license_id=?');$st->execute([$id]);$enabledMods=[];foreach($st->fetchAll() as $r)if((int)$r['enabled']===1)$enabledMods[]=(string)$r['module_code'];
        $df=$this->pdo->prepare('SELECT u.project_id,u.dataform_id,u.status,u.first_used_at,u.last_used_at,u.released_at,d.dataform_uuid,d.status registry_status FROM license_dataform_usage u LEFT JOIN license_dataforms d ON d.license_id=u.license_id AND d.project_id=u.project_id AND d.local_dataform_id=u.dataform_id WHERE u.license_id=? ORDER BY u.status,u.project_id,u.dataform_id');$df->execute([$id]);$dataforms=$df->fetchAll();
        $ins=$this->pdo->prepare('SELECT installation_id,status,generation,product_code,product_version,activated_at,last_seen_at FROM installations WHERE license_id=? ORDER BY activated_at DESC');$ins->execute([$id]);$installations=$ins->fetchAll();
        $once=$this->auth->takeSecretOnce('license_key_'.$id);
        $body='<div class="title-row"><div><h1>'.self::h($lic['license_number']).'</h1><p class="muted">'.self::h(($lic['customer_key']??$lic['customer_number']).' – '.$lic['customer_name']).'</p></div><a class="button" href="?page=licenses">Zurück</a></div>'.$this->alerts();
        if($once!==null)$body.='<div class="secret"><strong>Aktivierungsschlüssel – wird nur jetzt angezeigt:</strong><code>'.self::h($once).'</code><p>Jetzt sicher kopieren. Der Server speichert nur den Hash des geheimen Anteils.</p></div>';
        $body.='<div class="cards">'.$this->card('DataForms',$pol['used_dataforms'].' / '.($pol['max_dataforms']===null?'∞':$pol['max_dataforms'])).$this->card('Generation',(string)$lic['generation']).$this->card('Status',(string)$lic['status']).$this->card('Installationen',count(array_filter($installations,fn($r)=>InstallationLifecycleService::consumesSlot((string)$r['status']))).' / '.$lic['max_installations']).'</div>';
        $body.='<section><h2>Lizenz und Funktionsumfang</h2><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="update_license"><input type="hidden" name="license_id" value="'.self::h($id).'"><div class="grid3"><label>Status<select name="status">'.$this->options(['active'=>'Aktiv','suspended'=>'Gesperrt','revoked'=>'Widerrufen'],(string)$lic['status']).'</select></label><label>Gültig bis<input type="date" name="valid_until" value="'.($lic['valid_until']?gmdate('Y-m-d',(int)$lic['valid_until']):'').'"></label><label>Max. Installationen<input type="number" min="1" name="max_installations" value="'.(int)$lic['max_installations'].'"></label><label>Max. DataForms<input type="number" min="0" name="max_dataforms" value="'.($pol['max_dataforms']===null?'':(int)$pol['max_dataforms']).'" placeholder="leer = unbegrenzt"></label><label>Lease Sekunden<input type="number" min="60" name="lease_seconds" value="'.(int)$lic['lease_seconds'].'"></label><label>Grace Sekunden<input type="number" min="60" name="grace_seconds" value="'.(int)$lic['grace_seconds'].'"></label></div><h3>Module</h3><div class="checks">';foreach($mods as $m)$body.=$this->check('modules[]',$m['module_code'],$m['name'],in_array($m['module_code'],$enabledMods,true));$body.='</div><h3>Funktionen / Capabilities</h3>'.$this->capabilityChecks($capRows,$enabledCaps).'<button class="primary">Lizenz speichern</button></form><form method="post" class="inline danger-zone"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="rotate_key"><input type="hidden" name="license_id" value="'.self::h($id).'"><button class="warn" onclick="return confirm(&quot;Neuen Aktivierungsschlüssel erzeugen? Der bisherige Aktivierungsschlüssel wird sofort ungültig.&quot;)">Aktivierungsschlüssel erzeugen</button></form></section>';
        $body.='<section><h2>Verwendete DataForms</h2><p><strong>'.$pol['used_dataforms'].'</strong> belegt, '.($pol['available_dataforms']===null?'unbegrenzt weitere verfügbar':(int)$pol['available_dataforms'].' weitere verfügbar').'.</p><table><thead><tr><th>Projekt</th><th>DataForm</th><th>Status</th><th>Erstverwendung</th><th>Letzte Verwendung</th><th>Aktion</th></tr></thead><tbody>';
        foreach($dataforms as $r){$body.='<tr><td>'.self::h($r['project_id']).'</td><td>'.self::h($r['dataform_id']).'</td><td>'.$this->badge($r['status']).($r['registry_status']?' · Registry '.$this->badge($r['registry_status']):'').'</td><td>'.$this->fmtTime($r['first_used_at']).'</td><td>'.$this->fmtTime($r['last_used_at']).'</td><td>';
            if((string)($r['registry_status']??'')==='approved')$body.='<a class="button small" href="?page=dataform_policy&amp;license='.self::u($id).'&amp;project='.self::u($r['project_id']).'&amp;dataform='.self::u($r['dataform_id']).'">DF-Regeln</a> ';
            elseif((string)($r['registry_status']??'')==='pending')$body.='<a class="button small" href="?page=dataforms">Freigabe offen</a> ';
            if($r['status']==='active'){if(in_array((string)($r['registry_status']??''),['pending','approved'],true)){$body.='<a class="button small" href="?page=dataforms">Registry-gebunden</a>';}else{$body.='<form method="post" class="inline"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="release_dataform"><input type="hidden" name="license_id" value="'.self::h($id).'"><input type="hidden" name="project_id" value="'.self::h($r['project_id']).'"><input type="hidden" name="dataform_id" value="'.self::h($r['dataform_id']).'"><button class="small warn">Platz freigeben</button></form>';}}$body.='</td></tr>';}$body.='</tbody></table></section>';
        $body.='<section><h2>Installationen</h2><table><thead><tr><th>Installation</th><th>Status</th><th>Produkt</th><th>Generation</th><th>Letzter Kontakt</th><th>Aktion</th></tr></thead><tbody>';foreach($installations as $r){$body.='<tr><td><code>'.self::h($r['installation_id']).'</code></td><td>'.$this->badge($r['status']).'</td><td>'.self::h($r['product_code'].' '.($r['product_version']??'')).'</td><td>'.(int)$r['generation'].'</td><td>'.$this->fmtTime($r['last_seen_at']).'</td><td>'.$this->installationStatusForm($r,$id,'license').'</td></tr>';}$body.='</tbody></table></section>';
        $this->renderAdmin('Lizenz',$body,$user);
    }

    private function renderDataformPolicy(array $user): never
    {
        $licenseId=(string)($_GET['license']??'');$project=(string)($_GET['project']??'');$df=(string)($_GET['dataform']??'');$lic=$this->licenseById($licenseId);if($project===''||$df==='')throw new \RuntimeException('DATAFORM_REQUIRED');$reg=$this->pdo->prepare("SELECT dataform_uuid,name,status FROM license_dataforms WHERE license_id=? AND project_id=? AND local_dataform_id=? LIMIT 1");$reg->execute([$licenseId,$project,$df]);$regRow=$reg->fetch();if(!$regRow||(string)$regRow['status']!=='approved')throw new \RuntimeException('DATAFORM_POLICY_REQUIRES_APPROVED');$caps=$this->capabilityRows();$global=$this->licenses->globalCapabilityMap($licenseId);$effective=$this->licenses->allowedCapabilitiesForDataform($licenseId,$project,$df)??array_column($caps,'capability_code');$effMap=array_fill_keys($effective,true);
        $body='<div class="title-row"><div><h1>DataForm-Funktionen</h1><p class="muted">'.self::h($lic['license_number'].' · '.$regRow['name'].' · Projekt '.$project.' · DataForm '.$df).'<br><code>'.self::h((string)$regRow['dataform_uuid']).'</code></p></div><a class="button" href="?page=dataforms">Zurück</a></div>'.$this->alerts().'<section><p>HF86 Phase 2: Die wirksamen Funktionen sind die Schnittmenge aus Kundenlizenz und dieser DataForm-Freigabe. Eine hier gesetzte Freigabe kann eine global gesperrte Funktion <strong>nicht</strong> wieder aktivieren.</p><form method="post"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="save_dataform_policy"><input type="hidden" name="license_id" value="'.self::h($licenseId).'"><input type="hidden" name="project_id" value="'.self::h($project).'"><input type="hidden" name="dataform_id" value="'.self::h($df).'">';
        $last='';foreach($caps as $c){if($last!==$c['category']){if($last!=='')$body.='</div>';$body.='<h3>'.self::h(ucfirst((string)$c['category'])).'</h3><div class="checks">';$last=(string)$c['category'];}$code=(string)$c['capability_code'];$globalAllowed=$global===null || (($global[$code]??false)===true);$checked=$globalAllowed&&isset($effMap[$code]);$body.='<label class="check'.($globalAllowed?'':' disabled').'">'.'<input type="checkbox" name="capabilities[]" value="'.self::h($code).'"'.($checked?' checked':'').($globalAllowed?'':' disabled').'><span><strong>'.self::h($c['name']).'</strong><small>'.self::h($code).($globalAllowed?'':' · global gesperrt').'</small></span></label>';}
        if($last!=='')$body.='</div>';$body.='<button class="primary">DataForm-Regeln speichern</button></form></section>';$this->renderAdmin('DataForm-Funktionen',$body,$user);
    }

    private function renderRegisteredDataforms(array $user): never
    {
        $sql="SELECT d.*,l.license_number,l.status license_status,c.customer_number,c.customer_key,c.name customer_name,
                     u.status usage_status,u.first_used_at,u.last_used_at
              FROM license_dataforms d
              JOIN licenses l ON l.license_id=d.license_id
              JOIN customers c ON c.customer_id=d.customer_id
              LEFT JOIN license_dataform_usage u ON u.license_id=d.license_id AND u.project_id=d.project_id AND u.dataform_id=d.local_dataform_id
              ORDER BY CASE d.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END,d.registered_at ASC";
        $rows=$this->pdo->query($sql)->fetchAll();
        $pending=array_values(array_filter($rows,fn($r)=>(string)$r['status']==='pending'));
        $body='<div class="title-row"><div><h1>DataForm-Freigaben</h1><p class="muted">HF85 Phase 4: Registrierung, manuelle Freigabe, signierter DF-Token und kontrollierte Platzfreigabe beim lokalen Löschen.</p></div></div>'.$this->alerts();
        $body.='<div class="cards">'.$this->card('Offen',count($pending)).$this->card('Registriert',count($rows)).'</div>';
        $body.='<section><h2>Offene DataForms</h2>';
        if(!$pending){$body.='<p class="muted">Keine offenen DataForm-Freigaben.</p>';}
        else {
            $body.='<table><thead><tr><th>Kunde / Lizenz</th><th>DataForm</th><th>Projekt</th><th>Installation</th><th>Registriert</th><th>Lizenzplatz</th><th>Aktion</th></tr></thead><tbody>';
            foreach($pending as $r){
                $policy=$this->licenses->policySummary((string)$r['license_id']);
                $usage=(string)($r['usage_status']??'');
                $slot=$usage==='active'?'bereits durch Phase-1-Legacy belegt':($policy['available_dataforms']===null?'verfügbar (unbegrenzt)':((int)$policy['available_dataforms']>0?'verfügbar':'kein Platz frei'));
                $body.='<tr><td><strong>'.self::h(($r['customer_key']??$r['customer_number']).' – '.$r['customer_name']).'</strong><br><a href="?page=license&amp;id='.self::u($r['license_id']).'">'.self::h($r['license_number']).'</a></td><td><strong>'.self::h($r['name']).'</strong><br><code>'.self::h($r['dataform_uuid']).'</code><br><span class="muted">'.self::h($r['slug']).'</span></td><td>'.self::h($r['project_id']).'<br><span class="muted">lokale ID '.self::h($r['local_dataform_id']).'</span></td><td><code>'.self::h($r['installation_id']).'</code></td><td>'.$this->fmtTime($r['registered_at']).'</td><td>'.self::h($slot).'<br><span class="muted">'.(int)$policy['used_dataforms'].' / '.($policy['max_dataforms']===null?'∞':(int)$policy['max_dataforms']).'</span></td><td>';
                $body.='<form method="post" class="inline"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="approve_dataform"><input type="hidden" name="dataform_uuid" value="'.self::h($r['dataform_uuid']).'"><button class="small primary">Freigeben</button></form> ';
                $body.='<details class="inline"><summary class="button small warn">Ablehnen</summary><form method="post" class="padtop"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="reject_dataform"><input type="hidden" name="dataform_uuid" value="'.self::h($r['dataform_uuid']).'"><label>Notiz<input name="decision_note" maxlength="1000"></label><button class="small warn">Ablehnung bestätigen</button></form></details>';
                $body.='</td></tr>';
            }
            $body.='</tbody></table>';
        }
        $body.='</section><section><h2>Registrierungsbestand</h2><table><thead><tr><th>Status</th><th>Kunde / Lizenz</th><th>DataForm</th><th>Projekt</th><th>Lizenzplatz</th><th>Freigegeben</th><th>Letzter Kontakt</th><th>Funktionen</th></tr></thead><tbody>';
        foreach($rows as $r){
            $policyLink=(string)$r['status']==='approved'?'<a class="button small" href="?page=dataform_policy&amp;license='.self::u($r['license_id']).'&amp;project='.self::u($r['project_id']).'&amp;dataform='.self::u($r['local_dataform_id']).'">DF-Regeln</a>':'<span class="muted">erst nach Freigabe</span>';
            $body.='<tr><td>'.$this->badge($r['status']).'</td><td>'.self::h(($r['customer_key']??$r['customer_number']).' – '.$r['customer_name']).'<br><a href="?page=license&amp;id='.self::u($r['license_id']).'">'.self::h($r['license_number']).'</a></td><td>'.self::h($r['name']).'<br><code>'.self::h($r['dataform_uuid']).'</code></td><td>'.self::h($r['project_id']).' / '.self::h($r['local_dataform_id']).'</td><td>'.$this->badge($r['usage_status']??'none').'</td><td>'.$this->fmtTime($r['approved_at']).'</td><td>'.$this->fmtTime($r['last_seen_at']).'</td><td>'.$policyLink.'</td></tr>';
        }
        $body.='</tbody></table></section>';
        $this->renderAdmin('DataForm-Freigaben',$body,$user);
    }

    private function renderInstallations(array $user): never
    {
        $rows=$this->pdo->query('SELECT i.*,l.license_number,l.customer_id,c.name customer_name FROM installations i JOIN licenses l ON l.license_id=i.license_id LEFT JOIN customers c ON c.customer_id=l.customer_id ORDER BY i.activated_at DESC')->fetchAll();
        $body='<h1>Installationen</h1>'.$this->alerts().'<section><table><thead><tr><th>Installation</th><th>Lizenz</th><th>Kunde</th><th>Status</th><th>Produkt</th><th>Letzter Kontakt</th><th>Aktion</th></tr></thead><tbody>';foreach($rows as $r){$bootstrap=BootstrapActivationService::isBootstrapLicense((string)$r['license_id']);$licenseCell=$bootstrap?'<span class="badge neutral">Bootstrap</span>':('<a href="?page=license&amp;id='.self::u($r['license_id']).'">'.self::h($r['license_number']).'</a>');$customerCell=$bootstrap?'noch nicht gebunden':self::h($r['customer_name']??'—');$body.='<tr><td><code>'.self::h($r['installation_id']).'</code></td><td>'.$licenseCell.'</td><td>'.$customerCell.'</td><td>'.$this->badge($r['status']).'</td><td>'.self::h($r['product_code'].' '.($r['product_version']??'')).'</td><td>'.$this->fmtTime($r['last_seen_at']).'</td><td>'.$this->installationStatusForm($r,null,'installations').'</td></tr>';}$body.='</tbody></table></section>';$this->renderAdmin('Installationen',$body,$user);
    }

    private function renderAudit(array $user): never
    {
        $rows=$this->pdo->query('SELECT * FROM audit_events ORDER BY created_at DESC LIMIT 300')->fetchAll();
        $body='<h1>Audit</h1>'.$this->alerts().'<section><p class="muted">Die letzten 300 sicherheits- und lizenzrelevanten Ereignisse. Geheimschlüssel und Datensatzinhalte werden hier nicht gespeichert.</p><table><thead><tr><th>Zeit</th><th>Ereignis</th><th>Objekt</th><th>Akteur</th><th>Details</th></tr></thead><tbody>';foreach($rows as $r){$details=$r['new_value']??$r['old_value']??'';$body.='<tr><td>'.$this->fmtTime($r['created_at']).'</td><td><code>'.self::h($r['event_type']).'</code></td><td>'.self::h($r['entity_type'].' '.($r['entity_id']??'')).'</td><td>'.self::h($r['actor_type'].' '.($r['actor_id']??'')).'</td><td><span class="audit-detail">'.self::h((string)$details).'</span></td></tr>';}$body.='</tbody></table></section>';$this->renderAdmin('Audit',$body,$user);
    }

    private function renderNotFound(array $user): never{$this->renderAdmin('Nicht gefunden','<h1>Nicht gefunden</h1><p>Die Verwaltungsseite existiert nicht.</p>',$user,404);}
    private function renderError(array $user,string $message): never{$this->renderAdmin('Fehler','<h1>Fehler</h1><div class="alert error">'.self::h($message).'</div>',$user,400);}

    private function renderAdmin(string $title,string $body,array $user,int $status=200): never
    {
        http_response_code($status);header('Content-Type:text/html; charset=utf-8');$nav=[['dashboard','Dashboard'],['access_requests','Zugänge'],['license_requests','Lizenzanfragen'],['project_licenses','Projektlizenzen'],['customers','Kunden'],['licenses','Lizenzen'],['dataforms','DataForms'],['installations','Installationen'],['audit','Audit']];
        echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::h($title).' · easyIT Lizenzverwaltung</title>'.$this->style().'</head><body><header><div class="brand">easyIT <span>License/DataForm Server</span></div><div class="user">'.self::h($user['display_name']).'<form method="post" action="?page=logout" class="inline"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><button class="linkbtn">Abmelden</button></form></div></header><div class="shell"><nav>';
        foreach($nav as [$p,$label])echo '<a'.(((string)($_GET['page']??'dashboard')===$p)?' class="active"':'').' href="?page='.$p.'">'.self::h($label).'</a>';
        echo '</nav><main>'.$body.'</main></div></body></html>';exit;
    }

    private function renderPublic(string $title,string $body,?string $error=null): never
    {
        http_response_code(200);header('Content-Type:text/html; charset=utf-8');echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::h($title).'</title>'.$this->style().'</head><body class="auth"><main>'.($error?'<div class="alert error">'.self::h($error).'</div>':'').$body.'</main></body></html>';exit;
    }

    private function enableModules(string $licenseId,array $selected): void
    {
        $find=$this->pdo->prepare('SELECT module_code FROM license_modules WHERE license_id=? AND module_code=?');$ins=$this->pdo->prepare('INSERT INTO license_modules (license_id,module_code,enabled,valid_from,valid_until,configuration) VALUES (?,?,?,?,?,?)');$up=$this->pdo->prepare('UPDATE license_modules SET enabled=1 WHERE license_id=? AND module_code=?');
        foreach(array_keys($selected) as $code){$find->execute([$licenseId,$code]);if($find->fetch())$up->execute([$licenseId,$code]);else$ins->execute([$licenseId,$code,1,null,null,null]);}
    }

    private function enableCapabilities(string $licenseId,array $selectedCaps,?int $defaultMaxDataforms=null,bool $preserveLegacyUnrestricted=false): void
    {
        $now=time();$st=$this->pdo->prepare('SELECT max_dataforms FROM license_policies WHERE license_id=? LIMIT 1');$st->execute([$licenseId]);$row=$st->fetch();if(!$row)$this->pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)')->execute([$licenseId,$defaultMaxDataforms,$now,$now]);elseif($defaultMaxDataforms!==null&&$row['max_dataforms']===null)$this->pdo->prepare('UPDATE license_policies SET max_dataforms=?,updated_at=? WHERE license_id=?')->execute([$defaultMaxDataforms,$now,$licenseId]);
        if($preserveLegacyUnrestricted){$cnt=$this->pdo->prepare('SELECT COUNT(*) FROM license_capabilities WHERE license_id=?');$cnt->execute([$licenseId]);if((int)$cnt->fetchColumn()===0)return;}
        $find=$this->pdo->prepare('SELECT capability_code FROM license_capabilities WHERE license_id=? AND capability_code=?');$ins=$this->pdo->prepare('INSERT INTO license_capabilities (license_id,capability_code,enabled,created_at,updated_at) VALUES (?,?,?,?,?)');$up=$this->pdo->prepare('UPDATE license_capabilities SET enabled=1,updated_at=? WHERE license_id=? AND capability_code=?');
        foreach(array_keys($selectedCaps) as $code){$find->execute([$licenseId,$code]);if($find->fetch())$up->execute([$now,$licenseId,$code]);else$ins->execute([$licenseId,$code,1,$now,$now]);}
    }

    private function extendPolicy(string $licenseId,?int $maxDf,array $selectedCaps,bool $legacyUnrestricted): void
    {
        $now=time();$sel=$this->pdo->prepare('SELECT license_id FROM license_policies WHERE license_id=?');$sel->execute([$licenseId]);if($sel->fetch())$this->pdo->prepare('UPDATE license_policies SET max_dataforms=?,updated_at=? WHERE license_id=?')->execute([$maxDf,$now,$licenseId]);else$this->pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)')->execute([$licenseId,$maxDf,$now,$now]);
        if($legacyUnrestricted)return;
        $this->enableCapabilities($licenseId,$selectedCaps,null,false);
    }

    private function setModules(string $licenseId,array $selected): void
    {
        $rows=$this->moduleRows();$sel=$this->pdo->prepare('SELECT module_code FROM license_modules WHERE license_id=? AND module_code=?');$ins=$this->pdo->prepare('INSERT INTO license_modules (license_id,module_code,enabled,valid_from,valid_until,configuration) VALUES (?,?,?,?,?,?)');$up=$this->pdo->prepare('UPDATE license_modules SET enabled=? WHERE license_id=? AND module_code=?');
        foreach($rows as $r){$code=(string)$r['module_code'];$enabled=isset($selected[$code])?1:0;$sel->execute([$licenseId,$code]);if($sel->fetch())$up->execute([$enabled,$licenseId,$code]);else$ins->execute([$licenseId,$code,$enabled,null,null,null]);}
    }

    private function setPolicy(string $licenseId,?int $maxDf,array $selectedCaps): void
    {
        $now=time();$sel=$this->pdo->prepare('SELECT license_id FROM license_policies WHERE license_id=?');$sel->execute([$licenseId]);if($sel->fetch())$this->pdo->prepare('UPDATE license_policies SET max_dataforms=?,updated_at=? WHERE license_id=?')->execute([$maxDf,$now,$licenseId]);else$this->pdo->prepare('INSERT INTO license_policies (license_id,max_dataforms,created_at,updated_at) VALUES (?,?,?,?)')->execute([$licenseId,$maxDf,$now,$now]);
        $find=$this->pdo->prepare('SELECT capability_code FROM license_capabilities WHERE license_id=? AND capability_code=?');$ins=$this->pdo->prepare('INSERT INTO license_capabilities (license_id,capability_code,enabled,created_at,updated_at) VALUES (?,?,?,?,?)');$up=$this->pdo->prepare('UPDATE license_capabilities SET enabled=?,updated_at=? WHERE license_id=? AND capability_code=?');
        foreach($this->capabilityRows() as $r){$code=(string)$r['capability_code'];$enabled=isset($selectedCaps[$code])?1:0;$find->execute([$licenseId,$code]);if($find->fetch())$up->execute([$enabled,$now,$licenseId,$code]);else$ins->execute([$licenseId,$code,$enabled,$now,$now]);}
    }

    private function licenseById(string $id): array
    {
        $st=$this->pdo->prepare('SELECT l.*,c.customer_number,c.customer_key,c.name customer_name FROM licenses l JOIN customers c ON c.customer_id=l.customer_id WHERE l.license_id=? LIMIT 1');$st->execute([$id]);$r=$st->fetch();if(!$r)throw new \RuntimeException('LICENSE_NOT_FOUND');return $r;
    }
    private function customerById(string $id): array{$st=$this->pdo->prepare('SELECT * FROM customers WHERE customer_id=? LIMIT 1');$st->execute([$id]);$r=$st->fetch();if(!$r)throw new \RuntimeException('CUSTOMER_NOT_FOUND');return$r;}
    private function moduleRows(): array{return $this->pdo->query('SELECT module_code,name FROM modules WHERE active=1 ORDER BY module_code')->fetchAll();}
    private function capabilityRows(): array{return $this->pdo->query('SELECT capability_code,name,category FROM capability_registry WHERE active=1 ORDER BY category,capability_code')->fetchAll();}
    private function parseMaxDataforms(string $v): ?int{$v=trim($v);if($v==='')return null;if(!preg_match('/^\d+$/',$v))throw new \RuntimeException('MAX_DATAFORMS_INVALID');return(int)$v;}
    private function dateEndTimestamp(string $date): ?int{if(trim($date)==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d')!==$date)throw new \RuntimeException('DATE_INVALID');return$d->setTime(23,59,59)->getTimestamp();}

    private function installationStatusForm(array $r,?string $licenseId,string $returnPage): string
    {
        $status=(string)($r['status']??'');if(in_array($status,['retired','replaced'],true))return '<span class="muted">terminal · keine Reaktivierung</span>';
        $html='<form method="post" class="inline"><input type="hidden" name="csrf" value="'.self::h($this->auth->csrf()).'"><input type="hidden" name="action" value="set_installation_status"><input type="hidden" name="installation_id" value="'.self::h($r['installation_id']).'"><input type="hidden" name="return_page" value="'.self::h($returnPage).'">';if($licenseId!==null)$html.='<input type="hidden" name="license_id" value="'.self::h($licenseId).'">';$html.='<select name="status" class="compact">'.$this->options(['active'=>'Aktiv','blocked'=>'Gesperrt','retired'=>'Stillgelegt','replaced'=>'Ersetzt'],$status).'</select><button class="small">Setzen</button></form>';return$html;
    }

    private function capabilityChecks(array $caps,array $enabled): string
    {
        $map=array_fill_keys($enabled,true);$html='';$last='';foreach($caps as $c){if($last!==$c['category']){if($last!=='')$html.='</div>';$last=(string)$c['category'];$html.='<h4>'.self::h(ucfirst($last)).'</h4><div class="checks">';}$html.=$this->check('capabilities[]',$c['capability_code'],$c['name'].' · '.$c['capability_code'],isset($map[$c['capability_code']]));}if($last!=='')$html.='</div>';return$html;
    }
    private function check(string $name,string $value,string $label,bool $checked): string{return'<label class="check"><input type="checkbox" name="'.self::h($name).'" value="'.self::h($value).'"'.($checked?' checked':'').'><span>'.self::h($label).'</span></label>';}
    private function card(string $title,mixed $value): string{return'<div class="card"><span>'.self::h($title).'</span><strong>'.self::h($value).'</strong></div>';}
    private function badge(?string $status): string{$s=(string)$status;$cls=in_array($s,['active','completed','published','approved'],true)?'ok':(in_array($s,['blocked','revoked','suspended','rejected'],true)?'bad':'neutral');return'<span class="badge '.$cls.'">'.self::h($s).'</span>';}
    private function fmtTime(mixed $v): string{if(!$v)return'–';return gmdate('Y-m-d H:i:s',(int)$v).' UTC';}
    private function options(array $options,string $selected): string{$h='';foreach($options as $v=>$l)$h.='<option value="'.self::h($v).'"'.($v===$selected?' selected':'').'>'.self::h($l).'</option>';return$h;}
    private function alerts(): string{$html='';foreach($this->auth->consumeFlash() as $f)$html.='<div class="alert '.self::h($f['type']).'">'.self::h($f['message']).'</div>';return$html;}
    private function returnParams(): array{$out=[];foreach(['id','license','project','dataform'] as $k)if(isset($_POST[$k]))$out[$k]=(string)$_POST[$k];if(isset($_POST['license_id'])&&!isset($out['id']))$out['id']=(string)$_POST['license_id'];if(isset($_POST['customer_id'])&&!isset($out['id']))$out['id']=(string)$_POST['customer_id'];if(isset($_POST['project_id'])&&!isset($out['project']))$out['project']=(string)$_POST['project_id'];if(isset($_POST['dataform_id'])&&!isset($out['dataform']))$out['dataform']=(string)$_POST['dataform_id'];return$out;}

    private function redirect(string $page,array $params=[]): never{$params=['page'=>$page]+$params;header('Location: '.$this->base.'/?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),true,303);exit;}
    private function publicError(string $code): string{return match($code){'ADMIN_SETUP_TOKEN_INVALID'=>'Setup-Token ist ungültig.','ADMIN_PASSWORD_MISMATCH'=>'Die Kennwörter stimmen nicht überein.','ADMIN_PASSWORD_TOO_SHORT'=>'Das Kennwort muss mindestens 10 Zeichen lang sein.','ADMIN_PASSWORD_WEAK'=>'Das Kennwort benötigt Groß-, Kleinbuchstaben und mindestens eine Ziffer.','ADMIN_USERNAME_INVALID'=>'Der Benutzername ist ungültig.','ADMIN_CSRF_INVALID'=>'Die Sitzung ist abgelaufen oder die Formularanfrage ist ungültig. Bitte Seite neu laden.','NAME_REQUIRED'=>'Name ist erforderlich.','EMAIL_INVALID'=>'Die E-Mail-Adresse ist ungültig.','CUSTOMER_NOT_FOUND'=>'Kunde wurde nicht gefunden.','LICENSE_NOT_FOUND'=>'Lizenz wurde nicht gefunden.','STATUS_INVALID'=>'Status ist ungültig.','MAX_DATAFORMS_INVALID'=>'Die maximale DataForm-Anzahl ist ungültig.','DATE_INVALID'=>'Das Datum ist ungültig.','DATAFORM_REQUIRED'=>'Projekt und DataForm sind erforderlich.','DATAFORM_REGISTRATION_NOT_FOUND'=>'Die DataForm-Registrierung wurde nicht gefunden.','DATAFORM_STATUS_NOT_PENDING'=>'Das DataForm ist nicht mehr im Status pending.','DATAFORM_STATUS_CONFLICT'=>'Der DataForm-Status wurde parallel geändert. Bitte Seite neu laden.','DATAFORM_SLOT_BOUND_TO_APPROVAL'=>'Dieser Lizenzplatz ist an die zentrale DataForm-Freigabe gebunden. Verwenden Sie die DataForm-Verwaltung.','DATAFORM_LIMIT_REACHED'=>'Für diese Lizenz ist kein freier DataForm-Platz vorhanden.','DATAFORM_UUID_INVALID'=>'Die DataForm-UUID ist ungültig.','DATAFORM_POLICY_REQUIRES_APPROVED'=>'DataForm-spezifische Funktionen können erst nach der zentralen Freigabe gepflegt werden.','INSTALLATION_NOT_FOUND'=>'Installation wurde nicht gefunden.','LICENSE_NUMBER_INVALID'=>'Die Lizenznummer enthält ungültige Zeichen.','SURFACE_DOMAIN_INVALID'=>'Die Domain ist ungültig.','SURFACE_IP_INVALID'=>'Die IP-/Netzangabe ist ungültig.','ACCESS_REQUEST_NOT_FOUND'=>'Die Zugangsanforderung wurde nicht gefunden oder bereits bearbeitet.','LICENSE_REQUEST_NOT_FOUND'=>'Die Lizenzanfrage wurde nicht gefunden oder bereits bearbeitet.','PROJECT_PRODUCT_REQUEST_NOT_FOUND'=>'Die projektbezogene Produktanfrage wurde nicht gefunden oder bereits bearbeitet.','ACCOUNT_PASSWORD_MISMATCH'=>'Die Kennwörter stimmen nicht überein.','ACCOUNT_PASSWORD_WEAK'=>'Das Kennwort muss mindestens 10 Zeichen, Groß- und Kleinbuchstaben sowie eine Ziffer enthalten.','ACCOUNT_USER_NOT_FOUND'=>'Der Kundenbenutzer wurde nicht gefunden.','ADMIN_ACTION_INVALID'=>'Diese Verwaltungsaktion ist nicht zulässig.',default=>'Die Aktion konnte nicht ausgeführt werden. ('.preg_replace('/[^A-Z0-9_]/','',$code).')'};}
    private function securityHeaders(): void{header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: no-referrer');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");header('Cache-Control: no-store, max-age=0');}
    private static function h(mixed $v): string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    private static function u(mixed $v): string{return rawurlencode((string)$v);}

    private function style(): string{return <<<'HTML'
<style>
:root{font-family:Arial,Helvetica,sans-serif;color:#20242a;background:#f5f6f8}*{box-sizing:border-box}body{margin:0;background:#f5f6f8}header{height:58px;background:#17202a;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:0 24px}.brand{font-size:20px;font-weight:700}.brand span{font-weight:400;color:#cbd5df}.user{display:flex;align-items:center;gap:14px}.shell{display:grid;grid-template-columns:210px 1fr;min-height:calc(100vh - 58px)}nav{background:#fff;border-right:1px solid #dfe3e8;padding:20px 10px}nav a{display:block;padding:10px 12px;margin:2px 0;border-radius:6px;color:#28323c;text-decoration:none}nav a:hover,nav a.active{background:#e9eef4;font-weight:700}main{padding:26px;max-width:1500px;width:100%}h1{margin-top:0}h2{margin-top:0}section{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:20px;margin:18px 0;overflow:auto}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}.card{background:#fff;border:1px solid #dfe3e8;border-radius:8px;padding:18px}.card span{display:block;color:#6a737d;font-size:13px}.card strong{display:block;font-size:26px;margin-top:7px}.split{display:grid;grid-template-columns:minmax(280px,1fr) minmax(500px,2fr);gap:18px}.split section{margin:0}.wide{min-width:0}.grid2,.grid3{display:grid;grid-template-columns:repeat(2,minmax(200px,1fr));gap:14px}.grid3{grid-template-columns:repeat(3,minmax(180px,1fr))}label{display:block;font-weight:600;font-size:14px;margin-bottom:12px}input,select,textarea{width:100%;padding:9px 10px;border:1px solid #b9c0c8;border-radius:5px;background:#fff;font:inherit;margin-top:5px}textarea{resize:vertical;min-height:80px}select.compact{display:inline-block;width:auto;margin:0;padding:5px 6px}button,.button{display:inline-block;border:1px solid #9aa4ad;border-radius:5px;padding:8px 12px;background:#fff;color:#17202a;text-decoration:none;font:inherit;cursor:pointer}button.primary,.button.primary{background:#1f5f99;color:#fff;border-color:#1f5f99}button.warn{background:#fff6e8;border-color:#d9a148;color:#7a4a00}button.small,.button.small{padding:5px 8px;font-size:12px}.linkbtn{background:transparent;border:0;color:#fff;padding:4px}.inline{display:inline}.title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:18px}.checks{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:8px 12px;margin:8px 0 16px}.check{display:flex;gap:9px;align-items:flex-start;border:1px solid #dfe3e8;border-radius:6px;padding:9px;background:#fafbfc;font-weight:400;margin:0}.check input{width:auto;margin:2px 0 0}.check span{display:block}.check small{display:block;color:#6a737d;margin-top:2px}.check.disabled{opacity:.55}.alert{border-radius:6px;padding:11px 13px;margin:10px 0}.alert.success{background:#eaf7ee;color:#145a2d;border:1px solid #a7dbb8}.alert.error{background:#fff0f0;color:#8a1c1c;border:1px solid #e5b1b1}.secret{background:#fff8dc;border:2px solid #d5aa2a;padding:16px;border-radius:8px;margin:15px 0}.secret code{display:block;padding:10px;background:#fff;margin:10px 0;word-break:break-all}.badge{display:inline-block;padding:3px 7px;border-radius:999px;font-size:12px;background:#e6e9ed}.badge.ok{background:#dff4e5;color:#155c2b}.badge.bad{background:#f8dddd;color:#8e2020}.badge.neutral{background:#e8ebef;color:#48515a}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:9px 8px;border-bottom:1px solid #e2e5e8;vertical-align:top}th{color:#505861;background:#fafbfc}code{background:#eef1f4;padding:2px 5px;border-radius:4px}.muted,.hint{color:#68717b}.audit-detail{display:block;max-width:460px;word-break:break-word;font-family:monospace;font-size:12px}.danger-zone{margin-left:10px}.padtop{padding-top:16px}details summary{cursor:pointer}.auth{display:flex;min-height:100vh;align-items:flex-start;justify-content:center;padding-top:9vh}.auth main{max-width:520px}.auth-card{background:#fff;border:1px solid #dfe3e8;border-radius:10px;padding:28px;box-shadow:0 8px 28px rgba(0,0,0,.08)}
@media(max-width:900px){.shell{grid-template-columns:1fr}nav{border-right:0;border-bottom:1px solid #dfe3e8;display:flex;flex-wrap:wrap;gap:4px}.split,.grid2,.grid3{grid-template-columns:1fr}main{padding:15px}header{padding:0 14px}.brand span{display:none}}
</style>
HTML;}
}
