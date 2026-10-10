<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\ProtectedCore;

final class ActionResolver
{
    private const WRITE_CAPS=['dataset.create','dataset.update','dataset.delete','relation.createChild','relation.attach','relation.detach'];
    public function resolve(array $manifest,string $action,array $resource,array $changes):array
    {
        if(!in_array($action,self::WRITE_CAPS,true)) throw new \RuntimeException('ACTION_NOT_ALLOWED');
        if(!in_array($action,$manifest['capabilities']??[],true)) throw new \RuntimeException('ACTION_NOT_ALLOWED');
        $allowedFields=[];$fieldMap=[];foreach($manifest['fields']??[] as $f)$fieldMap[(string)$f['name']]=$f;
        foreach(($changes['fields']??[]) as $name){$name=(string)$name;if(isset($fieldMap[$name])&&!($fieldMap[$name]['readonly']??false))$allowedFields[]=$name;}
        $recordId=(string)($resource['record_id']??'');$recordIds=array_values(array_unique(array_filter(array_map('strval',(array)($resource['record_ids']??[])),static fn(string $id):bool=>$id!==''&&$id!=='0')));
        if($action==='dataset.update' && $recordId==='') throw new \RuntimeException('ACTION_RESOURCE_INVALID');
        if($action==='dataset.delete' && $recordId==='' && $recordIds===[]) throw new \RuntimeException('ACTION_RESOURCE_INVALID');
        $decision=['operation'=>$action,'resource'=>['dataform_id'=>(string)($resource['dataform_id']??$manifest['binding']['dataform_id']??''),'record_id'=>$recordId,'record_ids'=>$recordIds],'allowed_fields'=>array_values(array_unique($allowedFields))];
        if($action==='relation.createChild'){
            $wanted=(string)($resource['relation']??'');foreach($manifest['relations']??[] as $rel){if((string)($rel['name']??'')===$wanted){$decision['binding']=$rel['binding']??null;break;}}
            if(empty($decision['binding']))throw new \RuntimeException('RELATION_NOT_ALLOWED');
        }
        return $decision;
    }
}
