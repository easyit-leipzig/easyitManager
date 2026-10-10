<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;
require_once __DIR__.'/LicenseConfigStore.php';

use PDO;

/** HF86 Phase 2: resolves the live central capability policy for one approved DataForm. */
final class EnterpriseDataFormPolicyBridge
{
    /** @var array<string,array> */
    private static array $cache=[];

    public static function policy(string $enterpriseRoot,PDO $db,string|int $projectId,int $dataformId,bool $allowUnapproved=true): ?array
    {
        if($dataformId<1) return null;
        DataFormLicenseIdentity::ensureSchema($db);
        $key=(string)$projectId.'|'.$dataformId;
        if(isset(self::$cache[$key])) return self::$cache[$key];

        $row=self::row($db,$dataformId);
        if(!$row) throw new \RuntimeException('DATAFORM_NOT_FOUND');
        if(function_exists('enterprise_user')&&function_exists('enterprise_dataform_scope_allowed')){
            $scopeUser=\enterprise_user();
            if(is_array($scopeUser)&&!empty($scopeUser['customer'])&&!\enterprise_dataform_scope_allowed($scopeUser,$projectId,$dataformId,(string)($row['df_uuid']??''))) throw new \RuntimeException('DATAFORM_SCOPE_DENIED');
        }
        if((string)($row['license_status']??'')!=='approved'||trim((string)($row['license_token']??''))==='') {
            EnterpriseDataFormRegistryBridge::synchronizeProject($enterpriseRoot,$db,$projectId);
            $row=self::row($db,$dataformId);
        }
        if(!$row||(string)($row['license_status']??'')!=='approved'||trim((string)($row['license_token']??''))==='') {
            if($allowUnapproved) return null;
            throw new \RuntimeException('DATAFORM_NOT_APPROVED');
        }

        $cfg=\EnterpriseLicenseConfigStore::load($enterpriseRoot);
        if(!is_array($cfg)||empty($cfg['enabled'])) throw new \RuntimeException('DATAFORM_LICENSE_DISABLED');
        $api=new LicenseApiClient($cfg);
        $policy=$api->dataFormPolicy((string)$projectId,(string)$dataformId,(string)$row['license_token']);
        $caps=array_values(array_unique(array_map('strval',(array)($policy['capabilities']??[]))));
        if(function_exists('enterprise_user')){ $u=\enterprise_user(); if(is_array($u)&&!empty($u['customer'])){ $rp=(array)($u['role_policy']??[]); $rc=array_values(array_unique(array_map('strval',(array)($rp['capabilities']??[])))); $ru=$rp===[]||!empty($rp['unrestricted'])||in_array('*',$rc,true); if(!$ru)$caps=array_values(array_intersect($caps,$rc)); $policy['role_policy_applied']=true; }}
        sort($caps);
        $policy['capabilities']=$caps;
        $policy['capability_map']=array_fill_keys($caps,true);
        self::$cache[$key]=$policy;
        return $policy;
    }

    public static function allows(?array $policy,string $capability,bool $globalFallback): bool
    {
        if($policy===null) return $globalFallback;
        return isset(((array)($policy['capability_map']??[]))[$capability]);
    }

    public static function require(?array $policy,string $capability,bool $globalFallback=true): void
    {
        if(!self::allows($policy,$capability,$globalFallback)) throw new \RuntimeException('CAPABILITY_NOT_LICENSED');
    }

    private static function row(PDO $db,int $dataformId): ?array
    {
        $st=$db->prepare('SELECT id,df_uuid,license_status,license_token,license_token_key_id,license_token_issued_at FROM dataforms WHERE id=? LIMIT 1');
        $st->execute([$dataformId]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }
}
