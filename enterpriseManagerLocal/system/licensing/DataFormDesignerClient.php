<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;

final class DataFormDesignerClient
{
    /** @var array<string,string> */
    private array $tokens=[];
    public function __construct(private LicenseApiClient $api) {}

    public function validate(string|int $projectId,string|int $dataformId,array $definition):array
    {
        return $this->api->post('/api/v1/designer/validate',[
            'project_id'=>(string)$projectId,
            'dataform_id'=>(string)$dataformId,
            'df_license_token'=>$this->token((string)$projectId,(string)$dataformId),
            'definition'=>$definition,
        ]);
    }
    public function compile(string|int $projectId,string|int $dataformId,array $definition):array
    {
        return $this->api->post('/api/v1/designer/compile',[
            'project_id'=>(string)$projectId,
            'dataform_id'=>(string)$dataformId,
            'df_license_token'=>$this->token((string)$projectId,(string)$dataformId),
            'definition'=>$definition,
        ]);
    }
    public function publish(string $definitionId,string|int $projectId,string|int $dataformId):array
    {
        return $this->api->post('/api/v1/designer/publish',[
            'definition_id'=>$definitionId,
            'df_license_token'=>$this->token((string)$projectId,(string)$dataformId),
        ]);
    }
    public function revisions(string|int $projectId,string|int $dataformId):array
    {
        return $this->api->post('/api/v1/designer/revisions',[
            'project_id'=>(string)$projectId,
            'dataform_id'=>(string)$dataformId,
            'df_license_token'=>$this->token((string)$projectId,(string)$dataformId),
        ]);
    }

    private function token(string $projectId,string $dataformId): string
    {
        $key=$projectId.'|'.$dataformId;
        if(!isset($this->tokens[$key])) {
            $info=$this->api->dataFormToken($projectId,$dataformId);
            $token=trim((string)($info['token']??''));
            if($token==='') throw new \RuntimeException('DATAFORM_TOKEN_MISSING');
            $this->tokens[$key]=$token;
        }
        return $this->tokens[$key];
    }
}
