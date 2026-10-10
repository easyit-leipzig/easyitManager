<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;

final class LicenseApiClient
{
    private InstallationIdentity $identity;

    public function __construct(private array $cfg)
    {
        $this->identity = new InstallationIdentity((string)$cfg['storage_dir']);
    }

    public function registerAccount(array $payload): array
    {
        $payload['_surface']=$this->surfaceContext();
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $resp=$this->rawPost('/api/v1/account/register',$body,[]);
        return (array)($resp['body']['payload']??[]);
    }

    /** HF87 compatibility alias for older callers. */
    public function requestAccount(array $payload): array
    {
        return $this->registerAccount($payload);
    }

    public function loginAccount(string $email,string $password): array
    {
        $installationId=$this->installationId();
        $body=json_encode([
            'email'=>$email,
            'password'=>$password,
            'installation_id'=>$installationId,
            '_surface'=>$this->surfaceContext(),
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $resp=$this->rawPost('/api/v1/account/login',$body,[]);
        $payload=(array)($resp['body']['payload']??[]);
        $license=$payload['license']??null;
        $binding=(array)($payload['installation_binding']??[]);
        if(is_array($license) && !empty($license['number']) && in_array((string)($binding['status']??''),['bound','already_bound'],true)){
            $state=$this->identity->state();
            $this->identity->saveState(array_merge($state,[
                'activated'=>true,
                'license'=>$license,
                'installation_id'=>$installationId,
                'modules'=>(array)($payload['modules']??[]),
                'license_policy'=>(array)($payload['license_policy']??[]),
                'activation_mode'=>'customer_license',
                'last_successful_contact'=>time(),
                'last_trusted_server_time'=>(int)($resp['body']['server_time']??time()),
                'last_server_decision'=>'ACTIVE',
            ]));
        }
        return $payload;
    }

    public function accountProfile(string $sessionToken): array
    {
        return $this->accountSessionPost('/api/v1/account/profile',$sessionToken,[]);
    }

    public function updateAccountProfile(string $sessionToken,array $profile): array
    {
        return $this->accountSessionPost('/api/v1/account/profile/update',$sessionToken,$profile);
    }

    public function submitAccountLicenseRequest(string $sessionToken,array $request): array
    {
        return $this->accountSessionPost('/api/v1/account/license/request',$sessionToken,$request);
    }

    public function cancelAccountLicenseRequest(string $sessionToken,string $requestId): array
    {
        return $this->accountSessionPost('/api/v1/account/license/request/cancel',$sessionToken,['request_id'=>$requestId]);
    }

    public function accountProjectProducts(string $sessionToken): array
    {
        return $this->accountSessionPost('/api/v1/account/project-products',$sessionToken,[
            'installation_id'=>$this->installationId(),
        ]);
    }

    public function requestAccountProjectProduct(string $sessionToken,array $request): array
    {
        $request['installation_id']=$this->installationId();
        return $this->accountSessionPost('/api/v1/account/project-product/request',$sessionToken,$request);
    }

    public function cancelAccountProjectProductRequest(string $sessionToken,string $requestId): array
    {
        return $this->accountSessionPost('/api/v1/account/project-product/request/cancel',$sessionToken,[
            'installation_id'=>$this->installationId(),
            'request_id'=>$requestId,
        ]);
    }

    public function accountProjectProductToken(string $sessionToken,string $projectId,string $projectSlug,string $productCode,string $customerKey=''): array
    {
        return $this->accountSessionPost('/api/v1/account/project-product/token',$sessionToken,[
            'installation_id'=>$this->installationId(),
            'project_id'=>$projectId,
            'project_slug'=>$projectSlug,
            'product_code'=>$productCode,
            'customer_key'=>$customerKey,
        ]);
    }

    public function changeAccountPassword(string $sessionToken,string $currentPassword,string $newPassword,string $confirmation): array
    {
        return $this->accountSessionPost('/api/v1/account/password/change',$sessionToken,[
            'current_password'=>$currentPassword,
            'new_password'=>$newPassword,
            'new_password_confirm'=>$confirmation,
        ]);
    }

    public function addAccountDomain(string $sessionToken,string $domain): array
    {
        return $this->accountSessionPost('/api/v1/account/domain/add',$sessionToken,['domain'=>$domain]);
    }

    public function removeAccountDomain(string $sessionToken,string $ruleId): array
    {
        return $this->accountSessionPost('/api/v1/account/domain/remove',$sessionToken,['rule_id'=>$ruleId]);
    }

    public function addAccountNetwork(string $sessionToken,string $cidr): array
    {
        return $this->accountSessionPost('/api/v1/account/network/add',$sessionToken,['cidr'=>$cidr]);
    }

    public function removeAccountNetwork(string $sessionToken,string $ruleId): array
    {
        return $this->accountSessionPost('/api/v1/account/network/remove',$sessionToken,['rule_id'=>$ruleId]);
    }

    public function retireAccountInstallation(string $sessionToken,string $installationId): array
    {
        $result=$this->accountSessionPost('/api/v1/account/installation/retire',$sessionToken,['installation_id'=>$installationId]);
        $current=$this->identity->ensure();
        if(hash_equals((string)$current['installation_id'],$installationId)){
            $state=$this->identity->state();$state['activated']=false;$state['installation_status']='retired';$state['last_server_decision']='INSTALLATION_RETIRED';$state['last_successful_contact']=time();$this->identity->saveState($state);
            $dir=rtrim((string)$this->cfg['storage_dir'],'/\\').DIRECTORY_SEPARATOR.'runtime-cache';
            if(is_dir($dir)){foreach(glob($dir.DIRECTORY_SEPARATOR.'*.json')?:[] as $file)@unlink($file);}
        }
        return $result;
    }

    public function installationId(): string
    {
        return (string)$this->identity->ensure()['installation_id'];
    }

    public function accountTeam(string $sessionToken): array
    {
        return $this->accountSessionPost('/api/v1/account/team',$sessionToken,[]);
    }

    public function createAccountTeamUser(string $sessionToken,array $data): array
    {
        return $this->accountSessionPost('/api/v1/account/team/user/create',$sessionToken,$data);
    }

    public function setAccountTeamUser(string $sessionToken,array $data): array
    {
        return $this->accountSessionPost('/api/v1/account/team/user/set',$sessionToken,$data);
    }

    public function resetAccountTeamUserPassword(string $sessionToken,string $customerUserId,string $newPassword,string $confirmation): array
    {
        return $this->accountSessionPost('/api/v1/account/team/user/password-reset',$sessionToken,[
            'customer_user_id'=>$customerUserId,
            'new_password'=>$newPassword,
            'new_password_confirm'=>$confirmation,
        ]);
    }

    public function saveAccountTeamRole(string $sessionToken,array $data): array
    {
        return $this->accountSessionPost('/api/v1/account/team/role/save',$sessionToken,$data);
    }

    public function logoutAccount(string $sessionToken): void
    {
        try{$this->accountSessionPost('/api/v1/account/logout',$sessionToken,[]);}catch(\Throwable){}
    }

    private function accountSessionPost(string $path,string $sessionToken,array $payload): array
    {
        $payload['account_session_token']=$sessionToken;$payload['_surface']=$this->surfaceContext();
        $body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $resp=$this->rawPost($path,$body,[]);return (array)($resp['body']['payload']??[]);
    }

    public function registerDataForm(string $projectId,string $localDataformId,string $dataformUuid,string $name,string $slug): array
    {
        return $this->post('/api/v1/dataforms/register',[
            'project_id'=>$projectId,
            'local_dataform_id'=>$localDataformId,
            'dataform_uuid'=>$dataformUuid,
            'name'=>$name,
            'slug'=>$slug,
        ]);
    }

    public function dataFormToken(string $projectId,string $localDataformId): array
    {
        return $this->post('/api/v1/dataforms/token',[
            'project_id'=>$projectId,
            'dataform_id'=>$localDataformId,
        ]);
    }

    /** HF86 Phase 2: current effective capabilities for one approved DataForm. */
    public function dataFormPolicy(string $projectId,string $localDataformId,string $dataformToken): array
    {
        return $this->post('/api/v1/dataforms/policy',[
            'project_id'=>$projectId,
            'dataform_id'=>$localDataformId,
            'df_license_token'=>$dataformToken,
        ]);
    }

    /** HF85 Phase 4: central release before local DataForm deletion. */
    public function releaseDataForm(string $projectId,string $localDataformId,string $dataformUuid,string $name,string $slug): array
    {
        $result=$this->post('/api/v1/dataforms/release',[
            'project_id'=>$projectId,
            'local_dataform_id'=>$localDataformId,
            'dataform_uuid'=>$dataformUuid,
            'name'=>$name,
            'slug'=>$slug,
        ]);
        $state=$this->identity->state();
        $released=is_array($state['released_dataforms']??null)?$state['released_dataforms']:[];
        $released[hash('sha256',$projectId.'|'.$localDataformId)]=[
            'released_at'=>(int)($result['released_at']??time()),
            'dataform_uuid'=>$dataformUuid,
        ];
        $state['released_dataforms']=$released;
        $this->identity->saveState($state);
        return $result;
    }

    /** HF129: one-step local bootstrap without exposing/copying an activation key. */
    public function bootstrapLocalActivate(string $productVersion = 'unknown'): array
    {
        $id = $this->identity->ensure();
        $body = json_encode([
            'installation_id' => $id['installation_id'],
            'installation_public_key' => $id['public_key'],
            'product' => ['code' => 'easyIT-enterprise', 'version' => $productVersion],
            'environment' => ['hash' => 'sha256:' . hash('sha256', PHP_OS_FAMILY . '|' . PHP_VERSION)],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $resp = $this->rawPost('/api/v1/bootstrap/local-activate', $body, []);
        $payload = $resp['body']['payload'] ?? [];
        $existingState=$this->identity->state();
        $this->identity->saveState(array_merge($existingState,[
            'activated' => true,
            'activation_mode' => 'bootstrap',
            'license' => $payload['license'] ?? null,
            'installation_id' => $id['installation_id'],
            'installation_key_id' => $payload['installation_key_id'] ?? null,
            'installation_generation' => $payload['installation_generation'] ?? null,
            'modules' => $payload['modules'] ?? [],
            'license_policy' => $payload['license_policy'] ?? [],
            'last_successful_contact' => time(),
            'last_trusted_server_time' => (int)($resp['body']['server_time'] ?? time()),
            'last_server_decision' => 'ACTIVE',
        ]));
        return $payload;
    }

    public function activate(string $licenseKey, string $productVersion = 'unknown'): array
    {
        $id = $this->identity->ensure();
        $body = json_encode([
            'license_key' => $licenseKey,
            'installation_id' => $id['installation_id'],
            'installation_public_key' => $id['public_key'],
            'product' => ['code' => 'easyIT-enterprise', 'version' => $productVersion],
            'environment' => ['hash' => 'sha256:' . hash('sha256', PHP_OS_FAMILY . '|' . PHP_VERSION)],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $resp = $this->rawPost('/api/v1/activation/request', $body, []);
        $payload = $resp['body']['payload'] ?? [];
        $existingState=$this->identity->state();
        $this->identity->saveState(array_merge($existingState,[
            'activated' => true,
            'license' => $payload['license'] ?? null,
            'installation_id' => $id['installation_id'],
            'installation_key_id' => $payload['installation_key_id'] ?? null,
            'installation_generation' => $payload['installation_generation'] ?? null,
            'modules' => $payload['modules'] ?? [],
            'license_policy' => $payload['license_policy'] ?? [],
            'last_successful_contact' => time(),
            'last_trusted_server_time' => (int)($resp['body']['server_time'] ?? time()),
            'last_server_decision' => 'ACTIVE',
        ]));
        return $payload;
    }

    public function post(string $path, array $payload): array
    {
        $id = $this->identity->ensure();
        if (!isset($payload['_surface']) || !is_array($payload['_surface'])) {
            $payload['_surface'] = $this->surfaceContext();
        }
        $state = $this->identity->state();
        if (empty($state['activated'])) {
            throw new \RuntimeException('LICENSE_NOT_ACTIVATED');
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $requestId = 'req_' . bin2hex(random_bytes(16));
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $keyId = (string)($state['installation_key_id'] ?? '');
        $canonical = "POST\n" . $path . "\n" . $requestId . "\n" . $id['installation_id'] . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $id['private_key']));
        $headers = [
            'X-EasyIT-Request-ID: ' . $requestId,
            'X-EasyIT-Installation: ' . $id['installation_id'],
            'X-EasyIT-Timestamp: ' . $timestamp,
            'X-EasyIT-Nonce: ' . $nonce,
            'X-EasyIT-Key-ID: ' . $keyId,
            'X-EasyIT-Signature: ' . $signature,
            'X-EasyIT-Trusted-Server-Keys: ' . implode(',',array_keys($this->trustedServerKeys())),
        ];

        try {
            $response = $this->rawPost($path, $body, $headers);
        } catch (\RuntimeException $e) {
            // An explicit signed denial must invalidate old local authorization.
            $code = $e->getMessage();
            if (preg_match('/^(LICENSE_|INSTALLATION_|MODULE_|DATAFORM_)|^RUNTIME_GENERATION_INVALID$/', $code)) {
                $state=array_merge($state,$this->identity->state());
                $state['last_server_decision'] = $code;
                $state['last_successful_contact'] = time();
                $this->identity->saveState($state);
            }
            throw $e;
        }

        $state=array_merge($state,$this->identity->state());
        $state['last_successful_contact'] = time();
        $state['last_trusted_server_time'] = (int)($response['body']['server_time'] ?? time());
        $state['last_server_decision'] = 'ACTIVE';
        $responsePayload = $response['body']['payload'] ?? [];
        if (is_array($responsePayload) && isset($responsePayload['license_policy']) && is_array($responsePayload['license_policy'])) {
            $state['license_policy'] = $responsePayload['license_policy'];
        }
        $this->identity->saveState($state);
        return $response['body']['payload'] ?? [];
    }

    public function state(): array
    {
        return $this->identity->state();
    }

    public function config(): array
    {
        return $this->cfg;
    }

    public function verifyManifest(array $manifest, string $signatureBase64, string $keyId): bool
    {
        $keys = $this->trustedServerKeys();
        if ($keyId === '' || $signatureBase64 === '' || empty($keys[$keyId])) return false;
        $pub = base64_decode((string)$keys[$keyId], true);
        $sig = base64_decode($signatureBase64, true);
        if ($pub === false || $sig === false) return false;
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return sodium_crypto_sign_verify_detached($sig, $json, $pub);
    }

    /** HF86 Phase 11: static trust anchors + server-signed dynamically adopted rotation keys. */
    private function trustedServerKeys(): array
    {
        $keys=(array)($this->cfg['trusted_server_keys']??[]);
        $state=$this->identity->state();
        foreach((array)($state['dynamic_server_keys']??[]) as $id=>$publicKey){if(is_string($id)&&is_string($publicKey)&&$id!==''&&$publicKey!=='')$keys[$id]=$publicKey;}
        foreach((array)($state['revoked_server_key_ids']??[]) as $id)unset($keys[(string)$id]);
        return $keys;
    }

    private function adoptServerTrust(array $bundle): void
    {
        if((string)($bundle['schema']??'')!=='easyit.license.server-trust.v1') throw new \RuntimeException('SERVER_TRUST_BUNDLE_INVALID');
        $active=trim((string)($bundle['active_key_id']??''));if($active==='')throw new \RuntimeException('SERVER_TRUST_BUNDLE_INVALID');
        $current=$this->trustedServerKeys();$dynamic=[];$revoked=[];$seen=[];
        foreach((array)($bundle['keys']??[]) as $row){
            if(!is_array($row))throw new \RuntimeException('SERVER_TRUST_BUNDLE_INVALID');
            $id=trim((string)($row['key_id']??''));$status=(string)($row['status']??'');$public=trim((string)($row['public_key']??''));
            if($id===''||preg_match('/^[A-Za-z0-9._:-]{1,100}$/',$id)!==1||!in_array($status,['active','staged','retiring','revoked'],true))throw new \RuntimeException('SERVER_TRUST_BUNDLE_INVALID');
            $decoded=base64_decode($public,true);if($decoded===false||strlen($decoded)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new \RuntimeException('SERVER_TRUST_BUNDLE_INVALID');
            if(isset($current[$id])&&!hash_equals((string)$current[$id],$public))throw new \RuntimeException('SERVER_TRUST_KEY_CONFLICT');
            $seen[$id]=true;if($status==='revoked')$revoked[]=$id;else$dynamic[$id]=$public;
        }
        if(!isset($seen[$active])||in_array($active,$revoked,true))throw new \RuntimeException('SERVER_TRUST_BUNDLE_INVALID');
        $state=$this->identity->state();$state['dynamic_server_keys']=$dynamic;$state['revoked_server_key_ids']=array_values(array_unique($revoked));$state['active_server_key_id']=$active;$state['server_trust_updated_at']=time();$this->identity->saveState($state);
    }


    private function surfaceContext(): array
    {
        $configured = trim((string)($this->cfg['surface_origin'] ?? ''));
        if ($configured !== '') {
            $host = (string)(parse_url($configured, PHP_URL_HOST) ?: '');
            return ['origin' => rtrim($configured, '/'), 'host' => strtolower($host)];
        }
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        $origin = $host !== '' ? $scheme . '://' . $host : '';
        return ['origin' => $origin, 'host' => $host];
    }
    private function rawPost(string $path, string $body, array $extra): array
    {
        $url = rtrim((string)$this->cfg['server_url'], '/') . $path;
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $extra);
        [$status, $responseHeaders, $raw] = Transport::post(
            $url,
            $body,
            $headers,
            (int)($this->cfg['request_timeout'] ?? 15),
            (bool)($this->cfg['verify_tls'] ?? true)
        );

        $verifiedKeyId=$this->verifyServerResponse($raw, $responseHeaders);
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new \RuntimeException('SERVER_RESPONSE_INVALID');
        if(isset($data['server_trust'])&&is_array($data['server_trust']))$this->adoptServerTrust($data['server_trust']);
        if (($data['success'] ?? false) !== true) {
            $code = (string)($data['error']['code'] ?? 'SERVER_RESPONSE_INVALID');
            throw new \RuntimeException($code);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $data];
    }

    private function verifyServerResponse(string $raw, array $headers): string
    {
        $keys=$this->trustedServerKeys();
        $candidates=[
            [(string)($headers['x-easyit-server-key-id']??''),(string)($headers['x-easyit-server-signature']??'')],
            [(string)($headers['x-easyit-server-alt-key-id']??''),(string)($headers['x-easyit-server-alt-signature']??'')],
        ];
        $matchedTrusted=false;
        foreach($candidates as [$keyId,$signature64]){
            if($keyId===''||$signature64===''||empty($keys[$keyId]))continue;
            $matchedTrusted=true;$pub=base64_decode((string)$keys[$keyId],true);$signature=base64_decode($signature64,true);
            if($pub!==false&&$signature!==false&&strlen($pub)===SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES&&strlen($signature)===SODIUM_CRYPTO_SIGN_BYTES&&sodium_crypto_sign_verify_detached($signature,$raw,$pub))return$keyId;
        }
        throw new \RuntimeException($matchedTrusted?'SERVER_SIGNATURE_INVALID':'SERVER_SIGNATURE_UNTRUSTED');
    }
}
