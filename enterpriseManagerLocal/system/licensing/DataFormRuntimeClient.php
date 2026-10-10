<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;

final class DataFormRuntimeClient
{
    private string $dataformToken='';
    private string $projectId='';
    private string $dataformId='';

    public function __construct(private LicenseApiClient $api) {}

    public function start(string|int $projectId, string|int $dataformId): array
    {
        $this->projectId=(string)$projectId;
        $this->dataformId=(string)$dataformId;
        try {
            $tokenInfo=$this->api->dataFormToken($this->projectId,$this->dataformId);
            $this->dataformToken=trim((string)($tokenInfo['token']??''));
            if($this->dataformToken==='') throw new \RuntimeException('DATAFORM_TOKEN_MISSING');
            $session = $this->api->post('/api/v1/runtime/start', [
                'project_id' => $this->projectId,
                'dataform_id' => $this->dataformId,
                'df_license_token' => $this->dataformToken,
                'client' => ['product' => 'easyIT-enterprise', 'version' => 'local', 'runtime_protocol' => '2'],
            ]);
            $session['state'] = 'online';
            $this->storeCache($this->projectId, $this->dataformId, $session);
            return $session;
        } catch (\RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'SERVER_UNAVAILABLE')) throw $e;
            return $this->loadOfflineCache($this->projectId, $this->dataformId);
        }
    }

    public function renew(string $runtimeId, string $manifestHash): array
    {
        $this->requireToken();
        return $this->api->post('/api/v1/runtime/renew', [
            'runtime_id' => $runtimeId,
            'manifest_hash' => $manifestHash,
            'df_license_token'=>$this->dataformToken,
        ]);
    }

    public function action(string $runtimeId, string $action, string|int|null $recordId, array $fields = [], array $extraResource = []): array
    {
        $this->requireToken();
        return $this->api->post('/api/v1/runtime/action', [
            'runtime_id' => $runtimeId,
            'df_license_token'=>$this->dataformToken,
            'action' => $action,
            'resource' => array_merge(['record_id' => (string)($recordId ?? '')], $extraResource),
            'changes' => ['fields' => array_values($fields)],
        ]);
    }

    public function actionResult(string $actionId, string $status, string $code = 'OK'): array
    {
        return $this->api->post('/api/v1/runtime/action/result', [
            'action_id' => $actionId,
            'status' => $status,
            'result' => ['code' => $code],
        ]);
    }

    public function end(string $runtimeId): array
    {
        return $this->api->post('/api/v1/runtime/end', ['runtime_id' => $runtimeId, 'reason' => 'user_close']);
    }

    private function requireToken(): void
    {
        if($this->dataformToken==='') throw new \RuntimeException('DATAFORM_TOKEN_MISSING');
    }

    private function cacheFile(string $projectId, string $dataformId): string
    {
        $dir = rtrim((string)$this->api->config()['storage_dir'], '/\\') . DIRECTORY_SEPARATOR . 'runtime-cache';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        return $dir . DIRECTORY_SEPARATOR . hash('sha256', $projectId . '|' . $dataformId) . '.json';
    }

    /** HF85 Phase 4: remove any offline authorization left for a released DataForm. */
    public static function invalidateReleasedCache(LicenseApiClient $api,string|int $projectId,string|int $dataformId): void
    {
        $dir=rtrim((string)$api->config()['storage_dir'],'/\\').DIRECTORY_SEPARATOR.'runtime-cache';
        if(!is_dir($dir)) @mkdir($dir,0700,true);
        $base=$dir.DIRECTORY_SEPARATOR.hash('sha256',(string)$projectId.'|'.(string)$dataformId);
        if(is_file($base.'.json') && !@unlink($base.'.json')) throw new \RuntimeException('DATAFORM_RELEASE_CACHE_INVALIDATION_FAILED');
        $marker=$base.'.released';
        if(@file_put_contents($marker,(string)time(),LOCK_EX)===false) throw new \RuntimeException('DATAFORM_RELEASE_CACHE_INVALIDATION_FAILED');
        @chmod($marker,0600);
    }

    private function storeCache(string $projectId, string $dataformId, array $session): void
    {
        if (empty($session['manifest']) || empty($session['manifest_signature']) || empty($session['server_key_id'])) return;
        $data = [
            'project_id' => $projectId,
            'dataform_id' => $dataformId,
            'session' => $session,
            'stored_at' => time(),
        ];
        $file = $this->cacheFile($projectId, $dataformId);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        @chmod($file, 0600);
    }

    private function loadOfflineCache(string $projectId, string $dataformId): array
    {
        $state = $this->api->state();
        $released=is_array($state['released_dataforms']??null)?$state['released_dataforms']:[];
        $releaseKey=hash('sha256',$projectId.'|'.$dataformId);
        $cacheFile=$this->cacheFile($projectId,$dataformId);
        $releaseMarker=preg_replace('/\.json$/','.released',$cacheFile) ?: ($cacheFile.'.released');
        if(isset($released[$releaseKey])||is_file($releaseMarker)) throw new \RuntimeException('DATAFORM_REGISTRATION_REVOKED');
        $decision = (string)($state['last_server_decision'] ?? 'ACTIVE');
        if ($decision !== 'ACTIVE') throw new \RuntimeException($decision);

        $now = time();
        $trusted = (int)($state['last_trusted_server_time'] ?? 0);
        if ($trusted > 0 && $now + 300 < $trusted) throw new \RuntimeException('CLOCK_ROLLBACK_DETECTED');

        $file = $this->cacheFile($projectId, $dataformId);
        if (!is_file($file)) throw new \RuntimeException('SERVER_UNAVAILABLE');
        $cached = json_decode((string)file_get_contents($file), true);
        $session = is_array($cached) ? ($cached['session'] ?? null) : null;
        if (!is_array($session) || !is_array($session['manifest'] ?? null)) throw new \RuntimeException('RUNTIME_CACHE_INVALID');
        if (!$this->api->verifyManifest($session['manifest'], (string)($session['manifest_signature'] ?? ''), (string)($session['server_key_id'] ?? ''))) {
            throw new \RuntimeException('RUNTIME_SIGNATURE_INVALID');
        }
        if(empty($session['manifest']['binding']['dataform_uuid'])||empty($session['manifest']['binding']['dataform_token_hash'])) {
            throw new \RuntimeException('RUNTIME_CACHE_INVALID');
        }
        $lease = (int)($session['lease_until'] ?? $session['manifest']['runtime']['lease_until'] ?? 0);
        $grace = (int)($session['grace_until'] ?? $session['manifest']['runtime']['grace_until'] ?? 0);
        if ($now <= $lease) {
            $session['state'] = 'leased';
            return $session;
        }
        if ($now <= $grace) {
            $session['state'] = 'grace';
            $session['read_only'] = true;
            return $session;
        }
        throw new \RuntimeException('GRACE_EXPIRED');
    }
}
