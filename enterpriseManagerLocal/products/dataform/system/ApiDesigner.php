<?php
declare(strict_types=1);
require_once __DIR__.'/DataFormTransport.php';

final class ApiDesigner
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS api_definitions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
            description TEXT NULL,
            base_path VARCHAR(190) NOT NULL DEFAULT '/api',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_api_slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS api_endpoints (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            api_id BIGINT UNSIGNED NOT NULL,
            dataform_id BIGINT UNSIGNED NULL,
            query_id BIGINT UNSIGNED NULL,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(120) NOT NULL,
            http_method VARCHAR(10) NOT NULL DEFAULT 'GET',
            operation VARCHAR(24) NOT NULL DEFAULT 'list',
            requires_auth TINYINT(1) NOT NULL DEFAULT 1,
            rate_limit_per_minute INT UNSIGNED NOT NULL DEFAULT 60,
            fields_json LONGTEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_api_endpoint (api_id, slug, http_method),
            KEY idx_api_endpoints_api (api_id),
            CONSTRAINT fk_api_endpoints_api FOREIGN KEY (api_id) REFERENCES api_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS api_keys (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            api_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            key_prefix VARCHAR(20) NOT NULL,
            key_hash VARCHAR(255) NOT NULL,
            role_name VARCHAR(80) NOT NULL DEFAULT 'api',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            expires_at DATETIME NULL,
            last_used_at DATETIME NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_api_keys_api (api_id),
            KEY idx_api_keys_prefix (key_prefix),
            CONSTRAINT fk_api_keys_api FOREIGN KEY (api_id) REFERENCES api_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS api_request_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            api_id BIGINT UNSIGNED NOT NULL,
            endpoint_id BIGINT UNSIGNED NULL,
            api_key_id BIGINT UNSIGNED NULL,
            request_method VARCHAR(10) NOT NULL,
            request_path VARCHAR(255) NOT NULL,
            response_status SMALLINT UNSIGNED NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            client_ip VARCHAR(64) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_api_log_lookup (api_id, created_at),
            CONSTRAINT fk_api_log_api FOREIGN KEY (api_id) REFERENCES api_definitions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    public static function generateKey(): array
    {
        $plain = 'dfk_' . bin2hex(random_bytes(24));
        return [
            'plain' => $plain,
            'prefix' => substr($plain, 0, 12),
            'hash' => password_hash($plain, PASSWORD_DEFAULT),
        ];
    }

    public static function bearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        return preg_match('/^Bearer\s+(.+)$/i', trim((string)$header), $m) ? trim($m[1]) : '';
    }

    public static function authenticate(PDO $pdo, int $apiId, string $token): ?array
    {
        if ($token === '') return null;
        $prefix = substr($token, 0, 12);
        $stmt = $pdo->prepare("SELECT * FROM api_keys WHERE api_id=? AND key_prefix=? AND is_active=1 AND (expires_at IS NULL OR expires_at>NOW())");
        $stmt->execute([$apiId, $prefix]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $key) {
            if (password_verify($token, (string)$key['key_hash'])) {
                $pdo->prepare('UPDATE api_keys SET last_used_at=NOW() WHERE id=?')->execute([(int)$key['id']]);
                return $key;
            }
        }
        return null;
    }

    public static function rateLimit(PDO $pdo, int $apiId, int $endpointId, ?int $keyId, int $limit): bool
    {
        if ($limit < 1) return true;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM api_request_log WHERE api_id=? AND endpoint_id=? AND ((api_key_id IS NULL AND ? IS NULL) OR api_key_id=?) AND created_at >= (NOW() - INTERVAL 1 MINUTE)');
        $stmt->execute([$apiId, $endpointId, $keyId, $keyId]);
        return (int)$stmt->fetchColumn() < $limit;
    }

    public static function log(PDO $pdo, int $apiId, ?int $endpointId, ?int $keyId, string $method, string $path, int $status, int $durationMs): void
    {
        $stmt = $pdo->prepare('INSERT INTO api_request_log(api_id,endpoint_id,api_key_id,request_method,request_path,response_status,duration_ms,client_ip) VALUES(?,?,?,?,?,?,?,?)');
        $stmt->execute([$apiId, $endpointId, $keyId, $method, substr($path,0,255), $status, $durationMs, $_SERVER['REMOTE_ADDR'] ?? null]);
    }

    public static function openApi(array $api, array $endpoints, array $dataforms, string $serverUrl): array
    {
        $paths=[];
        $schemas=[];
        $formsBySlug=[];
        foreach ($dataforms as $df) {
            $slug=(string)($df['slug']??'');
            $fields=is_array($df['fields']??null)?$df['fields']:[];
            $formsBySlug[$slug]=$fields;
            $response=DataFormTransport::openApiRecordSchema($fields,false);
            $request=DataFormTransport::openApiRecordSchema($fields,true);
            $response['properties']=['id'=>['type'=>'integer','readOnly'=>true]]+($response['properties']??[])+[
                'created_at'=>['type'=>'string','format'=>'date-time','readOnly'=>true],
                'updated_at'=>['type'=>'string','format'=>'date-time','readOnly'=>true],
            ];
            $schemas[$slug]=$response;
            $schemas[$slug.'Input']=$request;
        }

        foreach ($endpoints as $ep) {
            $method=strtolower((string)$ep['http_method']);
            $path='/'.trim((string)$ep['slug'],'/');
            if (in_array($ep['operation'],['detail','update','delete'],true)) $path.='/{id}';
            $schemaSlug=(string)($ep['dataform_slug']??'Record');
            $epFields=$formsBySlug[$schemaSlug]??[];
            $selected=json_decode((string)($ep['fields_json']??'[]'),true);
            if (is_array($selected) && $selected!==[]) {
                $allowed=array_fill_keys(array_map('strval',$selected),true);
                $epFields=array_values(array_filter($epFields,static fn(array $f):bool=>isset($allowed[(string)$f['name']])));
            }
            $responseRecord=DataFormTransport::openApiRecordSchema($epFields,false);
            $responseRecord['properties']=['id'=>['type'=>'integer','readOnly'=>true]]+($responseRecord['properties']??[])+[
                'created_at'=>['type'=>'string','format'=>'date-time','readOnly'=>true],
                'updated_at'=>['type'=>'string','format'=>'date-time','readOnly'=>true],
            ];
            $requestRecord=DataFormTransport::openApiRecordSchema($epFields,true);

            $operation=[
                'summary'=>(string)$ep['name'],
                'operationId'=>self::slug((string)$ep['name']).'-'.$method,
                'security'=>(int)$ep['requires_auth']===1?[['bearerAuth'=>[]]]:[],
                'responses'=>[],
            ];
            if (str_contains($path,'{id}')) {
                $operation['parameters']=[['name'=>'id','in'=>'path','required'=>true,'schema'=>['type'=>'integer','minimum'=>1]]];
            }
            if (in_array($method,['post','put','patch'],true)) {
                $operation['requestBody']=[
                    'required'=>true,
                    'content'=>['application/json'=>['schema'=>$requestRecord]],
                ];
            }

            $op=(string)$ep['operation'];
            if ($op==='list') {
                $operation['responses']['200']=[
                    'description'=>'Liste erfolgreich gelesen',
                    'content'=>['application/json'=>['schema'=>[
                        'type'=>'object',
                        'properties'=>[
                            'data'=>['type'=>'array','items'=>$responseRecord],
                            'meta'=>['type'=>'object','properties'=>[
                                'limit'=>['type'=>'integer'],'offset'=>['type'=>'integer'],'count'=>['type'=>'integer'],
                            ]],
                        ],
                    ]]],
                ];
            } elseif ($op==='delete') {
                $operation['responses']['200']=[
                    'description'=>'Datensatz gelöscht',
                    'content'=>['application/json'=>['schema'=>[
                        'type'=>'object','properties'=>['deleted'=>['type'=>'boolean'],'id'=>['type'=>'integer']],
                    ]]],
                ];
            } else {
                $code=$op==='create'?'201':'200';
                $operation['responses'][$code]=[
                    'description'=>$op==='create'?'Datensatz angelegt':'Datensatz erfolgreich gelesen oder geändert',
                    'content'=>['application/json'=>['schema'=>[
                        'type'=>'object','properties'=>['data'=>$responseRecord],
                    ]]],
                ];
            }
            $operation['responses']['400']=['description'=>'Ungültige Anfrage'];
            $operation['responses']['401']=['description'=>'Authentifizierung erforderlich'];
            $operation['responses']['404']=['description'=>'Ressource nicht gefunden'];
            $operation['responses']['422']=['description'=>'Validierungsfehler'];
            $paths[$path][$method]=$operation;
        }

        return [
            'openapi'=>'3.0.3',
            'info'=>['title'=>$api['name'],'version'=>$api['version'],'description'=>$api['description']??''],
            'servers'=>[['url'=>rtrim($serverUrl,'/')]],
            'paths'=>$paths,
            'components'=>[
                'securitySchemes'=>['bearerAuth'=>['type'=>'http','scheme'=>'bearer','bearerFormat'=>'API key']],
                'schemas'=>$schemas,
            ],
        ];
    }
}
