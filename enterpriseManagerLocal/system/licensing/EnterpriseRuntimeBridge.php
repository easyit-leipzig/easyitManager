<?php
declare(strict_types=1);
namespace EasyIT\Enterprise\Licensing;
require_once __DIR__.'/LicenseConfigStore.php';

final class EnterpriseRuntimeBridge
{
    private static ?DataFormRuntimeClient $runtime=null;
    private static ?array $session=null;
    private static ?array $pendingAction=null;

    public static function boot(string $root,string|int $projectId,string|int $dataformId):array
    {
        if(function_exists('enterprise_user')&&function_exists('enterprise_require_dataform_scope')){
            $scopeUser=\enterprise_user();
            if(is_array($scopeUser)&&!empty($scopeUser['customer'])) \enterprise_require_dataform_scope($scopeUser,$projectId,$dataformId);
        }
        $cfg=\EnterpriseLicenseConfigStore::load($root);
        if(empty($cfg['enabled']))throw new \RuntimeException('DATAFORM_LICENSE_DISABLED');
        $api=new LicenseApiClient($cfg);
        self::$runtime=new DataFormRuntimeClient($api);
        self::$session=self::$runtime->start($projectId,$dataformId);
        $manifest=self::$session['manifest']??[];
        if(!is_array($manifest)||empty($manifest['runtime']['id']))throw new \RuntimeException('RUNTIME_MANIFEST_INVALID');
        if(function_exists('enterprise_user')&&function_exists('enterprise_license_capability')){ $u=\enterprise_user(); if(is_array($u)&&!empty($u['customer'])){ $manifest['capabilities']=array_values(array_filter(array_map('strval',(array)($manifest['capabilities']??[])),static fn(string $c):bool=>\enterprise_license_capability($u,$c))); }}
        return $manifest;
    }

    public static function authorizePost(array &$post):?array
    {
        if(!self::$runtime||!self::$session)return null;
        $raw=(string)($post['action']??'');
        $rid=(string)($post['record']??$post['id']??'');
        $action=match($raw){
            'save_record','update'=>$rid!==''&&$rid!=='0'?'dataset.update':'dataset.create',
            'create'=>'dataset.create',
            'delete_record','delete','bulk_delete'=>'dataset.delete',
            default=>null
        };
        if($action===null)return null;
        if(function_exists('enterprise_user')&&function_exists('enterprise_license_capability')){ $u=\enterprise_user(); if(is_array($u)&&!empty($u['customer'])&&!\enterprise_license_capability($u,$action))throw new \RuntimeException('CAPABILITY_NOT_ALLOWED_FOR_ROLE'); }

        $fields=[];
        if(isset($post['values'])&&is_array($post['values']))$fields=array_map('strval',array_keys($post['values']));
        $extra=[];
        if($raw==='bulk_delete'){
            $ids=array_values(array_unique(array_filter(array_map('intval',(array)($post['record_ids']??$post['selected']??[])),static fn(int $id):bool=>$id>0)));
            if($ids===[])throw new \RuntimeException('ACTION_RESOURCE_INVALID');
            $extra['record_ids']=$ids;
            $rid='';
        }
        $decision=self::$runtime->action((string)self::$session['runtime_id'],$action,$rid,$fields,$extra);
        self::$pendingAction=$decision['decision']??null;
        if(!is_array(self::$pendingAction)||empty(self::$pendingAction['action_id']))throw new \RuntimeException('ACTION_TOKEN_INVALID');

        if(isset($post['values'])&&is_array($post['values'])&&in_array($action,['dataset.create','dataset.update'],true)){
            $allowed=array_fill_keys(array_map('strval',(array)(self::$pendingAction['allowed_fields']??[])),true);
            foreach(array_keys($post['values']) as $name){
                if(!isset($allowed[(string)$name]))unset($post['values'][$name]);
            }
        }
        return $decision;
    }

    public static function finalize(bool $success,string $code='OK'):void
    {
        if(!self::$runtime||!self::$pendingAction||empty(self::$pendingAction['action_id']))return;
        try{self::$runtime->actionResult((string)self::$pendingAction['action_id'],$success?'completed':'failed',$code);}catch(\Throwable){}
        self::$pendingAction=null;
    }

    public static function runtimeId():?string{return self::$session['runtime_id']??null;}
    public static function state():?string{return self::$session['state']??null;}
}
