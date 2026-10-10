<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\Service;

use PDO;

final class LicenseService
{
    public function __construct(private PDO $pdo) {}

    public function byActivationKey(string $licenseKey): array
    {
        $pos=strpos($licenseKey,'.');
        if($pos===false) throw new \RuntimeException('LICENSE_INVALID');
        $number=substr($licenseKey,0,$pos);
        $secret=substr($licenseKey,$pos+1);
        $st=$this->pdo->prepare('SELECT * FROM licenses WHERE license_number=? LIMIT 1');
        $st->execute([$number]);
        $lic=$st->fetch();
        if(!$lic || !password_verify($secret,(string)$lic['activation_secret_hash'])) throw new \RuntimeException('LICENSE_INVALID');
        $this->assertUsable($lic);
        return $lic;
    }

    public function byId(string $id): array
    {
        $st=$this->pdo->prepare('SELECT * FROM licenses WHERE license_id=? LIMIT 1');
        $st->execute([$id]);
        $l=$st->fetch();
        if(!$l) throw new \RuntimeException('LICENSE_INVALID');
        $this->assertUsable($l);
        return $l;
    }

    public function assertUsable(array $lic): void
    {
        $status=(string)($lic['status']??'');
        if($status==='expired') throw new \RuntimeException('LICENSE_EXPIRED');
        if($status==='suspended') throw new \RuntimeException('LICENSE_SUSPENDED');
        if($status==='revoked') throw new \RuntimeException('LICENSE_REVOKED');
        if($status!=='active') throw new \RuntimeException('LICENSE_INVALID');
        $now=time();
        if(!empty($lic['valid_from']) && $now<(int)$lic['valid_from']) throw new \RuntimeException('LICENSE_INVALID');
        if(!empty($lic['valid_until']) && $now>(int)$lic['valid_until']) throw new \RuntimeException('LICENSE_EXPIRED');
    }

    public function requireModule(string $licenseId,string $module): void
    {
        $now=time();
        $st=$this->pdo->prepare('SELECT * FROM license_modules WHERE license_id=? AND module_code=? AND enabled=1 LIMIT 1');
        $st->execute([$licenseId,$module]);
        $m=$st->fetch();
        if(!$m) throw new \RuntimeException('MODULE_NOT_LICENSED');
        if(!empty($m['valid_from']) && $now<(int)$m['valid_from']) throw new \RuntimeException('MODULE_NOT_LICENSED');
        if(!empty($m['valid_until']) && $now>(int)$m['valid_until']) throw new \RuntimeException('MODULE_NOT_LICENSED');
    }

    public function modules(string $licenseId): array
    {
        $st=$this->pdo->prepare('SELECT module_code,valid_from,valid_until FROM license_modules WHERE license_id=? AND enabled=1');
        $st->execute([$licenseId]);
        $now=time();$out=[];
        foreach($st->fetchAll() as $row){
            if(!empty($row['valid_from'])&&$now<(int)$row['valid_from']) continue;
            if(!empty($row['valid_until'])&&$now>(int)$row['valid_until']) continue;
            $out[]=(string)$row['module_code'];
        }
        sort($out);
        return $out;
    }

    /** @return array{max_dataforms:?int,used_dataforms:int,available_dataforms:?int} */
    public function dataformLimit(string $licenseId): array
    {
        $st=$this->pdo->prepare('SELECT max_dataforms FROM license_policies WHERE license_id=? LIMIT 1');
        $st->execute([$licenseId]);
        $row=$st->fetch();
        $max=$row && $row['max_dataforms']!==null ? max(0,(int)$row['max_dataforms']) : null;
        $cnt=$this->pdo->prepare("SELECT COUNT(*) c FROM license_dataform_usage WHERE license_id=? AND status='active'");
        $cnt->execute([$licenseId]);
        $used=(int)($cnt->fetch()['c']??0);
        return [
            'max_dataforms'=>$max,
            'used_dataforms'=>$used,
            'available_dataforms'=>$max===null?null:max(0,$max-$used),
        ];
    }

