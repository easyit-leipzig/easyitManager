<?php
declare(strict_types=1);
namespace EasyIT\LicenseServer\ProtectedCore;

final class DataFormCompiler
{
    /**
     * @param ?string[] $licensedCapabilities Null keeps legacy behavior.
     */
    public function compile(
        array $definition,
        array $binding,
        int $issued,
        int $leaseUntil,
        int $graceUntil,
        string $runtimeId,
        ?array $licensedCapabilities=null,
        array $licensePolicy=[]
    ): array {
        $view=$definition['view']??[];
        $fields=[];
        foreach(($definition['fields']??[]) as $f){
            if(!is_array($f)||empty($f['name'])) continue;
            $fields[]=[
                'name'=>(string)$f['name'],
                'type'=>(string)($f['type']??'text'),
                'readonly'=>(bool)($f['readonly']??false),
                'required'=>(bool)($f['required']??false),
            ];
        }

        $requested=array_values(array_unique(array_map('strval',$definition['capabilities']??['dataset.read'])));
        if(!empty($view['fulltext_search'])) $requested[]='dataform.search';
        if(!empty($view['filter'])) $requested[]='dataform.filter';
        if(!array_key_exists('pagination',$view) || !empty($view['pagination'])) $requested[]='dataform.pagination';
        if(trim((string)($view['addClass']??''))!=='') $requested[]='dataform.custom_css';
        if(trim((string)($view['addJavaScript']??''))!=='') $requested[]='dataform.custom_javascript';
        if(!empty($definition['events']['dataform']) || !empty($definition['events']['dataset'])) $requested[]='dataform.events';
        if(!empty($definition['relations'])) $requested[]='relation.read';
        $requested=array_values(array_unique($requested));
        $caps=$licensedCapabilities===null
            ? $requested
            : array_values(array_intersect($requested,array_values(array_unique(array_map('strval',$licensedCapabilities)))));
        $has=static fn(string $cap):bool=>in_array($cap,$caps,true);

        $dataformEvents=array_values(array_map('strval',$definition['events']['dataform']??['open','close']));
        $datasetEvents=array_values(array_map('strval',$definition['events']['dataset']??[]));
        if(!$has('dataform.events')){
            $dataformEvents=[];
            $datasetEvents=[];
        }

        $relations=array_values(array_filter($definition['relations']??[], 'is_array'));
        if(!$has('relation.read')) $relations=[];

        return [
            'manifest_version'=>3,
            'runtime'=>[
                'id'=>$runtimeId,
                'issued_at'=>$issued,
                'lease_until'=>$leaseUntil,
                'grace_until'=>$graceUntil,
            ],
            'binding'=>$binding,
            'license_policy'=>[
                'max_dataforms'=>$licensePolicy['max_dataforms']??null,
                'used_dataforms'=>$licensePolicy['used_dataforms']??null,
                'available_dataforms'=>$licensePolicy['available_dataforms']??null,
            ],
            'view'=>[
                'mode'=>(string)($view['mode']??'form'),
                'page_size'=>(int)($view['page_size']??20),
                'fulltext_search'=>(bool)($view['fulltext_search']??false) && $has('dataform.search'),
                'filter'=>(bool)($view['filter']??false) && $has('dataform.filter'),
                'pagination'=>$has('dataform.pagination'),
                'addClass'=>$has('dataform.custom_css') ? (string)($view['addClass']??'') : '',
                'addJavaScript'=>$has('dataform.custom_javascript') ? (string)($view['addJavaScript']??'') : '',
            ],
            'capabilities'=>$caps,
            'fields'=>$fields,
            'events'=>[
                'dataform'=>$dataformEvents,
                'dataset'=>$datasetEvents,
            ],
            'relations'=>$relations,
        ];
    }
}
