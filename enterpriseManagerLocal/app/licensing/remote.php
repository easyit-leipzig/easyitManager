<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/system/app/bootstrap.php';
require dirname(__DIR__, 2) . '/system/ui/layout.php';
require dirname(__DIR__, 2) . '/system/licensing/Autoload.php';

use EasyIT\Enterprise\Licensing\InstallationIdentity;
use EasyIT\Enterprise\Licensing\LicenseApiClient;
use EasyIT\Enterprise\Licensing\Transport;

$user=enterprise_require_auth('../../');
if(!enterprise_is_admin($user)){
    http_response_code(403);
    render_page(['title'=>'DataForm-Lizenzserver','active'=>'licensing','base'=>'../../','content'=>'<div class="notice error">Nur Administratoren dürfen die Lizenzserver-Konfiguration ändern.</div>','app_nav'=>true,'user'=>$user]);
    exit;
}

$root=dirname(__DIR__,2);
$cfg=EnterpriseLicenseConfigStore::load($root);
if(!is_array($cfg))$cfg=[];
$error='';$message='';$trustProbe=null;

$fingerprintPublicKey=static function(string $public):string{
    $decoded=base64_decode(trim($public),true);
    if($decoded===false||strlen($decoded)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)return 'ungültig';
    return strtoupper(implode(':',str_split(hash('sha256',$decoded),2)));
};

$writeConfig=static function(array $newCfg)use($root):void{ EnterpriseLicenseConfigStore::save($root,$newCfg); };

$validateServerUrl=static function(string $url):array{
    $url=rtrim(trim($url),'/');
    if($url==='')throw new RuntimeException('Lizenzserver-URL fehlt.');
    $parts=parse_url($url);
    if(!is_array($parts)||empty($parts['scheme'])||empty($parts['host']))throw new RuntimeException('Lizenzserver-URL ist ungültig.');
    $local=in_array(strtolower((string)$parts['host']),['localhost','127.0.0.1','::1'],true);
    if(strtolower((string)$parts['scheme'])!=='https'&&!$local)throw new RuntimeException('Für entfernte Lizenzserver ist HTTPS zwingend.');
    return[$url,$local];
};

