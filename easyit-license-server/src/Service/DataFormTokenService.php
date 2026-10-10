<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use EasyIT\LicenseServer\Security\ServerSigner;
use PDO;

/** HF85 Phase 3: signierte, exakt gebundene DataForm-Lizenztokens. */
final class DataFormTokenService
{
    public function __construct(private PDO $pdo, private ServerSigner $signer, private LicenseService $licenses) {}

    public function issueForRow(array $row): array
    {
        if((string)($row['status']??'')!=='approved') throw new \RuntimeException('DATAFORM_NOT_APPROVED');
        $this->assertSlotActive($row);
        $issuedAt=(int)($row['approved_at']??0);
        if($issuedAt<=0) throw new \RuntimeException('DATAFORM_TOKEN_STATE_INVALID');
        $payload=[
            'type'=>'easyit.dataform.license',
            'version'=>1,
            'dataform_uuid'=>(string)$row['dataform_uuid'],
            'license_id'=>(string)$row['license_id'],
            'customer_id'=>(string)$row['customer_id'],
            'installation_id'=>(string)$row['installation_id'],
            'project_id'=>(string)$row['project_id'],
            'local_dataform_id'=>(string)$row['local_dataform_id'],
            'issued_at'=>$issuedAt,
        ];
        $json=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $signature=$this->signer->sign($json);
        $this->pdo->prepare('UPDATE license_dataforms SET token_last_issued_key_id=?,token_last_issued_at=? WHERE dataform_uuid=?')->execute([$this->signer->keyId(),time(),(string)$row['dataform_uuid']]);
        $token='DFT1.'.self::b64url($this->signer->keyId()).'.'.self::b64url($json).'.'.self::b64url(base64_decode($signature,true)?:'');
        return [
            'token'=>$token,
            'key_id'=>$this->signer->keyId(),
            'issued_at'=>$issuedAt,
            'token_hash'=>'sha256:'.hash('sha256',$token),
            'payload'=>$payload,
        ];
    }

    public function forInstallation(array $installation,string $projectId,string $dataformId): array
    {
        if($projectId===''||$dataformId==='') throw new \RuntimeException('REQUEST_INVALID');
        $st=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE license_id=? AND project_id=? AND local_dataform_id=? LIMIT 1');
        $st->execute([(string)$installation['license_id'],$projectId,$dataformId]);
        $row=$st->fetch();
        if(!$row) throw new \RuntimeException('DATAFORM_REGISTRATION_NOT_FOUND');
        $this->assertRowUsable($row,$installation);
        return $this->issueForRow($row)+['status'=>'approved','dataform_uuid'=>(string)$row['dataform_uuid']];
    }

    /**
     * Prüft Signatur, Identitätsbindung, Freigabestatus und aktiven Lizenzplatz.
     * @return array{row:array,payload:array,token_hash:string}
     */
    public function assertApproved(array $installation,array $request,string $projectId,string $dataformId): array
    {
        $token=trim((string)($request['df_license_token']??''));
        if($token==='') throw new \RuntimeException('DATAFORM_TOKEN_MISSING');
        $parts=explode('.',$token);
        if(count($parts)!==4||$parts[0]!=='DFT1') throw new \RuntimeException('DATAFORM_TOKEN_INVALID');
        $keyId=self::b64urlDecode($parts[1]);
        $json=self::b64urlDecode($parts[2]);
        $sig=self::b64urlDecode($parts[3]);
        if($keyId===''||$json===''||$sig==='') throw new \RuntimeException('DATAFORM_TOKEN_INVALID');
        if(!$this->signer->verifyByKeyId($json,base64_encode($sig),$keyId)) throw new \RuntimeException('DATAFORM_TOKEN_UNTRUSTED');
        try{$payload=json_decode($json,true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('DATAFORM_TOKEN_INVALID');}
        if(!is_array($payload)||($payload['type']??'')!=='easyit.dataform.license'||(int)($payload['version']??0)!==1) throw new \RuntimeException('DATAFORM_TOKEN_INVALID');

        $uuid=strtolower(trim((string)($payload['dataform_uuid']??'')));
        $st=$this->pdo->prepare('SELECT * FROM license_dataforms WHERE dataform_uuid=? LIMIT 1');
        $st->execute([$uuid]);
        $row=$st->fetch();
        if(!$row) throw new \RuntimeException('DATAFORM_REGISTRATION_NOT_FOUND');
        $this->assertRowUsable($row,$installation);

        $expected=[
            'license_id'=>(string)$installation['license_id'],
            'installation_id'=>(string)$installation['installation_id'],
            'project_id'=>$projectId,
            'local_dataform_id'=>$dataformId,
            'customer_id'=>(string)$row['customer_id'],
            'dataform_uuid'=>(string)$row['dataform_uuid'],
        ];
        foreach($expected as $k=>$v) if((string)($payload[$k]??'')!==$v) throw new \RuntimeException('DATAFORM_TOKEN_BINDING_INVALID');
        if((int)($payload['issued_at']??0)!==(int)($row['approved_at']??0)) throw new \RuntimeException('DATAFORM_TOKEN_BINDING_INVALID');

        $this->assertSlotActive($row);
        return ['row'=>$row,'payload'=>$payload,'token_hash'=>'sha256:'.hash('sha256',$token)];
    }

    private function assertRowUsable(array $row,array $installation): void
    {
        if((string)$row['status']!=='approved') throw new \RuntimeException('DATAFORM_NOT_APPROVED');
        if((string)$row['license_id']!==(string)$installation['license_id']) throw new \RuntimeException('DATAFORM_TOKEN_BINDING_INVALID');
        if((string)$row['installation_id']!==(string)$installation['installation_id']) throw new \RuntimeException('DATAFORM_INSTALLATION_MISMATCH');
        $license=$this->licenses->byId((string)$row['license_id']);
        $this->licenses->requireModule((string)$license['license_id'],'dataform');
        if((string)($license['customer_id']??'')!==(string)$row['customer_id']) throw new \RuntimeException('DATAFORM_TOKEN_BINDING_INVALID');
    }

    private function assertSlotActive(array $row): void
    {
        $usage=$this->pdo->prepare("SELECT status FROM license_dataform_usage WHERE license_id=? AND project_id=? AND dataform_id=? LIMIT 1");
        $usage->execute([(string)$row['license_id'],(string)$row['project_id'],(string)$row['local_dataform_id']]);
        $usageRow=$usage->fetch();
        if(!$usageRow||(string)$usageRow['status']!=='active') throw new \RuntimeException('DATAFORM_SLOT_NOT_ACTIVE');
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
    }
    private static function b64urlDecode(string $value): string
    {
        if($value==='') return '';
        $pad=(4-(strlen($value)%4))%4;
        $raw=base64_decode(strtr($value,'-_','+/').str_repeat('=',$pad),true);
        return $raw===false?'':$raw;
    }
}
