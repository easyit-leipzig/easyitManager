<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use EasyIT\LicenseServer\Support\Id;
use PDO;

final class AccountService
{
    public function __construct(
        private PDO $pdo,
        private LicenseService $licenses,
        private AuditService $audit,
        private SurfacePolicyService $surface
    ) {}

    public function requestAccess(array $data, string $requestId=''): array
    {
        return $this->register($data,$requestId);
    }

    public function register(array $data, string $requestId=''): array
    {
        $email=$this->normalizeEmail((string)($data['email']??''));
        $company=trim((string)($data['company']??''));
        $first=trim((string)($data['first_name']??''));
        $last=trim((string)($data['last_name']??''));
        $phone=trim((string)($data['phone']??''));
        $password=(string)($data['password']??'');
        $password2=(string)($data['password_confirm']??'');
        if($company==='')throw new \RuntimeException('ACCOUNT_COMPANY_REQUIRED');
        if($first===''||$last==='')throw new \RuntimeException('ACCOUNT_NAME_REQUIRED');
        if($password!==$password2)throw new \RuntimeException('ACCOUNT_PASSWORD_MISMATCH');
        $this->assertPassword($password);

        $surface=(array)($data['_surface']??[]);
        $domainRaw=trim((string)($data['requested_domain']??''));
        if($domainRaw===''&&!empty($surface['host']))$domainRaw=(string)$surface['host'];
        $domain=$domainRaw!==''?$this->surface->normalizeDomainPattern($domainRaw):null;

        $existing=$this->pdo->prepare('SELECT customer_user_id FROM customer_users WHERE LOWER(COALESCE(email,username))=? OR LOWER(username)=? LIMIT 1');
        $existing->execute([$email,$email]);
        if($existing->fetch())throw new \RuntimeException('ACCOUNT_ALREADY_EXISTS');
        $existingCustomer=$this->pdo->prepare('SELECT customer_id FROM customers WHERE customer_key=? LIMIT 1');
        $existingCustomer->execute([$email]);
        if($existingCustomer->fetch())throw new \RuntimeException('ACCOUNT_ALREADY_EXISTS');

        $now=time();$remote=(string)($_SERVER['REMOTE_ADDR']??'');$ipHash=$remote!==''?hash('sha256',$remote):null;
        if($ipHash!==null){
            $rl=$this->pdo->prepare('SELECT COUNT(*) c FROM customer_access_requests WHERE request_ip_hash=? AND requested_at>=?');
            $rl->execute([$ipHash,$now-3600]);
            if((int)($rl->fetch()['c']??0)>=5)throw new \RuntimeException('ACCOUNT_REQUEST_RATE_LIMIT');
        }

        $customerId=Id::make('CUS');$customerUserId=Id::make('CUSU');$registrationId=Id::make('REG');
        $display=trim($first.' '.$last);$passwordHash=password_hash($password,PASSWORD_DEFAULT);
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO customers (customer_id,customer_number,customer_key,name,company,email,status,legal_name,contact_name,phone,surface_domain_required,surface_ip_required,surface_self_service,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$customerId,$customerId,$email,$company!==''?$company:$display,$company,$email,'active',$company,$display,$phone!==''?$phone:null,1,0,1,$now,$now]);
            $this->pdo->prepare("INSERT INTO customer_users (customer_user_id,customer_id,username,email,license_id,password_hash,display_name,role,status,failed_attempts,locked_until,last_login_at,created_at,updated_at) VALUES (?,?,?,?,NULL,?,?,?,'active',0,NULL,NULL,?,?)")
                ->execute([$customerUserId,$customerId,$email,$email,$passwordHash,$display,'customer_admin',$now,$now]);
            $this->roles()->ensureDefaults($customerId);
            if($domain!==null){
                $this->pdo->prepare("INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")
                    ->execute([Id::make('DOM'),$customerId,$domain,$now,$now]);
            }
            $this->pdo->prepare("UPDATE customer_access_requests SET status='superseded',updated_at=? WHERE email=? AND status='pending'")->execute([$now,$email]);
            $this->pdo->prepare('INSERT INTO customer_access_requests (request_id,email,company,first_name,last_name,phone,requested_domain,password_hash,status,requested_at,reviewed_at,reviewed_by_admin_id,customer_id,customer_user_id,license_id,decision_note,updated_at,request_id_external,request_ip_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$registrationId,$email,$company,$first,$last,$phone!==''?$phone:null,$domain,$passwordHash,'registered',$now,$now,null,$customerId,$customerUserId,null,'HF87_SELF_REGISTRATION',$now,$requestId!==''?$requestId:null,$ipHash]);
            $this->audit->write('ACCOUNT_REGISTERED','customer_user',$customerUserId,'public',$email,$requestId?:null,[],['customer_id'=>$customerId,'customer_key'=>$email,'domain'=>$domain,'manager_mode'=>'demo']);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}

