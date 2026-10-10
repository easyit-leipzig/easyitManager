<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use EasyIT\LicenseServer\ProtectedCore\DataFormCompiler;
use EasyIT\LicenseServer\ProtectedCore\ActionResolver;
use EasyIT\LicenseServer\Security\ServerSigner;
use EasyIT\LicenseServer\Support\Id;
use PDO;

final class RuntimeService
{
    public function __construct(
        private PDO $pdo,
        private LicenseService $licenses,
        private AuditService $audit,
        private DataFormCompiler $compiler,
        private ActionResolver $actions,
        private ServerSigner $signer,
        private SurfacePolicyService $surfacePolicy,
        private DataFormTokenService $tokens,
        private array $runtimeCfg
    ) {}

    private function context(array $inst): array
    {
        $lic=$this->licenses->byId((string)$inst['license_id']);
        $this->licenses->requireModule((string)$lic['license_id'],'dataform');
        return [$lic,$inst];
    }

    private function decodeDefinition(array $r): array
    {
        $d=json_decode((string)$r['definition_json'],true);
        if(!is_array($d)) throw new \RuntimeException('DATAFORM_DEFINITION_INVALID');
        return [$r,$d];
    }

    private function definition(string $project,string $df): array
    {
        $st=$this->pdo->prepare("SELECT * FROM dataform_definitions WHERE project_id=? AND dataform_id=? AND status='published' ORDER BY revision_number DESC LIMIT 1");
        $st->execute([$project,$df]);
        $r=$st->fetch();
        if(!$r) throw new \RuntimeException('DATAFORM_DEFINITION_NOT_FOUND');
        return $this->decodeDefinition($r);
    }

    private function definitionById(string $definitionId): array
    {
        $st=$this->pdo->prepare('SELECT * FROM dataform_definitions WHERE definition_id=? LIMIT 1');
        $st->execute([$definitionId]);
        $r=$st->fetch();
        if(!$r) throw new \RuntimeException('DATAFORM_DEFINITION_NOT_FOUND');
        return $this->decodeDefinition($r);
    }

    private function compileManifest(array $lic,array $inst,array $defRow,array $def,string $project,string $df,string $rid,int $issued,int $lease,int $grace,array $tokenAuth): array
    {
        $binding=[
            'installation_id'=>$inst['installation_id'],
            'project_id'=>$project,
            'dataform_id'=>$df,
            'definition_id'=>$defRow['definition_id'],
            'dataform_uuid'=>(string)($tokenAuth['payload']['dataform_uuid']??''),
            'dataform_token_hash'=>(string)($tokenAuth['token_hash']??''),
            'license_generation'=>(int)$lic['generation'],
            'installation_generation'=>(int)$inst['generation'],
        ];
        $allowed=$this->licenses->allowedCapabilitiesForDataform((string)$lic['license_id'],$project,$df);
        $policy=$this->licenses->policySummary((string)$lic['license_id']);
        return $this->compiler->compile($def,$binding,$issued,$lease,$grace,$rid,$allowed,$policy);
    }