    /**
     * Claims one distinct project/dataform combination for a license.
     * Existing active claims do not consume another slot. Released claims can be reactivated.
     */
    public function claimDataform(string $licenseId,string $projectId,string $dataformId): array
    {
        if($projectId==='' || $dataformId==='') throw new \RuntimeException('REQUEST_INVALID');
        $ownTx=!$this->pdo->inTransaction();
        if($ownTx) $this->pdo->beginTransaction();
        try {
            // Serializes slot allocation on both MariaDB/MySQL and PostgreSQL.
            $lock=$this->pdo->prepare('SELECT license_id FROM licenses WHERE license_id=? FOR UPDATE');
            $lock->execute([$licenseId]);
            if(!$lock->fetch()) throw new \RuntimeException('LICENSE_INVALID');

            $sel=$this->pdo->prepare('SELECT * FROM license_dataform_usage WHERE license_id=? AND project_id=? AND dataform_id=? LIMIT 1');
            $sel->execute([$licenseId,$projectId,$dataformId]);
            $existing=$sel->fetch();
            $now=time();
            if($existing && (string)$existing['status']==='active') {
                $up=$this->pdo->prepare('UPDATE license_dataform_usage SET last_used_at=? WHERE license_id=? AND project_id=? AND dataform_id=?');
                $up->execute([$now,$licenseId,$projectId,$dataformId]);
                if($ownTx) $this->pdo->commit();
                return $this->dataformLimit($licenseId)+['claimed'=>false,'already_active'=>true];
            }

            $limit=$this->dataformLimit($licenseId);
            if($limit['max_dataforms']!==null && $limit['used_dataforms'] >= $limit['max_dataforms']) {
                throw new \RuntimeException('DATAFORM_LIMIT_REACHED');
            }

            if($existing) {
                $up=$this->pdo->prepare("UPDATE license_dataform_usage SET status='active',first_used_at=COALESCE(first_used_at,?),last_used_at=?,released_at=NULL WHERE license_id=? AND project_id=? AND dataform_id=?");
                $up->execute([$now,$now,$licenseId,$projectId,$dataformId]);
            } else {
                $ins=$this->pdo->prepare("INSERT INTO license_dataform_usage (license_id,project_id,dataform_id,status,first_used_at,last_used_at,released_at) VALUES (?,?,?,'active',?,?,NULL)");
                $ins->execute([$licenseId,$projectId,$dataformId,$now,$now]);
            }
            $after=$this->dataformLimit($licenseId);
            if($ownTx) $this->pdo->commit();
            return $after+['claimed'=>true,'already_active'=>false];
        } catch(\Throwable $e) {
            if($ownTx && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function releaseDataform(string $licenseId,string $projectId,string $dataformId): bool
    {
        $st=$this->pdo->prepare("UPDATE license_dataform_usage SET status='released',released_at=?,last_used_at=? WHERE license_id=? AND project_id=? AND dataform_id=? AND status='active'");
        $now=time();
        $st->execute([$now,$now,$licenseId,$projectId,$dataformId]);
        return $st->rowCount()>0;
    }

    /**
     * Returns the global license capability whitelist.
     * Null means legacy/unrestricted policy (no capability rows configured yet).
     * @return ?array<string,bool>
     */
    public function globalCapabilityMap(string $licenseId): ?array
    {
        $st=$this->pdo->prepare('SELECT capability_code,enabled FROM license_capabilities WHERE license_id=?');
        $st->execute([$licenseId]);
        $rows=$st->fetchAll();
        if(!$rows) return null;
        $map=[];
        foreach($rows as $r) $map[(string)$r['capability_code']]=(int)$r['enabled']===1;
        return $map;
    }

    /** @return array<string,bool> */
    public function dataformCapabilityOverrides(string $licenseId,string $projectId,string $dataformId): array
    {
        $st=$this->pdo->prepare('SELECT capability_code,enabled FROM license_dataform_capabilities WHERE license_id=? AND project_id=? AND dataform_id=?');
        $st->execute([$licenseId,$projectId,$dataformId]);
        $map=[];
        foreach($st->fetchAll() as $r) $map[(string)$r['capability_code']]=(int)$r['enabled']===1;
        return $map;
    }

    /**
     * Applies the license as an upper bound. Per-DataForm overrides may only reduce,
     * never expand a globally denied capability.
     * @param string[] $requested
     * @return string[]
     */
    public function effectiveCapabilities(string $licenseId,string $projectId,string $dataformId,array $requested): array
    {
        $global=$this->globalCapabilityMap($licenseId);
        $overrides=$this->dataformCapabilityOverrides($licenseId,$projectId,$dataformId);
        $out=[];
        foreach(array_values(array_unique(array_map('strval',$requested))) as $cap) {
            $allowed=$global===null ? true : (($global[$cap]??false)===true);
            if(isset($overrides[$cap]) && $overrides[$cap]===false) $allowed=false;
            // Explicit true override cannot widen a global deny.
            if($allowed) $out[]=$cap;
        }
        return $out;
    }

    /** @return ?string[] Null means legacy/unrestricted policy. */
    public function allowedCapabilitiesForDataform(string $licenseId,string $projectId,string $dataformId): ?array
    {
        $global=$this->globalCapabilityMap($licenseId);
        $overrides=$this->dataformCapabilityOverrides($licenseId,$projectId,$dataformId);
        if($global===null && $overrides===[]) return null;
        if($global===null) {
            $global=[];
            $st=$this->pdo->query('SELECT capability_code FROM capability_registry WHERE active=1');
            foreach($st->fetchAll() as $row) $global[(string)$row['capability_code']]=true;
        }
        $out=[];
        foreach($global as $code=>$enabled) {
            if(!$enabled) continue;
            if(isset($overrides[$code]) && $overrides[$code]===false) continue;
            $out[]=$code;
        }
        sort($out);
        return $out;
    }

    /** HF86 Phase 2: resolved per-DataForm policy for management/runtime surfaces. */
    public function resolvedDataformPolicy(string $licenseId,string $projectId,string $dataformId): array
    {
        $allowed=$this->allowedCapabilitiesForDataform($licenseId,$projectId,$dataformId);
        $policyKind='explicit';
        if($allowed===null){
            $policyKind='legacy_unrestricted';
            $allowed=[];
            $st=$this->pdo->query('SELECT capability_code FROM capability_registry WHERE active=1 ORDER BY category,capability_code');
            foreach($st->fetchAll() as $row)$allowed[]=(string)$row['capability_code'];
        }
        $overrides=$this->dataformCapabilityOverrides($licenseId,$projectId,$dataformId);
        $disabled=[];
        foreach($overrides as $code=>$enabled)if(!$enabled)$disabled[]=$code;
        sort($allowed);sort($disabled);
        return [
            'capability_policy'=>$policyKind,
            'capabilities'=>array_values(array_unique($allowed)),
            'disabled_capabilities'=>array_values(array_unique($disabled)),
            'has_dataform_overrides'=>$overrides!==[],
        ];
    }

    public function requireCapability(string $licenseId,string $projectId,string $dataformId,string $capability): void
    {
        $effective=$this->effectiveCapabilities($licenseId,$projectId,$dataformId,[$capability]);
        if(!in_array($capability,$effective,true)) throw new \RuntimeException('CAPABILITY_NOT_LICENSED');
    }

    public function policySummary(string $licenseId): array
    {
        $limit=$this->dataformLimit($licenseId);
        $global=$this->globalCapabilityMap($licenseId);
        $enabled=[];$disabled=[];
        if($global!==null) {
            foreach($global as $code=>$ok) { if($ok) $enabled[]=$code; else $disabled[]=$code; }
            sort($enabled);sort($disabled);
        }
        return $limit+[
            'capability_policy'=>$global===null?'legacy_unrestricted':'explicit',
            'capabilities'=>$enabled,
            'disabled_capabilities'=>$disabled,
        ];
    }
}
