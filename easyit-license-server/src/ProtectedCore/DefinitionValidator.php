<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\ProtectedCore;

final class DefinitionValidator
{
    private const FIELD_TYPES = ['text','textarea','integer','decimal','date','datetime','boolean','enum','json','link','image'];
    private const DATAFORM_EVENTS = ['open','close','before_view_change','view_change','before_refresh','after_refresh'];
    private const DATASET_EVENTS = ['before_current_change','after_current_change','before_field_change','after_field_change','before_new','after_new','before_validate','after_validate','before_save','after_save','before_insert','after_insert','before_update','after_update','before_delete','after_delete'];

    public function validate(array $definition): array
    {
        $errors=[];$warnings=[];$names=[];
        $fields=$definition['fields']??[];
        if(!is_array($fields))$errors[]=$this->err('FIELDS_INVALID','fields');
        else foreach($fields as $i=>$field){
            if(!is_array($field)){$errors[]=$this->err('FIELD_INVALID','fields.'.$i);continue;}
            $name=trim((string)($field['name']??''));$type=(string)($field['type']??'text');
            if($name===''||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$name))$errors[]=$this->err('FIELD_NAME_INVALID','fields.'.$i.'.name');
            if(isset($names[strtolower($name)]))$errors[]=$this->err('FIELD_NAME_DUPLICATE','fields.'.$i.'.name');
            $names[strtolower($name)]=true;
            if(!in_array($type,self::FIELD_TYPES,true))$errors[]=$this->err('FIELD_TYPE_NOT_SUPPORTED','fields.'.$i.'.type');
        }
        $events=$definition['events']??[];
        foreach((array)($events['dataform']??[]) as $e)if(!in_array((string)$e,self::DATAFORM_EVENTS,true))$errors[]=$this->err('EVENT_SCOPE_INVALID','events.dataform.'.$e);
        foreach((array)($events['dataset']??[]) as $e)if(!in_array((string)$e,self::DATASET_EVENTS,true))$errors[]=$this->err('EVENT_SCOPE_INVALID','events.dataset.'.$e);
        foreach((array)($definition['relations']??[]) as $i=>$r){
            if(!is_array($r)){$errors[]=$this->err('RELATION_INVALID','relations.'.$i);continue;}
            if(!in_array((string)($r['type']??''),['1:n','n:1','1:1','n:m'],true))$errors[]=$this->err('RELATION_TYPE_INVALID','relations.'.$i.'.type');
            if(($r['type']??'')==='1:n'&&empty($r['child_field']))$errors[]=$this->err('RELATION_CHILD_FIELD_REQUIRED','relations.'.$i.'.child_field');
        }
        $view=$definition['view']??[];
        if(isset($view['page_size'])&&((int)$view['page_size']<1||(int)$view['page_size']>1000))$errors[]=$this->err('PAGE_SIZE_INVALID','view.page_size');
        return ['valid'=>$errors===[],'errors'=>$errors,'warnings'=>$warnings,'registry'=>['field_types'=>self::FIELD_TYPES,'dataform_events'=>self::DATAFORM_EVENTS,'dataset_events'=>self::DATASET_EVENTS]];
    }
    private function err(string $code,string $path):array{return ['code'=>$code,'path'=>$path];}
}
