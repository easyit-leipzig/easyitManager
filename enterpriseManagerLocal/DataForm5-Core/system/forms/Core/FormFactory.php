<?php
declare(strict_types=1);
namespace DataForm5\Forms\Core;
use DataForm5\Validation\Core\Validator;
use DataForm5\Forms\Fields\{CheckboxField,SelectField,TextField};
final class FormFactory
{
    public function __construct(private readonly Validator $validator) {}
    public function builder(string $name): FormBuilder { return new FormBuilder($this->validator,$name); }
    /** @param array{attributes?:array<string,mixed>,fields?:array<string,array<string,mixed>>} $definition */
    public function fromDefinition(string $name,array $definition): Form
    {
        $builder=$this->builder($name)->attributes((array)($definition['attributes']??[]));
        foreach((array)($definition['fields']??[]) as $fieldName=>$config){
            if(!is_array($config)) continue;
            $type=strtolower((string)($config['type']??'text'));
            $label=(string)($config['label']??'');
            $rules=$config['rules']??[];
            $attrs=(array)($config['attributes']??[]);
            if(in_array($type,['email','password','number','date','url','hidden'],true)) $attrs=['type'=>$type]+$attrs;
            if($type==='select') $builder->select((string)$fieldName,(array)($config['options']??[]),$label,$rules,$attrs);
            elseif($type==='checkbox') $builder->checkbox((string)$fieldName,$label,$rules?:'boolean',$attrs);
            else $builder->field(new Field((string)$fieldName,$type==='textarea'?'textarea':($type==='text'?'text':$type),$label,$rules,$attrs));
        }
        return $builder->build();
    }
    public function fromFile(string $name,string $file): Form
    {
        if(!is_file($file)) throw new \RuntimeException('Formulardefinition nicht gefunden: '.$file);
        $definition=require $file;
        if(!is_array($definition)) throw new \RuntimeException('Formulardefinition muss ein Array zurückgeben.');
        return $this->fromDefinition($name,$definition);
    }
}
