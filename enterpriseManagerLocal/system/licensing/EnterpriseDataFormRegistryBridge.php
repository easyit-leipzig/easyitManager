<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;
require_once __DIR__.'/LicenseConfigStore.php';

use PDO;

/** HF85 Phase 1-4: registriert DataForms, synchronisiert Freigaben/Tokens und steuert die zentrale Freigabe vor lokalem Loeschen. */
final class EnterpriseDataFormRegistryBridge
{
    public static function synchronizeProject(string $enterpriseRoot,PDO $db,string|int $projectId): array
    {
        DataFormLicenseIdentity::ensureSchema($db);
        $cfg=\EnterpriseLicenseConfigStore::load($enterpriseRoot);
        if(!is_array($cfg)) return ['registered'=>0,'pending'=>0,'approved'=>0,'errors'=>['Lizenzkonfiguration ist ungültig.']];
        $api=new LicenseApiClient($cfg);
        $rows=$db->query("SELECT id,name,slug,df_uuid,license_status,license_registered_at,license_token,license_token_key_id,license_token_issued_at FROM dataforms ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $registered=0;$pending=0;$approved=0;$errors=[];
        foreach($rows as $row) {
            $status=(string)($row['license_status']??'unlicensed');
            // pending wird bewusst erneut beim Server abgefragt; approved wird in Phase 3
            // weiter synchronisiert, bis der signierte Token
            // lokal angekommen ist. rejected/revoked/released bleiben terminal.
            if(in_array($status,['rejected','revoked','released','embedded_calendar'],true)) continue;
            try {
                $result=$api->registerDataForm((string)$projectId,(string)$row['id'],(string)$row['df_uuid'],(string)$row['name'],(string)$row['slug']);
                $remoteStatus=(string)($result['status']??'pending');
                if(!in_array($remoteStatus,['pending','approved','rejected','revoked','released'],true)) $remoteStatus='pending';
                $registeredAt=(int)($row['license_registered_at']??0);if($registeredAt<=0)$registeredAt=time();
                $token='';$tokenKey='';$tokenIssued=null;
                if($remoteStatus==='approved') {
                    $token=trim((string)($result['df_license_token']??''));
                    $tokenKey=trim((string)($result['df_license_token_key_id']??''));
                    $tokenIssued=(int)($result['df_license_token_issued_at']??0);
                    if($token===''||$tokenKey===''||$tokenIssued<=0) throw new \RuntimeException('DATAFORM_TOKEN_MISSING');
                    $approved++;
                }
                $up=$db->prepare('UPDATE dataforms SET license_status=?,license_registered_at=?,license_token=?,license_token_key_id=?,license_token_issued_at=? WHERE id=?');
                $up->execute([$remoteStatus,$registeredAt,$token!==''?$token:null,$tokenKey!==''?$tokenKey:null,$tokenIssued?:null,(int)$row['id']]);
                $registered++; if($remoteStatus==='pending')$pending++;
            } catch(\Throwable $e) {
                $up=$db->prepare("UPDATE dataforms SET license_status='registration_error' WHERE id=?");
                $up->execute([(int)$row['id']]);
                $errors[]=(string)$row['name'].': '.$e->getMessage();
            }
        }
        return ['registered'=>$registered,'pending'=>$pending,'approved'=>$approved,'errors'=>$errors];
    }

    /**
     * HF85 Phase 4: central release is mandatory before the local destructive delete.
     * After the server confirms release, local token material and offline runtime cache
     * are invalidated immediately. The final database delete can then be retried safely.
     */
    public static function releaseForDelete(string $enterpriseRoot,PDO $db,string|int $projectId,int $dataformId): array
    {
        if($dataformId<1) throw new \RuntimeException('DATAFORM_REQUIRED');
        DataFormLicenseIdentity::ensureSchema($db);
        $st=$db->prepare('SELECT id,name,slug,df_uuid,license_status FROM dataforms WHERE id=? LIMIT 1');
        $st->execute([$dataformId]);
        $row=$st->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new \RuntimeException('DATAFORM_NOT_FOUND');
        $uuid=strtolower(trim((string)($row['df_uuid']??'')));
        if(!DataFormLicenseIdentity::isUuidV4($uuid)) throw new \RuntimeException('DATAFORM_UUID_INVALID');
        if((string)($row['license_status']??'')==='embedded_calendar') {
            return [
                'status'=>'embedded_calendar',
                'local_status'=>'embedded_calendar',
                'slot_released'=>false,
                'already_released'=>true,
                'embedded_product'=>'calendar',
            ];
        }

        $cfg=\EnterpriseLicenseConfigStore::load($enterpriseRoot);
        if(!is_array($cfg)||empty($cfg['enabled'])) throw new \RuntimeException('DATAFORM_LICENSE_DISABLED');
        $api=new LicenseApiClient($cfg);
        $remote=$api->releaseDataForm((string)$projectId,(string)$dataformId,$uuid,(string)$row['name'],(string)$row['slug']);
        if((string)($remote['status']??'')!=='released') throw new \RuntimeException('DATAFORM_RELEASE_NOT_CONFIRMED');

        // Fail closed before the local delete: no token and no offline cache may survive.
        DataFormRuntimeClient::invalidateReleasedCache($api,(string)$projectId,(string)$dataformId);
        $up=$db->prepare("UPDATE dataforms SET license_status='released',license_token=NULL,license_token_key_id=NULL,license_token_issued_at=NULL WHERE id=?");
        $up->execute([$dataformId]);
        if($up->rowCount()===0) {
            $verify=$db->prepare('SELECT license_status FROM dataforms WHERE id=? LIMIT 1');
            $verify->execute([$dataformId]);
            if((string)$verify->fetchColumn()!=='released') throw new \RuntimeException('DATAFORM_LOCAL_RELEASE_MARK_FAILED');
        }
        return $remote+['local_status'=>'released','dataform_name'=>(string)$row['name']];
    }

}