$discoverServerKey=static function(string $url,int $timeout,bool $verifyTls)use($validateServerUrl):array{
    [$url,$local]=$validateServerUrl($url);
    $verify=!$local||$verifyTls;
    [$status,$headers,$raw]=Transport::get($url.'/api/v1/health',['Accept: application/json'],$timeout,$verify);
    $data=json_decode($raw,true);
    if(!is_array($data)||($data['success']??false)!==true)throw new RuntimeException('Der Lizenzserver antwortet nicht mit einer gültigen Health-Antwort.');
    $bundle=$data['server_trust']??null;
    if(!is_array($bundle)||(string)($bundle['schema']??'')!=='easyit.license.server-trust.v1')throw new RuntimeException('Der Lizenzserver stellt keinen gültigen Vertrauensschlüssel bereit.');
    $keyId=trim((string)($bundle['active_key_id']??''));$public='';
    foreach((array)($bundle['keys']??[]) as $row){
        if(is_array($row)&&(string)($row['key_id']??'')===$keyId&&(string)($row['status']??'')==='active'){$public=trim((string)($row['public_key']??''));break;}
    }
    $decoded=base64_decode($public,true);
    if($keyId===''||$decoded===false||strlen($decoded)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('Der Lizenzserver liefert keinen verwendbaren aktiven Ed25519-Schlüssel.');
    $headerKeyId=trim((string)($headers['x-easyit-server-key-id']??''));
    $signature=base64_decode(trim((string)($headers['x-easyit-server-signature']??'')),true);
    if($headerKeyId!==$keyId||$signature===false||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||!sodium_crypto_sign_verify_detached($signature,$raw,$decoded)){
        throw new RuntimeException('Die Health-Antwort ist nicht mit dem darin veröffentlichten Server-Schlüssel signiert.');
    }
    return['server_url'=>$url,'local'=>$local,'verify_tls'=>$verify,'key_id'=>$keyId,'public_key'=>$public,'fingerprint'=>strtoupper(implode(':',str_split(hash('sha256',$decoded),2)))];
};

try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        enterprise_check_csrf((string)($_POST['csrf']??''));
        $action=(string)($_POST['action']??'');
        if(in_array($action,['save_remote','discover_remote','probe_trust','replace_trust'],true)){
            $url=(string)($_POST['server_url']??($cfg['server_url']??''));
            [$url,$local]=$validateServerUrl($url);
            $connect=max(1,min(30,(int)($_POST['connect_timeout']??($cfg['connect_timeout']??5))));
            $request=max(2,min(120,(int)($_POST['request_timeout']??($cfg['request_timeout']??15))));
            $verifyTls=!$local||isset($_POST['verify_tls']);
            $existing=(array)($cfg['trusted_server_keys']??[]);

            if($action==='probe_trust'){
                $trustProbe=$discoverServerKey($url,$request,$verifyTls);
                $message='Aktueller Server-Schlüssel wurde gelesen und kryptografisch gegen die Health-Antwort geprüft. Es wurde noch nichts gespeichert.';
            }elseif($action==='replace_trust'){
                $confirmation=trim((string)($_POST['trust_confirmation']??''));
                if($confirmation!=='NEU KOPPELN')throw new RuntimeException('Zum Ersetzen des Vertrauensankers muss exakt „NEU KOPPELN“ eingegeben werden.');
                $discovered=$discoverServerKey($url,$request,$verifyTls);
                $expected=trim((string)($_POST['expected_fingerprint']??''));
                if($expected===''||!hash_equals($expected,(string)$discovered['fingerprint']))throw new RuntimeException('Der Server-Schlüssel hat sich seit der Prüfung geändert. Bitte erneut prüfen und erst danach koppeln.');
                $kid=(string)$discovered['key_id'];$pub=(string)$discovered['public_key'];
                $cfg=[
                    'enabled'=>true,
                    'server_url'=>$url,
                    'connect_timeout'=>$connect,
                    'request_timeout'=>$request,
                    'verify_tls'=>(bool)$discovered['verify_tls'],
                    'storage_dir'=>(string)($cfg['storage_dir']??($root.'/storage/licensing')),
                    'trusted_server_keys'=>[$kid=>$pub],
                ];
                $writeConfig($cfg);
                $identity=new InstallationIdentity((string)$cfg['storage_dir']);
                $state=$identity->state();
                $oldIds=array_values(array_map('strval',array_keys($existing)));
                $history=is_array($state['server_trust_repair_history']??null)?$state['server_trust_repair_history']:[];
                $history[]=['at'=>time(),'old_key_ids'=>$oldIds,'new_key_id'=>$kid,'new_fingerprint'=>(string)$discovered['fingerprint']];
                $state['server_trust_repair_history']=array_slice($history,-20);
                unset($state['dynamic_server_keys'],$state['revoked_server_key_ids'],$state['active_server_key_id'],$state['server_trust_updated_at']);
                $state['active_server_key_id']=$kid;
                $state['server_trust_updated_at']=time();
                $state['last_server_decision']='SERVER_TRUST_REPAIRED';
                $identity->saveState($state);
                $message='Lizenzserver wurde ausdrücklich neu gekoppelt. Neuer SHA-256-Fingerprint: '.htmlspecialchars((string)$discovered['fingerprint'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            }else{
                $kid=trim((string)($_POST['server_key_id']??''));
                $pub=trim((string)($_POST['server_public_key']??''));
                $auto=$action==='discover_remote'||$kid===''||$pub==='';
                if($auto){
                    $discovered=$discoverServerKey($url,$request,$verifyTls);
                    $kid=(string)$discovered['key_id'];$pub=(string)$discovered['public_key'];$verifyTls=(bool)$discovered['verify_tls'];
                }
                if($kid===''||$pub==='')throw new RuntimeException('Server-Key-ID und öffentlicher Server-Schlüssel sind erforderlich.');
                $decoded=base64_decode($pub,true);
                if($decoded===false||strlen($decoded)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('Der öffentliche Ed25519-Schlüssel ist ungültig.');
                if($existing){
                    $same=isset($existing[$kid])&&hash_equals((string)$existing[$kid],$pub);
                    if(!$same)throw new RuntimeException('Der Lizenzserver verwendet einen anderen Vertrauensschlüssel. Aus Sicherheitsgründen wird er nicht automatisch ersetzt. Verwenden Sie unten „Vertrauensbeziehung prüfen und neu koppeln“.');
                }
                $cfg=[
                    'enabled'=>true,
                    'server_url'=>$url,
                    'connect_timeout'=>$connect,
                    'request_timeout'=>$request,
                    'verify_tls'=>$verifyTls,
                    'storage_dir'=>(string)($cfg['storage_dir']??($root.'/storage/licensing')),
                    'trusted_server_keys'=>[$kid=>$pub],
                ];
                $writeConfig($cfg);
                $fingerprint=strtoupper(implode(':',str_split(hash('sha256',$decoded),2)));
                $message=($auto?'Lizenzserver geprüft und Vertrauensschlüssel übernommen.':'Lizenzserver und Vertrauensschlüssel wurden gespeichert.').' SHA-256: '.htmlspecialchars($fingerprint,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            }
        }elseif($action==='activate'){
            $licenseKey=trim((string)($_POST['license_key']??''));
            if($licenseKey==='')throw new RuntimeException('Lizenzschlüssel fehlt.');
            $cfg=EnterpriseLicenseConfigStore::load($root);
            $api=new LicenseApiClient($cfg);
            $payload=$api->activate($licenseKey,trim((string)@file_get_contents($root.'/VERSION'))?:'unknown');
            $message='Installation wurde am zentralen Lizenzserver aktiviert: '.htmlspecialchars((string)($payload['license']['number']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
            $licenseKey='';
        }
        $cfg=EnterpriseLicenseConfigStore::load($root);
    }
}catch(Throwable $e){$error=$e->getMessage();}

$state=[];$identity=null;
try{
    $identity=new InstallationIdentity((string)($cfg['storage_dir']??($root.'/storage/licensing')));
    $state=$identity->state();
}catch(Throwable $e){if($error==='')$error=$e->getMessage();}
$keys=(array)($cfg['trusted_server_keys']??[]);$keyId=(string)(array_key_first($keys)??'');$publicKey=$keyId!==''?(string)$keys[$keyId]:'';
$currentFingerprint=$publicKey!==''?$fingerprintPublicKey($publicKey):'—';
$policy=is_array($state['license_policy']??null)?$state['license_policy']:[];
$policyCaps=array_values(array_map('strval',(array)($policy['capabilities']??[])));
$dynamicKeys=(array)($state['dynamic_server_keys']??[]);
$revokedKeyIds=array_values(array_map('strval',(array)($state['revoked_server_key_ids']??[])));
$trustedKeyIds=array_values(array_unique(array_merge(array_map('strval',array_keys((array)($cfg['trusted_server_keys']??[]))),array_map('strval',array_keys($dynamicKeys)))));
$trustedKeyIds=array_values(array_filter($trustedKeyIds,static fn(string $id):bool=>!in_array($id,$revokedKeyIds,true)));sort($trustedKeyIds);

ob_start();
render_breadcrumbs([
    ['label'=>'Enterprise','href'=>'../dashboard.php'],
    ['label'=>'Lizenzverwaltung','href'=>'index.php'],
    ['label'=>'DataForm-Lizenzserver','href'=>''],
]);
?>
<section class="hero"><span class="badge">DataForm5 · Servergebundene Lizenz</span><h1>DataForm-Lizenzserver</h1><p>DataForm-Runtime und Designer werden erst nach kryptografisch bestätigter Lizenzfreigabe verwendet.</p></section>
<?php if($message):?><div class="notice success"><?=$message?></div><?php endif;?>
<?php if($error):?><div class="notice error"><?=e($error)?></div><?php endif;?>

<div class="metric-grid">
 <div class="metric"><strong><?=!empty($state['activated'])?'aktiviert':'nicht aktiviert'?></strong><span>Installation</span></div>
 <div class="metric"><strong><?=e((string)($state['license']['number']??'—'))?></strong><span>Zentrale Lizenz</span></div>
 <div class="metric"><strong><?=e(implode(', ',array_map('strval',(array)($state['modules']??[])))?:'—')?></strong><span>Module</span></div>
 <div class="metric"><strong><?=e((string)($state['last_server_decision']??'—'))?></strong><span>letzte Serverentscheidung</span></div>
</div>

<section class="card"><h2>Lizenzumfang</h2><table><tbody>
<tr><th>DataForms</th><td><?php if(array_key_exists('max_dataforms',$policy)): ?><?=e((string)($policy['used_dataforms']??0))?> / <?=($policy['max_dataforms']===null?'unbegrenzt':e((string)$policy['max_dataforms']))?><?php else: ?>—<?php endif; ?></td></tr>
<tr><th>Verfügbare DataForm-Slots</th><td><?=array_key_exists('available_dataforms',$policy)?($policy['available_dataforms']===null?'unbegrenzt':e((string)$policy['available_dataforms'])):'—'?></td></tr>
<tr><th>Capability-Policy</th><td><?=e((string)($policy['capability_policy']??'—'))?></td></tr>
<tr><th>Freigegebene Funktionen</th><td><?=e($policyCaps?implode(', ',$policyCaps):((string)($policy['capability_policy']??'')==='legacy_unrestricted'?'alle (Legacy)':'—'))?></td></tr>
</tbody></table></section>

<section class="card"><h2>1. Lizenzserver konfigurieren</h2>
<form method="post" class="form-grid">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<label class="full">Server-URL<input name="server_url" required value="<?=e((string)($cfg['server_url']??''))?>" placeholder="https://license.example.de"></label>
<label>Server-Key-ID<input name="server_key_id" value="<?=e($keyId)?>" placeholder="wird automatisch geladen"></label>
<label>Connect-Timeout (s)<input type="number" min="1" max="30" name="connect_timeout" value="<?=e((string)($cfg['connect_timeout']??5))?>"></label>
<label>Request-Timeout (s)<input type="number" min="2" max="120" name="request_timeout" value="<?=e((string)($cfg['request_timeout']??15))?>"></label>
<label><input type="checkbox" name="verify_tls" value="1" <?=!empty($cfg['verify_tls'])?'checked':''?>> TLS-Zertifikat prüfen</label>
<label class="full">Öffentlicher Ed25519-Server-Schlüssel<textarea name="server_public_key" rows="4" placeholder="wird automatisch vom Lizenzserver geladen"><?=e($publicKey)?></textarea></label><p class="full">Ist noch kein Vertrauensschlüssel eingetragen, lädt der Manager ihn über die Health-Schnittstelle des Lizenzservers. Bei entfernten Servern ist dafür HTTPS mit Zertifikatsprüfung zwingend.</p>
<div class="full"><button class="button" type="submit" name="action" value="discover_remote">Server prüfen &amp; Schlüssel laden</button> <button class="button" type="submit" name="action" value="save_remote">Serverkonfiguration speichern</button></div>
</form></section>

<section class="card"><h2>Vertrauensbeziehung prüfen und neu koppeln</h2>
<p>Dieser Vorgang ist nur erforderlich, wenn der Lizenzserver neu installiert wurde oder sein Server-Signaturschlüssel bewusst ersetzt wurde. Ein geänderter Schlüssel wird niemals automatisch vertraut.</p>
<table><tbody>
<tr><th>Aktuell vertraute Key-ID</th><td><code><?=e($keyId?:'—')?></code></td></tr>
<tr><th>Aktueller SHA-256-Fingerprint</th><td><code><?=e($currentFingerprint)?></code></td></tr>
</tbody></table>
<form method="post" class="form-grid">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="server_url" value="<?=e((string)($cfg['server_url']??''))?>">
<input type="hidden" name="connect_timeout" value="<?=e((string)($cfg['connect_timeout']??5))?>">
<input type="hidden" name="request_timeout" value="<?=e((string)($cfg['request_timeout']??15))?>">
<?php if(!empty($cfg['verify_tls'])):?><input type="hidden" name="verify_tls" value="1"><?php endif;?>
<div class="full"><button class="button secondary" type="submit" name="action" value="probe_trust">Aktuellen Server-Schlüssel prüfen</button></div>
</form>
<?php if(is_array($trustProbe)):?>
<div class="notice warning">
<strong>Gefundener Server-Schlüssel – noch nicht gespeichert</strong>
<table><tbody>
<tr><th>Server</th><td><?=e((string)$trustProbe['server_url'])?></td></tr>
<tr><th>Key-ID</th><td><code><?=e((string)$trustProbe['key_id'])?></code></td></tr>
<tr><th>SHA-256-Fingerprint</th><td><code><?=e((string)$trustProbe['fingerprint'])?></code></td></tr>
</tbody></table>
</div>
<form method="post" class="form-grid">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>">
<input type="hidden" name="action" value="replace_trust">
<input type="hidden" name="server_url" value="<?=e((string)$trustProbe['server_url'])?>">
<input type="hidden" name="connect_timeout" value="<?=e((string)($cfg['connect_timeout']??5))?>">
<input type="hidden" name="request_timeout" value="<?=e((string)($cfg['request_timeout']??15))?>">
<input type="hidden" name="expected_fingerprint" value="<?=e((string)$trustProbe['fingerprint'])?>">
<?php if(!empty($trustProbe['verify_tls'])):?><input type="hidden" name="verify_tls" value="1"><?php endif;?>
<label class="full">Bestätigung<input name="trust_confirmation" autocomplete="off" placeholder="NEU KOPPELN" required><small>Geben Sie exakt <strong>NEU KOPPELN</strong> ein. Dadurch wird ausschließlich der Server-Vertrauensanker ersetzt; die lokale Installationsidentität bleibt erhalten.</small></label>
<div class="full"><button class="button danger" type="submit">Vertrauensanker ersetzen</button></div>
</form>
<?php endif;?>
</section>

<?php if(empty($state['activated'])): ?>
<section class="card"><h2>2. Recovery: Installation manuell aktivieren</h2>
<p>Dieser Bereich ist nur für Recovery, Serverwechsel oder eine ausdrücklich manuell durchgeführte Aktivierung vorgesehen. Die frühere Recovery-Funktion verwendet einen Bootstrap-Aktivierungsschlüssel ohne Kundenkonto. Bei einer normalen Neuinstallation erledigt das Setup Vertrauensaufbau und Bootstrap-Aktivierung automatisch in einem Schritt; ein Aktivierungsschlüssel wird dabei weder angezeigt noch kopiert.</p>
<form method="post" class="form-grid">
<input type="hidden" name="csrf" value="<?=e(enterprise_csrf())?>"><input type="hidden" name="action" value="activate">
<label class="full">Recovery-Aktivierungsschlüssel<input type="password" autocomplete="off" name="license_key" required></label>
<div class="full"><button class="button" type="submit">Recovery-Aktivierung ausführen</button></div>
</form></section>
<?php endif; ?>

<section class="card"><h2>Lokaler Zustand</h2><table><tbody>
<tr><th>Server</th><td><?=e((string)($cfg['server_url']??'—'))?></td></tr>
<tr><th>Installation-ID</th><td><?=e((string)($state['installation_id']??'wird bei Aktivierung erzeugt'))?></td></tr>
<tr><th>Generation</th><td><?=e((string)($state['installation_generation']??'—'))?></td></tr>
<tr><th>Letzter erfolgreicher Kontakt</th><td><?=!empty($state['last_successful_contact'])?e(date('Y-m-d H:i:s',(int)$state['last_successful_contact'])):'—'?></td></tr>
<tr><th>Aktiver Server-Signaturschlüssel</th><td><code><?=e((string)($state['active_server_key_id']??$keyId??'—'))?></code></td></tr>
<tr><th>Vertrauenswürdige Server-Schlüssel</th><td><?=e($trustedKeyIds?implode(', ',$trustedKeyIds):'—')?></td></tr>
<tr><th>Widerrufene Server-Schlüssel</th><td><?=e($revokedKeyIds?implode(', ',$revokedKeyIds):'—')?></td></tr>
</tbody></table></section>
<?php
$content=ob_get_clean();
render_page(['title'=>'DataForm-Lizenzserver','active'=>'licensing','base'=>'../../','content'=>$content,'app_nav'=>true,'user'=>$user]);