        $payload=$this->login(['email'=>$email,'password'=>$password,'_surface'=>$surface],$requestId);
        $payload['registration']=['registered'=>true,'registration_id'=>$registrationId,'customer_key'=>$email];
        return $payload;
    }
    public function login(array $data, string $requestId=''): array
    {
        $email=$this->normalizeEmail((string)($data['email']??''));$password=(string)($data['password']??'');
        if($password==='')throw new \RuntimeException('ACCOUNT_LOGIN_INVALID');
        $st=$this->pdo->prepare('SELECT u.*,c.status customer_status,c.customer_number,c.customer_key,c.name customer_name FROM customer_users u JOIN customers c ON c.customer_id=u.customer_id WHERE LOWER(COALESCE(u.email,u.username))=? OR LOWER(u.username)=? LIMIT 1');
        $st->execute([$email,$email]);$u=$st->fetch();$now=time();
        if(!$u){password_verify($password,'$2y$10$3NQ2k.5H0X7gYPQLWspvMO6vhkcwuPd0szC4yR9QkwpJ9cAtx9iD2');$this->throwPendingOrInvalid($email);}
        if((string)$u['status']!=='active'||(string)$u['customer_status']!=='active')throw new \RuntimeException('ACCOUNT_NOT_ACTIVE');
        if(!empty($u['locked_until'])&&$now<(int)$u['locked_until'])throw new \RuntimeException('ACCOUNT_LOCKED');
        if(!password_verify($password,(string)$u['password_hash'])){
            $f=(int)$u['failed_attempts']+1;$locked=null;if($f>=5){$locked=$now+900;$f=0;}
            $this->pdo->prepare('UPDATE customer_users SET failed_attempts=?,locked_until=?,updated_at=? WHERE customer_user_id=?')->execute([$f,$locked,$now,$u['customer_user_id']]);
            $this->audit->write('ACCOUNT_LOGIN_FAILED','customer_user',(string)$u['customer_user_id'],'customer_user',(string)$u['customer_user_id'],$requestId?:null);
            throw new \RuntimeException('ACCOUNT_LOGIN_INVALID');
        }

        $lic=$this->resolveUsableLicense($u);
        $installationBinding=['status'=>'not_applicable'];
        if($lic!==null){
            $licenseId=(string)$lic['license_id'];$surfacePolicy=$this->surface->checkForLicense($licenseId,$data);
            $installationBinding=(new BootstrapActivationService($this->pdo,$this->audit))->bindInstallation(trim((string)($data['installation_id']??'')),$licenseId,(string)$u['customer_id'],(string)$u['customer_user_id']);
            $modules=$this->licenses->modules($licenseId);$policy=$this->licenses->policySummary($licenseId);
            $rolePolicy=$this->roles()->policy((string)$u['customer_id'],(string)$u['customer_user_id'],$policy,$licenseId);$mode='licensed';
        }else{
            $licenseId='';$modules=[];$policy=$this->demoLicensePolicy();$rolePolicy=$this->demoRolePolicy();$mode='demo';
        }
        $this->pdo->prepare('UPDATE customer_users SET failed_attempts=0,locked_until=NULL,last_login_at=?,updated_at=? WHERE customer_user_id=?')->execute([$now,$now,$u['customer_user_id']]);
        $this->audit->write('ACCOUNT_LOGIN','customer_user',(string)$u['customer_user_id'],'customer_user',(string)$u['customer_user_id'],$requestId?:null,[],['manager_mode'=>$mode,'customer_key'=>(string)($u['customer_key']?:$email),'license_id'=>$licenseId?:null]);
        [$rawSession,$expires]=$this->issueSession($u,$data,$now);
        $payload=[
            'manager_mode'=>$mode,'customer_key'=>(string)($u['customer_key']?:$email),
            'account_session_token'=>$rawSession,'account_session_expires_at'=>$expires,
            'account'=>['customer_user_id'=>(string)$u['customer_user_id'],'customer_id'=>(string)$u['customer_id'],'customer_key'=>(string)($u['customer_key']?:$email),'email'=>(string)($u['email']?:$u['username']),'display_name'=>(string)$u['display_name'],'role'=>(string)$u['role'],'customer_number'=>(string)$u['customer_number'],'customer_name'=>(string)$u['customer_name']],
            'license'=>$lic===null?null:['id'=>$licenseId,'number'=>(string)$lic['license_number'],'status'=>(string)$lic['status'],'valid_until'=>$lic['valid_until']!==null?(int)$lic['valid_until']:null,'generation'=>(int)$lic['generation']],
            'modules'=>$modules,'license_policy'=>$policy,'role_policy'=>$rolePolicy,'installation_binding'=>$installationBinding,
            'surface_policy'=>$lic===null?['allowed'=>true,'error_code'=>null]:$surfacePolicy,
        ];
        if($mode==='demo')$payload['demo']=$this->demoInfo();
        return $payload;
    }
    public function profile(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$customerId=(string)$u['customer_id'];
        $cst=$this->pdo->prepare('SELECT * FROM customers WHERE customer_id=? LIMIT 1');$cst->execute([$customerId]);$c=$cst->fetch();if(!$c)throw new \RuntimeException('CUSTOMER_NOT_FOUND');
        $dst=$this->pdo->prepare("SELECT rule_id,domain_pattern,status FROM customer_allowed_domains WHERE customer_id=? AND status='active' ORDER BY domain_pattern");$dst->execute([$customerId]);
        $nst=$this->pdo->prepare("SELECT rule_id,cidr,status FROM customer_allowed_networks WHERE customer_id=? AND status='active' ORDER BY cidr");$nst->execute([$customerId]);
        $customerFields=['customer_id','customer_number','customer_key','name','company','legal_name','contact_name','email','phone','mobile','website','street','postal_code','city','state_region','country_code','vat_id','billing_email','technical_email','surface_domain_required','surface_ip_required','surface_self_service'];

        $licenseRequests=$this->licenseRequestRows($customerId);$licenseRequestCatalog=$this->licenseRequestCatalog();

        if($lic===null){
            return [
                'manager_mode'=>'demo','customer_key'=>(string)($c['customer_key']??''),
                'account'=>['customer_user_id'=>(string)$u['customer_user_id'],'customer_key'=>(string)($c['customer_key']??''),'email'=>(string)($u['email']?:$u['username']),'display_name'=>(string)$u['display_name']],
                'customer'=>array_intersect_key($c,array_flip($customerFields)),'license'=>null,'modules'=>[],
                'license_policy'=>$this->demoLicensePolicy(),'installation_usage'=>['used_installations'=>0,'max_installations'=>0,'available_installations'=>0],
                'role_policy'=>$this->demoRolePolicy(),'installations'=>[],'dataforms'=>[],'domains'=>$dst->fetchAll(),'networks'=>$nst->fetchAll(),'demo'=>$this->demoInfo(),
                'license_requests'=>$licenseRequests,'license_request_catalog'=>$licenseRequestCatalog,'surface_policy'=>['allowed'=>true,'error_code'=>null],
            ];
        }

        $licenseId=(string)$lic['license_id'];$surfacePolicy=$this->surface->checkForLicense($licenseId,$data);
        $licensePolicy=$this->licenses->policySummary($licenseId);$rolePolicy=$this->roles()->policy($customerId,(string)$u['customer_user_id'],$licensePolicy,$licenseId);
        $ist=$this->pdo->prepare('SELECT installation_id,status,generation,product_code,product_version,activated_at,last_seen_at FROM installations WHERE license_id=? ORDER BY activated_at DESC,installation_id');$ist->execute([$licenseId]);$installations=$ist->fetchAll();
        $usedInstallations=0;foreach($installations as $installation)if(InstallationLifecycleService::consumesSlot((string)($installation['status']??'')))$usedInstallations++;
        $maxInstallations=max(1,(int)($lic['max_installations']??1));$installationUsage=['used_installations'=>$usedInstallations,'max_installations'=>$maxInstallations,'available_installations'=>max(0,$maxInstallations-$usedInstallations)];
        $dfst=$this->pdo->prepare("SELECT d.dataform_uuid,d.installation_id,d.project_id,d.local_dataform_id,d.name,d.slug,d.status,d.registered_at,d.last_seen_at,d.approved_at,d.released_at,u.status usage_status,i.status installation_status FROM license_dataforms d LEFT JOIN license_dataform_usage u ON u.license_id=d.license_id AND u.project_id=d.project_id AND u.dataform_id=d.local_dataform_id LEFT JOIN installations i ON i.license_id=d.license_id AND i.installation_id=d.installation_id WHERE d.license_id=? AND d.customer_id=? ORDER BY d.project_id,d.name,d.local_dataform_id");
        $dfst->execute([$licenseId,$customerId]);$dataforms=[];$resourceScope=(array)($rolePolicy['resource_scope']??[]);$unrestricted=(bool)($resourceScope['unrestricted']??false);
        $wholeProjects=array_fill_keys(array_map('strval',(array)($resourceScope['project_ids']??[])),true);$allowedDataforms=array_fill_keys(array_map('strval',(array)($resourceScope['dataform_uuids']??[])),true);
        foreach($dfst->fetchAll() as $row){
            $uuid=(string)$row['dataform_uuid'];$projectId=(string)$row['project_id'];if(!$unrestricted&&!isset($wholeProjects[$projectId])&&!isset($allowedDataforms[$uuid]))continue;
            $registryStatus=(string)$row['status'];$usageStatus=(string)($row['usage_status']??'none');$installationStatus=(string)($row['installation_status']??'missing');
            if($registryStatus==='approved')$tokenStatus=$usageStatus!=='active'?'slot_inactive':($installationStatus!=='active'?'installation_inactive':'valid');elseif($registryStatus==='pending')$tokenStatus='pending';else$tokenStatus='invalid';
            $row['usage_status']=$usageStatus;$row['installation_status']=$installationStatus;$row['consumes_license_slot']=$usageStatus==='active';$row['token_status']=$tokenStatus;$dataforms[]=$row;
        }
        return [
            'manager_mode'=>'licensed','customer_key'=>(string)($c['customer_key']??''),
            'account'=>['customer_user_id'=>(string)$u['customer_user_id'],'customer_key'=>(string)($c['customer_key']??''),'email'=>(string)($u['email']?:$u['username']),'display_name'=>(string)$u['display_name']],
            'customer'=>array_intersect_key($c,array_flip($customerFields)),
            'license'=>['id'=>$licenseId,'number'=>(string)$lic['license_number'],'status'=>(string)$lic['status'],'valid_until'=>$lic['valid_until']!==null?(int)$lic['valid_until']:null,'generation'=>(int)$lic['generation']],
            'modules'=>$this->licenses->modules($licenseId),'license_policy'=>$licensePolicy,'installation_usage'=>$installationUsage,'role_policy'=>$rolePolicy,'installations'=>$installations,'dataforms'=>$dataforms,'domains'=>$dst->fetchAll(),'networks'=>$nst->fetchAll(),
            'license_requests'=>$licenseRequests,'license_request_catalog'=>$licenseRequestCatalog,'surface_policy'=>$surfacePolicy,
        ];
    }
    public function submitLicenseRequest(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);
        if((string)($u['role']??'')!=='customer_admin')throw new \RuntimeException('LICENSE_REQUEST_PERMISSION_DENIED');
        $customerId=(string)$u['customer_id'];$customerKey=strtolower(trim((string)($u['customer_key']??$u['email']??$u['username']??'')));
        $requestType=(string)($data['request_type']??($lic===null?'new':'change'));
        if(!in_array($requestType,['new','change','extension'],true))throw new \RuntimeException('LICENSE_REQUEST_TYPE_INVALID');
        if($lic===null&&$requestType!=='new')$requestType='new';

        $maxInstall=(int)($data['max_installations']??1);if($maxInstall<1||$maxInstall>1000)throw new \RuntimeException('LICENSE_REQUEST_INSTALLATIONS_INVALID');
        $maxDfRaw=$data['max_dataforms']??5;$maxDf=$maxDfRaw===''?null:(int)$maxDfRaw;if($maxDf!==null&&($maxDf<0||$maxDf>100000))throw new \RuntimeException('LICENSE_REQUEST_DATAFORMS_INVALID');
        $term=(int)($data['term_months']??12);if($term<0||$term>120)throw new \RuntimeException('LICENSE_REQUEST_TERM_INVALID');

        $productCatalog=['dataform','dialog','nachhilfe','calendar','csv-engine','db-engine'];
        $products=array_values(array_intersect($productCatalog,array_values(array_unique(array_map('strval',(array)($data['products']??[]))))));
        if(!$products)$products=['dataform'];

        $moduleRows=$this->pdo->query("SELECT module_code FROM modules WHERE active=1 ORDER BY module_code")->fetchAll(PDO::FETCH_COLUMN);
        $moduleMap=array_fill_keys(array_map('strval',$moduleRows),true);$modules=[];
        foreach((array)($data['modules']??[]) as $m){$m=(string)$m;if(isset($moduleMap[$m]))$modules[$m]=true;}

        $capRows=$this->pdo->query("SELECT capability_code FROM capability_registry WHERE active=1 ORDER BY capability_code")->fetchAll(PDO::FETCH_COLUMN);
        $capMap=array_fill_keys(array_map('strval',$capRows),true);$caps=[];
        foreach((array)($data['capabilities']??[]) as $cap){$cap=(string)$cap;if(isset($capMap[$cap]))$caps[$cap]=true;}

        foreach(array_keys($caps) as $cap){
            if(str_starts_with($cap,'designer.'))$modules['designer']=true;
            elseif($cap==='export.execute')$modules['export']=true;
            elseif($cap==='api.access')$modules['api']=true;
            else $modules['dataform']=true;
        }
        foreach(['dataform','dialog','nachhilfe','calendar','csv-engine','db-engine'] as $productModule)if(in_array($productModule,$products,true))$modules[$productModule]=true;

        // HF108: Eine Erweiterung ist strikt additiv. Alte Clients duerfen deshalb
        // keine bestehenden Kontingente, Module oder Capabilities versehentlich
        // herabsetzen, wenn sie nur die zusaetzliche Freigabe senden.
        if($lic!==null&&$requestType==='extension'){
            $licenseId=(string)$lic['license_id'];
            $maxInstall=max($maxInstall,max(1,(int)($lic['max_installations']??1)));
            $currentPolicy=$this->licenses->policySummary($licenseId);
            if(array_key_exists('max_dataforms',$currentPolicy)){
                $currentMaxDf=$currentPolicy['max_dataforms'];
                if($currentMaxDf===null)$maxDf=null;
                elseif($maxDf!==null)$maxDf=max((int)$currentMaxDf,$maxDf);
            }
            foreach($this->licenses->modules($licenseId) as $moduleCode)$modules[(string)$moduleCode]=true;
            $currentGlobalCaps=$this->licenses->globalCapabilityMap($licenseId);
            if($currentGlobalCaps===null){
                foreach(array_keys($capMap) as $capabilityCode)$caps[(string)$capabilityCode]=true;
            }else{
                foreach($currentGlobalCaps as $capabilityCode=>$enabled)if($enabled)$caps[(string)$capabilityCode]=true;
            }
        }

        if(!$modules)throw new \RuntimeException('LICENSE_REQUEST_MODULE_REQUIRED');
        if(isset($modules['dataform'])&&$maxDf===null&&$requestType!=='extension')$maxDf=5;

        $domains=$this->normalizeRequestLines((string)($data['desired_domains']??''),fn(string $v)=>$this->surface->normalizeDomainPattern($v));
        $networks=$this->normalizeRequestLines((string)($data['desired_networks']??''),fn(string $v)=>$this->surface->normalizeCidr($v));
        $notes=trim((string)($data['notes']??''));if(mb_strlen($notes,'UTF-8')>4000)throw new \RuntimeException('LICENSE_REQUEST_NOTES_TOO_LONG');

        $now=time();$requestId=Id::make('LREQ');
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("UPDATE customer_license_requests SET status='superseded',updated_at=? WHERE customer_id=? AND status='pending'")->execute([$now,$customerId]);
            $st=$this->pdo->prepare('INSERT INTO customer_license_requests (request_id,customer_id,customer_user_id,customer_key,request_type,requested_products,requested_modules,requested_capabilities,max_installations,max_dataforms,term_months,requested_domains,requested_networks,notes,status,requested_at,updated_at,reviewed_at,reviewed_by_admin_id,decision_note,resulting_license_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\',?,?,NULL,NULL,NULL,NULL)');
            $st->execute([$requestId,$customerId,(string)$u['customer_user_id'],$customerKey,$requestType,$this->jsonEncodeList($products),$this->jsonEncodeList(array_keys($modules)),$this->jsonEncodeList(array_keys($caps)),$maxInstall,$maxDf,$term,$this->jsonEncodeList($domains),$this->jsonEncodeList($networks),$notes!==''?$notes:null,$now,$now]);
            $this->audit->write('LICENSE_REQUEST_SUBMITTED','license_request',$requestId,'customer_user',(string)$u['customer_user_id'],null,[],['customer_key'=>$customerKey,'request_type'=>$requestType,'products'=>$products,'modules'=>array_keys($modules),'max_installations'=>$maxInstall,'max_dataforms'=>$maxDf,'term_months'=>$term]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        $profile=$this->profile($data);$profile['license_request_result']=['submitted'=>true,'request_id'=>$requestId];return $profile;
    }

    public function cancelLicenseRequest(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$requestId=trim((string)($data['request_id']??''));if($requestId==='')throw new \RuntimeException('LICENSE_REQUEST_NOT_FOUND');
        $st=$this->pdo->prepare("UPDATE customer_license_requests SET status='cancelled',updated_at=? WHERE request_id=? AND customer_id=? AND status='pending'");$st->execute([time(),$requestId,$u['customer_id']]);
        if($st->rowCount()===0)throw new \RuntimeException('LICENSE_REQUEST_NOT_FOUND');
        $this->audit->write('LICENSE_REQUEST_CANCELLED','license_request',$requestId,'customer_user',(string)$u['customer_user_id']);return $this->profile($data);
    }

    /** HF87 Phase 4: central product portal for one Enterprise installation. */
    public function projectProducts(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);
        $installationId=trim((string)($data['installation_id']??''));
        if($installationId==='')throw new \RuntimeException('PROJECT_PRODUCT_INSTALLATION_REQUIRED');
        return $this->projectProductPayload((string)$u['customer_id'],$installationId,$lic);
    }

    public function requestProjectProduct(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);
        if((string)($u['role']??'')!=='customer_admin')throw new \RuntimeException('LICENSE_REQUEST_PERMISSION_DENIED');
        $customerId=(string)$u['customer_id'];
        $customerKey=strtolower(trim((string)($u['customer_key']??$u['email']??$u['username']??'')));
        $installationId=trim((string)($data['installation_id']??''));
        $projectId=trim((string)($data['project_id']??''));
        $projectName=trim((string)($data['project_name']??''));
        $projectSlug=trim((string)($data['project_slug']??''));
        $productCode=strtolower(trim((string)($data['product_code']??'')));
        if($installationId===''||mb_strlen($installationId,'UTF-8')>100)throw new \RuntimeException('PROJECT_PRODUCT_INSTALLATION_REQUIRED');
        if($projectId===''||mb_strlen($projectId,'UTF-8')>100||$projectName===''||mb_strlen($projectName,'UTF-8')>255)throw new \RuntimeException('PROJECT_PRODUCT_PROJECT_INVALID');
        if($projectSlug!==''&&mb_strlen($projectSlug,'UTF-8')>255)throw new \RuntimeException('PROJECT_PRODUCT_PROJECT_INVALID');

        $pst=$this->pdo->prepare("SELECT * FROM product_catalog WHERE product_code=? AND visible=1 AND status IN ('available','beta') LIMIT 1");
        $pst->execute([$productCode]);$product=$pst->fetch();if(!$product)throw new \RuntimeException('PROJECT_PRODUCT_NOT_AVAILABLE');

        $ent=$this->pdo->prepare("SELECT entitlement_id FROM project_product_entitlements WHERE customer_id=? AND installation_id=? AND product_code=? AND status='active' AND (project_id=? OR (?<>'' AND project_slug=?)) AND (valid_until IS NULL OR valid_until>=?) LIMIT 1");
        $ent->execute([$customerId,$installationId,$productCode,$projectId,$projectSlug,$projectSlug,time()]);
        if($ent->fetch())throw new \RuntimeException('PROJECT_PRODUCT_ALREADY_LICENSED');

        $moduleRows=$this->pdo->query("SELECT module_code FROM modules WHERE active=1 ORDER BY module_code")->fetchAll(PDO::FETCH_COLUMN);$moduleMap=array_fill_keys(array_map('strval',$moduleRows),true);
        $defaultModules=$this->jsonDecodeList((string)($product['default_modules']??''));$requestedModules=array_values(array_unique(array_merge($defaultModules,array_map('strval',(array)($data['modules']??[])))));$modules=[];
        foreach($requestedModules as $m)if(isset($moduleMap[$m]))$modules[$m]=true;
        $capRows=$this->pdo->query("SELECT capability_code FROM capability_registry WHERE active=1 ORDER BY capability_code")->fetchAll(PDO::FETCH_COLUMN);$capMap=array_fill_keys(array_map('strval',$capRows),true);
        $defaultCaps=$this->jsonDecodeList((string)($product['default_capabilities']??''));$requestedCaps=array_values(array_unique(array_merge($defaultCaps,array_map('strval',(array)($data['capabilities']??[])))));$caps=[];
        foreach($requestedCaps as $cap)if(isset($capMap[$cap]))$caps[$cap]=true;
        $term=(int)($data['term_months']??12);if($term<0||$term>120)throw new \RuntimeException('LICENSE_REQUEST_TERM_INVALID');
        $notes=trim((string)($data['notes']??''));if(mb_strlen($notes,'UTF-8')>2500)throw new \RuntimeException('LICENSE_REQUEST_NOTES_TOO_LONG');

        $now=time();$requestId=Id::make('PREQ');
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare("UPDATE project_product_requests SET status='superseded',updated_at=? WHERE customer_id=? AND installation_id=? AND product_code=? AND status='pending' AND (project_id=? OR (?<>'' AND project_slug=?))")->execute([$now,$customerId,$installationId,$productCode,$projectId,$projectSlug,$projectSlug]);
            $st=$this->pdo->prepare("INSERT INTO project_product_requests (request_id,customer_id,customer_user_id,customer_key,installation_id,project_id,project_name,project_slug,product_code,requested_modules,requested_capabilities,term_months,notes,status,requested_at,updated_at,reviewed_at,reviewed_by_admin_id,decision_note,resulting_license_id,entitlement_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?,NULL,NULL,NULL,NULL,NULL)");
            $st->execute([$requestId,$customerId,(string)$u['customer_user_id'],$customerKey,$installationId,$projectId,$projectName,$projectSlug!==''?$projectSlug:null,$productCode,$this->jsonEncodeList(array_keys($modules)),$this->jsonEncodeList(array_keys($caps)),$term,$notes!==''?$notes:null,$now,$now]);
            $this->audit->write('PROJECT_PRODUCT_LICENSE_REQUESTED','project_product_request',$requestId,'customer_user',(string)$u['customer_user_id'],null,[],['customer_key'=>$customerKey,'installation_id'=>$installationId,'project_id'=>$projectId,'project_slug'=>$projectSlug,'product_code'=>$productCode]);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return $this->projectProductPayload($customerId,$installationId,$lic);
    }

    public function cancelProjectProductRequest(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);if((string)($u['role']??'')!=='customer_admin')throw new \RuntimeException('LICENSE_REQUEST_PERMISSION_DENIED');
        $requestId=trim((string)($data['request_id']??''));$installationId=trim((string)($data['installation_id']??''));if($requestId===''||$installationId==='')throw new \RuntimeException('PROJECT_PRODUCT_REQUEST_NOT_FOUND');
        $st=$this->pdo->prepare("UPDATE project_product_requests SET status='cancelled',updated_at=? WHERE request_id=? AND customer_id=? AND installation_id=? AND status='pending'");$st->execute([time(),$requestId,$u['customer_id'],$installationId]);if($st->rowCount()===0)throw new \RuntimeException('PROJECT_PRODUCT_REQUEST_NOT_FOUND');
        $this->audit->write('PROJECT_PRODUCT_LICENSE_REQUEST_CANCELLED','project_product_request',$requestId,'customer_user',(string)$u['customer_user_id']);
        return $this->projectProductPayload((string)$u['customer_id'],$installationId,$lic);
    }

    public function changePassword(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);if($lic!==null)$this->surface->assertForLicense((string)$lic['license_id'],$data);
        $svc=new CustomerCredentialService($this->pdo,$this->audit);
        $version=$svc->changePassword((string)$u['customer_id'],(string)$u['customer_user_id'],(string)($data['current_password']??''),(string)($data['new_password']??''),(string)($data['new_password_confirm']??''),(string)$u['customer_user_id']);
        return ['password_changed'=>true,'relogin_required'=>true,'credential_version'=>$version];
    }
    public function updateProfile(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);if($lic!==null)$this->surface->assertForLicense((string)$lic['license_id'],$data);
        // HF87: the registered customer e-mail is the business key and is not self-service editable.
        $fields=['legal_name','contact_name','phone','mobile','website','street','postal_code','city','state_region','country_code','vat_id','billing_email','technical_email'];$v=[];
        foreach($fields as $f)$v[$f]=trim((string)($data[$f]??''));foreach(['billing_email','technical_email'] as $f)if($v[$f]!==''&&!filter_var($v[$f],FILTER_VALIDATE_EMAIL))throw new \RuntimeException('ACCOUNT_EMAIL_INVALID');
        $sql='UPDATE customers SET '.implode(',',array_map(fn($f)=>$f.'=?',$fields)).',updated_at=? WHERE customer_id=?';$args=[];foreach($fields as $f)$args[]=$v[$f]!==''?$v[$f]:null;$args[]=time();$args[]=$u['customer_id'];$this->pdo->prepare($sql)->execute($args);
        $this->audit->write('ACCOUNT_PROFILE_UPDATED','customer',(string)$u['customer_id'],'customer_user',(string)$u['customer_user_id']);return $this->profile($data);
    }
    public function addDomain(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->assertSurfaceSelfService((string)$u['customer_id']);
        $domain=$this->surface->normalizeDomainPattern((string)($data['domain']??''));$now=time();$id=Id::make('DOM');
        try{$this->pdo->prepare("INSERT INTO customer_allowed_domains (rule_id,customer_id,domain_pattern,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute([$id,$u['customer_id'],$domain,$now,$now]);}catch(\Throwable){$this->pdo->prepare("UPDATE customer_allowed_domains SET status='active',updated_at=? WHERE customer_id=? AND domain_pattern=?")->execute([$now,$u['customer_id'],$domain]);}
        $this->audit->write('ACCOUNT_DOMAIN_ADDED','customer',(string)$u['customer_id'],'customer_user',(string)$u['customer_user_id'],null,[],['domain'=>$domain]);return $this->profile($data);
    }

    public function removeDomain(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$surface=$this->surface->assertForLicense((string)$lic['license_id'],$data);$this->assertSurfaceSelfService((string)$u['customer_id']);$id=(string)($data['rule_id']??'');
        if($this->surface->removeDomainRuleSafely((string)$u['customer_id'],$id,(string)($surface['host']??'')))$this->audit->write('ACCOUNT_DOMAIN_REMOVED','customer',(string)$u['customer_id'],'customer_user',(string)$u['customer_user_id'],null,[],['rule_id'=>$id]);return $this->profile($data);
    }

    public function addNetwork(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->assertSurfaceSelfService((string)$u['customer_id']);
        $cidr=$this->surface->normalizeCidr((string)($data['cidr']??''));$now=time();$id=Id::make('NET');
        try{$this->pdo->prepare("INSERT INTO customer_allowed_networks (rule_id,customer_id,cidr,status,created_at,updated_at) VALUES (?,?,?,'active',?,?)")->execute([$id,$u['customer_id'],$cidr,$now,$now]);}catch(\Throwable){$this->pdo->prepare("UPDATE customer_allowed_networks SET status='active',updated_at=? WHERE customer_id=? AND cidr=?")->execute([$now,$u['customer_id'],$cidr]);}
        $this->audit->write('ACCOUNT_NETWORK_ADDED','customer',(string)$u['customer_id'],'customer_user',(string)$u['customer_user_id'],null,[],['cidr'=>$cidr]);return $this->profile($data);
    }

    public function removeNetwork(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$surface=$this->surface->assertForLicense((string)$lic['license_id'],$data);$this->assertSurfaceSelfService((string)$u['customer_id']);$id=(string)($data['rule_id']??'');
        if($this->surface->removeNetworkRuleSafely((string)$u['customer_id'],$id,(string)($surface['source_ip']??'')))$this->audit->write('ACCOUNT_NETWORK_REMOVED','customer',(string)$u['customer_id'],'customer_user',(string)$u['customer_user_id'],null,[],['rule_id'=>$id]);return $this->profile($data);
    }

    public function retireInstallation(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$licenseId=(string)$lic['license_id'];$this->surface->assertForLicense($licenseId,$data);
        $policy=$this->licenses->policySummary($licenseId);$this->roles()->assertInternal((string)$u['customer_id'],(string)$u['customer_user_id'],$policy,'account.installations.manage',$licenseId);
        $iid=trim((string)($data['installation_id']??''));if($iid==='')throw new \RuntimeException('INSTALLATION_REQUIRED');
        $lifecycle=new InstallationLifecycleService($this->pdo,$this->audit);$result=$lifecycle->transition($iid,'retired','customer_user',(string)$u['customer_user_id'],null,$licenseId);
        $profile=$this->profile($data);$profile['installation_lifecycle']=$result;return $profile;
    }

    public function team(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->surface->assertForLicense((string)$lic['license_id'],$data);$policy=$this->licenses->policySummary((string)$lic['license_id']);return $this->roles()->team((string)$u['customer_id'],(string)$u['customer_user_id'],$policy,(string)$lic['license_id']);
    }

    public function teamCreateUser(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->surface->assertForLicense((string)$lic['license_id'],$data);$u['license_id']=$lic['license_id'];$policy=$this->licenses->policySummary((string)$lic['license_id']);return $this->roles()->createUser($u,$policy,$data,(string)$lic['license_id']);
    }

    public function teamSetUser(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->surface->assertForLicense((string)$lic['license_id'],$data);$policy=$this->licenses->policySummary((string)$lic['license_id']);return $this->roles()->setUser($u,$policy,$data,(string)$lic['license_id']);
    }

    public function teamResetUserPassword(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->surface->assertForLicense((string)$lic['license_id'],$data);$policy=$this->licenses->policySummary((string)$lic['license_id']);return $this->roles()->resetUserPassword($u,$policy,$data,(string)$lic['license_id']);
    }

    public function teamSaveRole(array $data): array
    {
        [$u,$lic]=$this->sessionAccount($data);$lic=$this->requireLicense($lic);$this->surface->assertForLicense((string)$lic['license_id'],$data);$policy=$this->licenses->policySummary((string)$lic['license_id']);return $this->roles()->saveRole($u,$policy,$data,(string)$lic['license_id']);
    }

    public function logout(array $data): array
    {
        $token=(string)($data['account_session_token']??'');if($token!=='')$this->pdo->prepare("UPDATE customer_account_sessions SET status='closed',last_seen_at=? WHERE token_hash=? AND status='active'")->execute([time(),hash('sha256',$token)]);return ['logged_out'=>true];
    }

    private function sessionAccount(array $data): array
    {
        $token=(string)($data['account_session_token']??'');if($token==='')throw new \RuntimeException('ACCOUNT_SESSION_INVALID');$now=time();
        $st=$this->pdo->prepare("SELECT u.*,s.session_id,s.expires_at,c.status customer_status,c.customer_key FROM customer_account_sessions s JOIN customer_users u ON u.customer_user_id=s.customer_user_id JOIN customers c ON c.customer_id=u.customer_id WHERE s.token_hash=? AND s.status='active' LIMIT 1");
        $st->execute([hash('sha256',$token)]);$u=$st->fetch();if(!$u)throw new \RuntimeException('ACCOUNT_SESSION_INVALID');
        if($now>(int)$u['expires_at']){$this->pdo->prepare("UPDATE customer_account_sessions SET status='expired' WHERE session_id=?")->execute([$u['session_id']]);throw new \RuntimeException('ACCOUNT_SESSION_EXPIRED');}
        if((string)$u['status']!=='active'||(string)$u['customer_status']!=='active')throw new \RuntimeException('ACCOUNT_NOT_ACTIVE');
        $lic=$this->resolveUsableLicense($u);$this->pdo->prepare('UPDATE customer_account_sessions SET last_seen_at=? WHERE session_id=?')->execute([$now,$u['session_id']]);return[$u,$lic];
    }
    private function resolveUsableLicense(array $u): ?array
    {
        $customerId=(string)($u['customer_id']??'');if($customerId==='')return null;$now=time();$preferred=trim((string)($u['license_id']??''));
        if($preferred!==''){
            $st=$this->pdo->prepare("SELECT * FROM licenses WHERE license_id=? AND customer_id=? AND status='active' AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) LIMIT 1");
            $st->execute([$preferred,$customerId,$now,$now]);if($lic=$st->fetch())return $lic;
        }
        $st=$this->pdo->prepare("SELECT * FROM licenses WHERE customer_id=? AND status='active' AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) ORDER BY created_at DESC LIMIT 1");
        $st->execute([$customerId,$now,$now]);$lic=$st->fetch();if(!$lic)return null;$resolved=(string)$lic['license_id'];
        if($resolved!==$preferred)$this->pdo->prepare('UPDATE customer_users SET license_id=?,updated_at=? WHERE customer_user_id=?')->execute([$resolved,$now,$u['customer_user_id']]);
        return $lic;
    }

    private function projectProductPayload(string $customerId,string $installationId,?array $lic): array
    {
        $products=[];$rows=$this->pdo->query("SELECT product_code,name,description,loader_module,package_key,default_modules,default_capabilities,status,version,sort_order FROM product_catalog WHERE visible=1 ORDER BY sort_order,product_code")->fetchAll();
        foreach($rows as $r){$r['default_modules']=$this->jsonDecodeList((string)($r['default_modules']??''));$r['default_capabilities']=$this->jsonDecodeList((string)($r['default_capabilities']??''));$products[]=$r;}
        $req=$this->pdo->prepare("SELECT request_id,installation_id,project_id,project_name,project_slug,product_code,requested_modules,requested_capabilities,term_months,notes,status,requested_at,updated_at,reviewed_at,decision_note,resulting_license_id,entitlement_id FROM project_product_requests WHERE customer_id=? AND installation_id=? ORDER BY requested_at DESC LIMIT 100");$req->execute([$customerId,$installationId]);$requests=[];
        foreach($req->fetchAll() as $r){$r['requested_modules']=$this->jsonDecodeList((string)($r['requested_modules']??''));$r['requested_capabilities']=$this->jsonDecodeList((string)($r['requested_capabilities']??''));$requests[]=$r;}
        $ent=$this->pdo->prepare("SELECT entitlement_id,license_id,installation_id,project_id,project_name,project_slug,product_code,status,valid_from,valid_until,modules,capabilities,approved_request_id,created_at,updated_at FROM project_product_entitlements WHERE customer_id=? AND installation_id=? ORDER BY project_name,product_code");$ent->execute([$customerId,$installationId]);$entitlements=[];
        foreach($ent->fetchAll() as $r){$r['modules']=$this->jsonDecodeList((string)($r['modules']??''));$r['capabilities']=$this->jsonDecodeList((string)($r['capabilities']??''));$entitlements[]=$r;}
        return ['installation_id'=>$installationId,'license_id'=>$lic!==null?(string)$lic['license_id']:null,'products'=>$products,'project_requests'=>$requests,'project_entitlements'=>$entitlements];
    }

    private function licenseRequestRows(string $customerId): array
    {
        $st=$this->pdo->prepare('SELECT request_id,customer_key,request_type,requested_products,requested_modules,requested_capabilities,max_installations,max_dataforms,term_months,requested_domains,requested_networks,notes,status,requested_at,updated_at,reviewed_at,decision_note,resulting_license_id FROM customer_license_requests WHERE customer_id=? ORDER BY requested_at DESC LIMIT 20');$st->execute([$customerId]);$out=[];
        foreach($st->fetchAll() as $r){foreach(['requested_products','requested_modules','requested_capabilities','requested_domains','requested_networks'] as $f)$r[$f]=$this->jsonDecodeList((string)($r[$f]??''));$out[]=$r;}return $out;
    }
    private function licenseRequestCatalog(): array
    {
        $mods=$this->pdo->query("SELECT module_code,name FROM modules WHERE active=1 ORDER BY module_code")->fetchAll();$caps=$this->pdo->query("SELECT capability_code,name,category FROM capability_registry WHERE active=1 ORDER BY category,capability_code")->fetchAll();
        return ['products'=>[['code'=>'dataform','name'=>'DataForm'],['code'=>'dialog','name'=>'Dialog'],['code'=>'nachhilfe','name'=>'Nachhilfe'],['code'=>'calendar','name'=>'easyIT Calendar'],['code'=>'csv-engine','name'=>'CSV-Engine'],['code'=>'db-engine','name'=>'DB-Engine']],'modules'=>$mods,'capabilities'=>$caps];
    }
    private function normalizeRequestLines(string $raw,callable $normalizer): array
    {
        $out=[];foreach(preg_split('/[\\r\\n,;]+/',$raw)?:[] as $line){$line=trim($line);if($line==='')continue;$v=(string)$normalizer($line);$out[$v]=true;}return array_keys($out);
    }
    private function jsonEncodeList(array $values): string{return json_encode(array_values(array_unique(array_map('strval',$values))),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    private function jsonDecodeList(string $json): array{if(trim($json)==='')return[];try{$v=json_decode($json,true,512,JSON_THROW_ON_ERROR);return is_array($v)?array_values(array_map('strval',$v)):[];}catch(\Throwable){return[];}}

    private function requireLicense(?array $lic): array
    {
        if($lic===null)throw new \RuntimeException('ACCOUNT_LICENSE_REQUIRED');return $lic;
    }

    private function issueSession(array $u,array $data,int $now): array
    {
        $rawSession=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');$sessionId=Id::make('ACCS');$sessionHash=hash('sha256',$rawSession);$expires=$now+8*3600;
        $surface=(array)($data['_surface']??[]);$surfaceHost=strtolower(trim((string)($surface['host']??'')));
        $this->pdo->prepare("UPDATE customer_account_sessions SET status='expired' WHERE customer_user_id=? AND status='active'")->execute([$u['customer_user_id']]);
        $this->pdo->prepare('INSERT INTO customer_account_sessions (session_id,customer_user_id,token_hash,status,issued_at,expires_at,last_seen_at,surface_host,created_at) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$sessionId,$u['customer_user_id'],$sessionHash,'active',$now,$expires,$now,$surfaceHost!==''?$surfaceHost:null,$now]);
        return[$rawSession,$expires];
    }

    private function demoLicensePolicy(): array
    {
        return ['mode'=>'demo','capability_policy'=>'demo','max_dataforms'=>0,'used_dataforms'=>0,'available_dataforms'=>0,'capabilities'=>[]];
    }

    private function demoRolePolicy(): array
    {
        return ['roles'=>[['id'=>'demo','name'=>'demo','label'=>'Demo']],'unrestricted'=>false,'capabilities'=>[],'internal_capabilities'=>[],'resource_scope'=>['unrestricted'=>false,'mode'=>'restricted','project_ids'=>[],'visible_project_ids'=>[],'dataform_uuids'=>[],'dataforms'=>[]]];
    }

    private function demoInfo(): array
    {
        return ['active'=>true,'label'=>'Demo-Version','productive_resources'=>false,'production_projects'=>false,'production_dataforms'=>false,'df_tokens'=>false,'designer_publish'=>false,'export'=>false,'api'=>false];
    }

    private function normalizeEmail(string $email): string
    {
        $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('ACCOUNT_EMAIL_INVALID');return $email;
    }

    private function roles(): CustomerRoleService { return new CustomerRoleService($this->pdo,$this->audit); }

    private function assertSurfaceSelfService(string $customerId): void
    {
        $st=$this->pdo->prepare('SELECT surface_self_service FROM customers WHERE customer_id=? LIMIT 1');$st->execute([$customerId]);if((int)$st->fetchColumn()!==1)throw new \RuntimeException('CUSTOMER_SURFACE_SELF_SERVICE_DISABLED');
    }

    private function throwPendingOrInvalid(string $email): never
    {
        $st=$this->pdo->prepare('SELECT status FROM customer_access_requests WHERE email=? ORDER BY requested_at DESC LIMIT 1');$st->execute([$email]);$status=$st->fetchColumn();
        if($status==='pending') throw new \RuntimeException('ACCOUNT_REQUEST_PENDING');
        if($status==='rejected') throw new \RuntimeException('ACCOUNT_REQUEST_REJECTED');
        throw new \RuntimeException('ACCOUNT_LOGIN_INVALID');
    }

    private function assertPassword(string $password): void
    {
        if(strlen($password)<10) throw new \RuntimeException('ACCOUNT_PASSWORD_WEAK');
        if(!preg_match('/[a-z]/',$password)||!preg_match('/[A-Z]/',$password)||!preg_match('/\d/',$password)) throw new \RuntimeException('ACCOUNT_PASSWORD_WEAK');
    }
}
