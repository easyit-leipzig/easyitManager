<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\App;
use EasyIT\LicenseServer\Database\Connection;
use EasyIT\LicenseServer\Database\MigrationRunner;
use EasyIT\LicenseServer\Http\Http;
use EasyIT\LicenseServer\Http\ApiResponder;
use EasyIT\LicenseServer\ProtectedCore\ActionResolver;
use EasyIT\LicenseServer\ProtectedCore\DataFormCompiler;
use EasyIT\LicenseServer\ProtectedCore\DefinitionValidator;
use EasyIT\LicenseServer\Security\RequestVerifier;
use EasyIT\LicenseServer\Security\ServerSigner;
use EasyIT\LicenseServer\Service\ActivationService;
use EasyIT\LicenseServer\Service\AccountService;
use EasyIT\LicenseServer\Service\AuditService;
use EasyIT\LicenseServer\Service\LicenseService;
use EasyIT\LicenseServer\Service\RuntimeService;
use EasyIT\LicenseServer\Service\DesignerService;
use EasyIT\LicenseServer\Service\DataFormRegistryService;
use EasyIT\LicenseServer\Service\DataFormTokenService;
use EasyIT\LicenseServer\Service\SurfacePolicyService;
use EasyIT\LicenseServer\Service\BootstrapActivationService;