    public function start(array $inst,array $p,string $requestId): array
    {
        [$lic,$inst]=$this->context($inst);
        $project=(string)($p['project_id']??'');
        $df=(string)($p['dataform_id']??'');
        if($project===''||$df==='') throw new \RuntimeException('REQUEST_INVALID');
        $surface=$this->surfacePolicy->assertForLicense((string)$lic['license_id'],$p);
        $tokenAuth=$this->tokens->assertApproved($inst,$p,$project,$df);

        [$defRow,$def]=$this->definition($project,$df);
        $this->licenses->requireCapability((string)$lic['license_id'],$project,$df,'dataset.read');
        $now=time();
        $lease=min($now+(int)($lic['lease_seconds']??$this->runtimeCfg['lease_seconds']),!empty($lic['valid_until'])?(int)$lic['valid_until']:PHP_INT_MAX);
        $grace=min($now+(int)($lic['grace_seconds']??$this->runtimeCfg['grace_seconds']),!empty($lic['valid_until'])?(int)$lic['valid_until']:PHP_INT_MAX);
        $rid=Id::make('RT');

        $this->pdo->beginTransaction();
        try {
            // HF85 Phase 3: Der Lizenzplatz wurde bereits durch die manuelle
            // Superadmin-Freigabe belegt. Runtime darf keinen Platz mehr selbst claimen.
            $usage=$this->licenses->policySummary((string)$lic['license_id']);
            $manifest=$this->compileManifest($lic,$inst,$defRow,$def,$project,$df,$rid,$now,$lease,$grace,$tokenAuth);
            if(!in_array('dataset.read',(array)($manifest['capabilities']??[]),true)) throw new \RuntimeException('CAPABILITY_NOT_LICENSED');
            $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $hash=hash('sha256',$json);
            $msig=$this->signer->sign($json);
            $st=$this->pdo->prepare('INSERT INTO runtime_sessions (runtime_id,license_id,installation_id,project_id,dataform_id,definition_id,license_generation,installation_generation,manifest_version,status,issued_at,lease_until,grace_until,last_seen_at,manifest_hash,surface_origin,source_ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$rid,$lic['license_id'],$inst['installation_id'],$project,$df,$defRow['definition_id'],$lic['generation'],$inst['generation'],'3','active',$now,$lease,$grace,$now,$hash,$surface['origin'],$surface['source_ip']]);
            $this->audit->write('RUNTIME_STARTED','runtime',$rid,'installation',(string)$inst['installation_id'],$requestId,[],['project_id'=>$project,'dataform_id'=>$df,'used_dataforms'=>$usage['used_dataforms'],'max_dataforms'=>$usage['max_dataforms']]);
            $this->pdo->commit();
        } catch(\Throwable $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return [
            'runtime_id'=>$rid,
            'state'=>'online',
            'lease_until'=>$lease,
            'grace_until'=>$grace,
            'license_policy'=>$this->licenses->policySummary((string)$lic['license_id']),
            'manifest'=>$manifest,
            'manifest_hash'=>'sha256:'.$hash,
            'manifest_signature'=>$msig,
            'server_key_id'=>$this->signer->keyId(),
        ];
    }

    public function renew(array $inst,array $p,string $requestId): array
    {
        $rid=(string)($p['runtime_id']??'');
        $st=$this->pdo->prepare('SELECT * FROM runtime_sessions WHERE runtime_id=? AND installation_id=? LIMIT 1');
        $st->execute([$rid,$inst['installation_id']]);
        $rt=$st->fetch();
        if(!$rt) throw new \RuntimeException('RUNTIME_UNKNOWN');
        if($rt['status']!=='active') throw new \RuntimeException('RUNTIME_REVOKED');

        [$lic,$inst]=$this->context($inst);
        $surface=$this->surfacePolicy->assertForLicense((string)$lic['license_id'],$p);
        if((int)$rt['license_generation']!==(int)$lic['generation'] || (int)$rt['installation_generation']!==(int)$inst['generation']) throw new \RuntimeException('RUNTIME_GENERATION_INVALID');
        $tokenAuth=$this->tokens->assertApproved($inst,$p,(string)$rt['project_id'],(string)$rt['dataform_id']);
        [$defRow,$def]=$this->definition((string)$rt['project_id'],(string)$rt['dataform_id']);
        $now=time();
        $lease=min($now+(int)$lic['lease_seconds'],!empty($lic['valid_until'])?(int)$lic['valid_until']:PHP_INT_MAX);
        $grace=min($now+(int)$lic['grace_seconds'],!empty($lic['valid_until'])?(int)$lic['valid_until']:PHP_INT_MAX);

        // Policy changes increment license generation. Definition changes replace the manifest.
        $changed=(string)$defRow['definition_id']!==(string)$rt['definition_id'];
        $hash=(string)$rt['manifest_hash'];
        $manifest=null;$json=null;
        if($changed){
            $manifest=$this->compileManifest($lic,$inst,$defRow,$def,(string)$rt['project_id'],(string)$rt['dataform_id'],$rid,(int)$rt['issued_at'],$lease,$grace,$tokenAuth);
            if(!in_array('dataset.read',(array)($manifest['capabilities']??[]),true)) throw new \RuntimeException('CAPABILITY_NOT_LICENSED');
            $json=json_encode($manifest,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            $hash=hash('sha256',$json);
        }
        $up=$this->pdo->prepare('UPDATE runtime_sessions SET lease_until=?,grace_until=?,last_seen_at=?,definition_id=?,manifest_hash=?,surface_origin=?,source_ip=? WHERE runtime_id=?');
        $up->execute([$lease,$grace,$now,$defRow['definition_id'],$hash,$surface['origin'],$surface['source_ip'],$rid]);
        $out=[
            'runtime_id'=>$rid,
            'state'=>'online',
            'lease_until'=>$lease,
            'grace_until'=>$grace,
            'manifest_changed'=>$changed,
            'manifest_hash'=>'sha256:'.$hash,
            'license_policy'=>$this->licenses->policySummary((string)$lic['license_id']),
        ];
        if($changed&&$manifest!==null&&$json!==null){
            $out['manifest']=$manifest;
            $out['manifest_signature']=$this->signer->sign($json);
            $out['server_key_id']=$this->signer->keyId();
        }
        return $out;
    }

    public function action(array $inst,array $p,string $requestId): array
    {
        $rid=(string)($p['runtime_id']??'');
        $st=$this->pdo->prepare('SELECT * FROM runtime_sessions WHERE runtime_id=? AND installation_id=? LIMIT 1');
        $st->execute([$rid,$inst['installation_id']]);
        $rt=$st->fetch();
        if(!$rt||$rt['status']!=='active'||time()>(int)$rt['lease_until']) throw new \RuntimeException('RUNTIME_EXPIRED');

        [$lic,$inst]=$this->context($inst);
        $this->surfacePolicy->assertForLicense((string)$lic['license_id'],$p);
        if((int)$rt['license_generation']!==(int)$lic['generation']||(int)$rt['installation_generation']!==(int)$inst['generation']) throw new \RuntimeException('RUNTIME_GENERATION_INVALID');
        $tokenAuth=$this->tokens->assertApproved($inst,$p,(string)$rt['project_id'],(string)$rt['dataform_id']);
        [$defRow,$def]=$this->definitionById((string)$rt['definition_id']);
        $manifest=$this->compileManifest($lic,$inst,$defRow,$def,(string)$rt['project_id'],(string)$rt['dataform_id'],$rid,(int)$rt['issued_at'],(int)$rt['lease_until'],(int)$rt['grace_until'],$tokenAuth);
        $decision=$this->actions->resolve($manifest,(string)($p['action']??''),(array)($p['resource']??[]),(array)($p['changes']??[]));
        $aid=Id::make('ACT');$now=time();
        $decision=['action_id'=>$aid,'runtime_id'=>$rid]+$decision+[
            'issued_at'=>$now,
            'valid_until'=>$now+(int)$this->runtimeCfg['action_ttl_seconds'],
            'nonce'=>bin2hex(random_bytes(16)),
        ];
        $dj=json_encode($decision,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        $dh=hash('sha256',$dj);
        $resourceHash=hash('sha256',json_encode($decision['resource']));
        $ins=$this->pdo->prepare('INSERT INTO runtime_actions (action_id,runtime_id,action_type,dataform_id,resource_hash,status,issued_at,valid_until,completed_at,decision_hash,result_code) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([$aid,$rid,$decision['operation'],$rt['dataform_id'],$resourceHash,'issued',$now,$decision['valid_until'],null,$dh,null]);
        return [
            'allowed'=>true,
            'decision'=>$decision,
            'decision_hash'=>'sha256:'.$dh,
            'decision_signature'=>$this->signer->sign($dj),
            'server_key_id'=>$this->signer->keyId(),
        ];
    }

    public function actionResult(array $inst,array $p): array
    {
        $aid=(string)($p['action_id']??'');
        $status=(string)($p['status']??'');
        if(!in_array($status,['completed','failed'],true)) throw new \RuntimeException('REQUEST_INVALID');
        $st=$this->pdo->prepare('SELECT a.*,r.installation_id FROM runtime_actions a JOIN runtime_sessions r ON r.runtime_id=a.runtime_id WHERE a.action_id=? LIMIT 1');
        $st->execute([$aid]);
        $a=$st->fetch();
        if(!$a||(string)$a['installation_id']!==(string)$inst['installation_id']) throw new \RuntimeException('ACTION_TOKEN_INVALID');
        if($a['status']===$status) return ['action_id'=>$aid,'status'=>$status,'idempotent'=>true];
        if($a['status']!=='issued') throw new \RuntimeException('ACTION_REPLAY_DETECTED');
        $up=$this->pdo->prepare('UPDATE runtime_actions SET status=?,completed_at=?,result_code=? WHERE action_id=? AND status=?');
        $up->execute([$status,time(),(string)($p['result']['code']??'OK'),$aid,'issued']);
        return ['action_id'=>$aid,'status'=>$status,'idempotent'=>false];
    }

    public function end(array $inst,array $p): array
    {
        $rid=(string)($p['runtime_id']??'');
        $st=$this->pdo->prepare("UPDATE runtime_sessions SET status='closed',last_seen_at=? WHERE runtime_id=? AND installation_id=? AND status='active'");
        $st->execute([time(),$rid,$inst['installation_id']]);
        return ['runtime_id'=>$rid,'status'=>'closed'];
    }
}