final class Application
{
 public static function run(array $cfg):never{
  $pdo=Connection::create($cfg['database']);(new MigrationRunner($pdo))->migrate();$signer=new ServerSigner($cfg['security']['server_key_id'],$cfg['security']['server_private_key_file'],$cfg['security']['server_public_key_file'],$cfg['security']['server_keyring_file']??((string)$cfg['app']['base_path'].'/secure/keys/server-keyring.json'));$res=new ApiResponder($signer,$cfg['app']['api_version']);$path=Http::path();$method=Http::method();$headers=Http::headers();$body=Http::body();$req=$headers['x-easyit-request-id']??null;
  try{
   if($method==='GET'&&$path==='/api/v1/health'){ $adminCount=(int)$pdo->query("SELECT COUNT(*) FROM admin_users WHERE status IN ('active','disabled')")->fetchColumn();$setupReady=$adminCount>0&&is_file((string)$cfg['app']['base_path'].'/secure/license-server-setup.lock');$res->send(true,['status'=>$setupReady?'ready':'setup_required','ready'=>$setupReady,'api'=>'v1'],null,200,$req); }
   $maintenance=(string)($cfg['app']['maintenance_mode']??'off');if($maintenance==='full')throw new \RuntimeException('SERVICE_MAINTENANCE');if($maintenance==='read_only'&&$method==='POST'&&in_array($path,['/api/v1/activation/request','/api/v1/dataforms/register','/api/v1/dataforms/release','/api/v1/designer/compile','/api/v1/designer/publish'],true))throw new \RuntimeException('SERVICE_MAINTENANCE');
   $data=$body!==''?json_decode($body,true,512,JSON_THROW_ON_ERROR):[];if(!is_array($data))$data=[];
   $audit=new AuditService($pdo);$licenses=new LicenseService($pdo);$surface=new SurfacePolicyService($pdo,$cfg['network']??[]);
   if($method==='POST'&&($path==='/api/v1/account/register'||$path==='/api/v1/account/request')){
      $svc=new AccountService($pdo,$licenses,$audit,$surface);
      $payload=$path==='/api/v1/account/register'?$svc->register($data,(string)($req??'')):$svc->requestAccess($data,(string)($req??''));
      $res->send(true,$payload,null,$path==='/api/v1/account/register'?201:200,$req);
   }
   if($method==='POST'&&$path==='/api/v1/account/login'){
      $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->login($data,(string)($req??'')),null,200,$req);
   }
   if($method==='POST'&&$path==='/api/v1/account/profile'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->profile($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/profile/update'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->updateProfile($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/password/change'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->changePassword($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/license/request'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->submitLicenseRequest($data),null,201,$req); }
   if($method==='POST'&&$path==='/api/v1/account/license/request/cancel'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->cancelLicenseRequest($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/project-products'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->projectProducts($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/project-product/request'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->requestProjectProduct($data),null,201,$req); }
   if($method==='POST'&&$path==='/api/v1/account/project-product/request/cancel'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->cancelProjectProductRequest($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/project-product/token'){
      $svc=new AccountService($pdo,$licenses,$audit,$surface);
      $portal=$svc->projectProducts($data);
      $product=strtolower(trim((string)($data['product_code']??'')));
      $projectId=trim((string)($data['project_id']??''));$projectSlug=trim((string)($data['project_slug']??''));$now=time();$found=null;
      foreach((array)($portal['project_entitlements']??[]) as $ent){
          if((string)($ent['product_code']??'')!==$product||(string)($ent['status']??'')!=='active')continue;
          $until=$ent['valid_until']??null;if($until!==null&&(int)$until<$now)continue;
          if((string)($ent['project_id']??'')===$projectId||($projectSlug!==''&&(string)($ent['project_slug']??'')===$projectSlug)){$found=$ent;break;}
      }
      if(!is_array($found))throw new \RuntimeException('PROJECT_PRODUCT_NOT_LICENSED');
      $tokenPayload=['schema'=>'easyit.project-product-token.v1','customer_key'=>(string)($data['customer_key']??''),'installation_id'=>(string)($portal['installation_id']??''),'project_id'=>(string)($found['project_id']??$projectId),'project_slug'=>(string)($found['project_slug']??$projectSlug),'product_code'=>$product,'capabilities'=>(array)($found['capabilities']??[]),'modules'=>(array)($found['modules']??[]),'issued_at'=>$now,'valid_until'=>$found['valid_until']!==null?(int)$found['valid_until']:($now+31536000)];
      $token=['payload'=>$tokenPayload,'key_id'=>$signer->keyId(),'signature'=>$signer->signArray($tokenPayload),'public_key'=>$signer->publicKeyEncoded()];
      $res->send(true,$token,null,200,$req);
   }
   if($method==='POST'&&$path==='/api/v1/account/domain/add'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->addDomain($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/domain/remove'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->removeDomain($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/network/add'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->addNetwork($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/network/remove'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->removeNetwork($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/installation/retire'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->retireInstallation($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/team'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->team($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/team/user/create'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->teamCreateUser($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/team/user/set'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->teamSetUser($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/team/user/password-reset'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->teamResetUserPassword($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/team/role/save'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->teamSaveRole($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/account/logout'){ $svc=new AccountService($pdo,$licenses,$audit,$surface);$res->send(true,$svc->logout($data),null,200,$req); }
   if($method==='POST'&&$path==='/api/v1/bootstrap/local-activate'){
      $remote=(string)($_SERVER['REMOTE_ADDR']??'');
      if(!in_array($remote,['127.0.0.1','::1'],true))throw new \RuntimeException('BOOTSTRAP_LOCAL_ONLY');
      foreach(['installation_id','installation_public_key','product'] as $required)if(!isset($data[$required]))throw new \RuntimeException('REQUEST_INVALID');
      // HF129: The activation secret never leaves the license-server process. It
      // is issued and consumed inside this single loopback-only request.
      $bootstrap=new BootstrapActivationService($pdo,$audit);
      $data['license_key']=$bootstrap->issue('local-enterprise-setup',(string)$data['installation_id']);
      $svc=new ActivationService($pdo,$licenses,$audit);$payload=$svc->activate($data,(string)($req??''));$payload['server_key_id']=$signer->keyId();$payload['server_public_key']=$signer->publicKeyEncoded();$res->send(true,$payload,null,200,$req);
   }
   if($method==='POST'&&$path==='/api/v1/activation/request'){
      $svc=new ActivationService($pdo,$licenses,$audit);$payload=$svc->activate($data,(string)($req??''));$payload['server_key_id']=$signer->keyId();$payload['server_public_key']=$signer->publicKeyEncoded();$res->send(true,$payload,null,200,$req);
   }
   $verifier=new RequestVerifier($pdo,(int)$cfg['app']['clock_skew_seconds']);$inst=$verifier->verify($method,$path,$headers,$body);$tokens=new DataFormTokenService($pdo,$signer,$licenses);$runtime=new RuntimeService($pdo,$licenses,$audit,new DataFormCompiler(),new ActionResolver(),$signer,$surface,$tokens,$cfg['runtime']);$designer=new DesignerService($pdo,$licenses,$audit,new DefinitionValidator(),$tokens);$registry=new DataFormRegistryService($pdo,$licenses,$audit,$surface,$tokens);
   if($method==='POST'&&$path==='/api/v1/dataforms/register')$res->send(true,$registry->register($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/dataforms/release')$res->send(true,$registry->release($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/dataforms/token')$res->send(true,$tokens->forInstallation($inst,(string)($data['project_id']??''),(string)($data['dataform_id']??'')),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/dataforms/policy')$res->send(true,$registry->policy($inst,$data),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/runtime/start')$res->send(true,$runtime->start($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/runtime/renew')$res->send(true,$runtime->renew($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/runtime/action')$res->send(true,$runtime->action($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/runtime/action/result')$res->send(true,$runtime->actionResult($inst,$data),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/runtime/end')$res->send(true,$runtime->end($inst,$data),null,200,$req);
   if($method==='POST'&&str_starts_with($path,'/api/v1/designer/'))$surface->assertForLicense((string)$inst['license_id'],$data);
   if($method==='POST'&&$path==='/api/v1/designer/validate')$res->send(true,$designer->validate($inst,$data),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/designer/compile')$res->send(true,$designer->compile($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/designer/publish')$res->send(true,$designer->publish($inst,$data,(string)$req),null,200,$req);
   if($method==='POST'&&$path==='/api/v1/designer/revisions')$res->send(true,$designer->revisions($inst,$data),null,200,$req);
   $res->send(false,[],['code'=>'NOT_FOUND','message'=>'Endpoint nicht gefunden.'],404,$req);
  }catch(\JsonException){$res->send(false,[],['code'=>'REQUEST_INVALID','message'=>'Ungültige JSON-Anfrage.'],400,$req);}catch(\Throwable $e){$code=$e->getMessage();$known=preg_match('/^[A-Z0-9_]{3,80}$/',$code)?$code:'SERVER_INTERNAL_ERROR';$status=$known==='SERVICE_MAINTENANCE'?503:(in_array($known,['REQUEST_INVALID','REQUEST_EXPIRED','REQUEST_SIGNATURE_INVALID','NONCE_REUSED'],true)?400:(str_starts_with($known,'LICENSE_')||str_starts_with($known,'INSTALLATION_')||str_starts_with($known,'MODULE_')||str_starts_with($known,'RUNTIME_')||str_starts_with($known,'ACTION_')||str_starts_with($known,'RELATION_')||str_starts_with($known,'DATAFORM_')||str_starts_with($known,'CAPABILITY_')||str_starts_with($known,'ACCOUNT_')||str_starts_with($known,'PROJECT_PRODUCT_')?403:500));$res->send(false,[],['code'=>$known,'message'=>self::publicMessage($known)],$status,$req);}
 }
 private static function publicMessage(string $code):string{return match($code){'INSTALLATION_TERMINAL'=>'Diese Installation wurde dauerhaft stillgelegt oder ersetzt und kann nicht erneut aktiviert werden.','INSTALLATION_TRANSITION_INVALID'=>'Der angeforderte Installationsstatus ist aus dem aktuellen Zustand nicht zulässig.','INSTALLATION_NOT_FOUND'=>'Die Installation wurde nicht gefunden.','INSTALLATION_REQUIRED'=>'Eine Installation muss angegeben werden.','LICENSE_EXPIRED'=>'Die Lizenz ist abgelaufen.','LICENSE_SUSPENDED'=>'Die Lizenz ist gesperrt.','LICENSE_REVOKED'=>'Die Lizenz wurde widerrufen.','MODULE_NOT_LICENSED'=>'Das angeforderte Modul ist nicht lizenziert.','CAPABILITY_NOT_LICENSED'=>'Diese DataForm-Funktion ist in der Lizenz nicht freigegeben.','DATAFORM_LIMIT_REACHED'=>'Die lizenzierte Anzahl verwendbarer DataForms ist erreicht.','DATAFORM_UUID_INVALID'=>'Die DataForm-ID ist ungültig.','DATAFORM_IDENTITY_CONFLICT'=>'Die DataForm-Identität steht im Konflikt mit einer bestehenden Registrierung.','DATAFORM_REGISTRATION_REVOKED'=>'Diese DataForm-Registrierung wurde widerrufen oder freigegeben.','DATAFORM_REGISTRATION_NOT_FOUND'=>'Die DataForm-Registrierung wurde nicht gefunden.','DATAFORM_STATUS_CONFLICT'=>'Der DataForm-Lizenzstatus wurde gleichzeitig geändert.','DATAFORM_NOT_APPROVED'=>'Dieses DataForm wurde noch nicht manuell freigegeben.','DATAFORM_INSTALLATION_MISMATCH'=>'Dieses DataForm ist an eine andere Installation gebunden.','DATAFORM_TOKEN_MISSING'=>'Für dieses DataForm fehlt der signierte Lizenz-Token.','DATAFORM_TOKEN_INVALID'=>'Der DataForm-Lizenz-Token ist ungültig.','DATAFORM_TOKEN_UNTRUSTED'=>'Der DataForm-Lizenz-Token stammt nicht vom erwarteten Lizenzserver-Schlüssel.','DATAFORM_TOKEN_BINDING_INVALID'=>'Der DataForm-Lizenz-Token passt nicht zu dieser DataForm-Identität.','DATAFORM_SLOT_NOT_ACTIVE'=>'Der lizenzierte DataForm-Platz ist nicht mehr aktiv.','DATAFORM_TOKEN_STATE_INVALID'=>'Der DataForm-Lizenzstatus ist für die Token-Ausgabe unvollständig.','INSTALLATION_LIMIT_REACHED'=>'Die maximale Anzahl aktivierter Installationen ist erreicht.','INSTALLATION_BLOCKED'=>'Diese Installation ist gesperrt.','SURFACE_DOMAIN_REQUIRED'=>'Die aufrufende Domain fehlt.','SURFACE_DOMAIN_NOT_CONFIGURED'=>'Für den Kunden ist noch keine Domain freigegeben.','SURFACE_DOMAIN_NOT_ALLOWED'=>'Diese Domain ist für die DataForm-Oberfläche nicht freigegeben.','SURFACE_IP_NOT_CONFIGURED'=>'Für den Kunden ist noch kein IP-Netz freigegeben.','SURFACE_IP_NOT_ALLOWED'=>'Diese Quell-IP ist nicht freigegeben.','SERVICE_MAINTENANCE'=>'Der Dienst befindet sich im Wartungsmodus.','ACCOUNT_REQUEST_PENDING'=>'Ihre Zugangsanforderung wartet auf Freigabe.','ACCOUNT_REQUEST_REJECTED'=>'Ihre Zugangsanforderung wurde abgelehnt.','ACCOUNT_NOT_ACTIVE'=>'Das Kundenkonto ist noch nicht freigegeben.','ACCOUNT_LICENSE_PENDING'=>'Für dieses Konto wurde noch keine Lizenz freigegeben.','ACCOUNT_LICENSE_REQUIRED'=>'Diese Funktion benötigt eine gültige Lizenz; das Konto bleibt im Demo-Modus angemeldet.','ACCOUNT_LOCKED'=>'Das Konto ist vorübergehend gesperrt.','ACCOUNT_LOGIN_INVALID'=>'E-Mail-Adresse oder Kennwort ist falsch.','ACCOUNT_ALREADY_EXISTS'=>'Für diese E-Mail-Adresse besteht bereits ein Konto.','ACCOUNT_EMAIL_INVALID'=>'Die E-Mail-Adresse ist ungültig.','ACCOUNT_COMPANY_REQUIRED'=>'Firmenname ist erforderlich.','ACCOUNT_NAME_REQUIRED'=>'Vor- und Nachname sind erforderlich.','ACCOUNT_PASSWORD_MISMATCH'=>'Die Kennwörter stimmen nicht überein.','ACCOUNT_PASSWORD_WEAK'=>'Das Kennwort muss mindestens 10 Zeichen, Groß-/Kleinbuchstaben und eine Ziffer enthalten.','ACCOUNT_REQUEST_RATE_LIMIT'=>'Zu viele Zugangsanforderungen. Bitte später erneut versuchen.','ACCOUNT_SESSION_INVALID'=>'Die Kundenanmeldung ist ungültig.','ACCOUNT_SESSION_EXPIRED'=>'Die Kundenanmeldung ist abgelaufen.','LICENSE_REQUEST_PERMISSION_DENIED'=>'Nur ein Kundenadministrator darf Lizenzanfragen stellen.','LICENSE_REQUEST_TYPE_INVALID'=>'Der Typ der Lizenzanfrage ist ungültig.','LICENSE_REQUEST_INSTALLATIONS_INVALID'=>'Die gewünschte Anzahl Installationen ist ungültig.','LICENSE_REQUEST_DATAFORMS_INVALID'=>'Die gewünschte DataForm-Anzahl ist ungültig.','LICENSE_REQUEST_TERM_INVALID'=>'Die gewünschte Laufzeit ist ungültig.','LICENSE_REQUEST_MODULE_REQUIRED'=>'Mindestens ein Lizenzmodul muss angefordert werden.','LICENSE_REQUEST_NOTES_TOO_LONG'=>'Die Zusatzhinweise sind zu lang.','LICENSE_REQUEST_NOT_FOUND'=>'Die Lizenzanfrage wurde nicht gefunden oder ist nicht mehr offen.','PROJECT_PRODUCT_INSTALLATION_REQUIRED'=>'Die Enterprise-Installation konnte nicht eindeutig bestimmt werden.','PROJECT_PRODUCT_PROJECT_INVALID'=>'Das gewünschte Projekt ist ungültig oder unvollständig.','PROJECT_PRODUCT_NOT_AVAILABLE'=>'Dieses Produkt kann derzeit nicht für ein Projekt angefordert werden.','PROJECT_PRODUCT_ALREADY_LICENSED'=>'Dieses Produkt ist für das Projekt bereits freigegeben.','PROJECT_PRODUCT_REQUEST_NOT_FOUND'=>'Die projektbezogene Lizenzanfrage wurde nicht gefunden oder ist nicht mehr offen.','PROJECT_PRODUCT_NOT_LICENSED'=>'Dieses Produkt ist für das angegebene Projekt nicht freigegeben.','CUSTOMER_SURFACE_SELF_SERVICE_DISABLED'=>'Die Domain-/IP-Pflege ist für dieses Kundenkonto nicht freigegeben.','BOOTSTRAP_LOCAL_ONLY'=>'Die automatische Erstaktivierung ist ausschließlich direkt auf dem lokalen Lizenzserver zulässig.',default=>'Die Anfrage konnte nicht verarbeitet werden.'};}
}
