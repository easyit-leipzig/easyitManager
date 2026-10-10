<?php
declare(strict_types=1);
namespace DataForm5\Modules\Config;
final class ModuleConfigSchema
{
    /** @param array<string,array<string,mixed>> $fields */
    public function __construct(public readonly array $fields) {}
    public static function fromModule(string $modulePath): self
    {
        $file=rtrim($modulePath,'/\\').'/config/schema.php';
        $data=is_file($file)?require $file:[];
        return new self(is_array($data)?$data:[]);
    }
    public function defaults(): array
    {
        $out=[]; foreach($this->fields as $name=>$def) if(array_key_exists('default',$def)) $out[$name]=$def['default']; return $out;
    }
    public function secrets(): array
    {
        return array_keys(array_filter($this->fields,static fn($d)=>is_array($d)&&($d['secret']??false)===true));
    }
    public function validate(array $values): array
    {
        $errors=[]; $clean=[];
        foreach($this->fields as $name=>$def){
            if(!is_array($def)) continue; $value=$values[$name]??($def['default']??null);
            if(($def['required']??false)&&($value===null||$value==='')){$errors[$name]='Pflichtfeld fehlt.';continue;}
            if($value===null){$clean[$name]=null;continue;}
            $type=(string)($def['type']??'string');
            try{$clean[$name]=match($type){
                'bool'=>filter_var($value,FILTER_VALIDATE_BOOL,FILTER_NULL_ON_FAILURE)??throw new \InvalidArgumentException(),
                'int'=>filter_var($value,FILTER_VALIDATE_INT)!==false?(int)$value:throw new \InvalidArgumentException(),
                'float'=>is_numeric($value)?(float)$value:throw new \InvalidArgumentException(),
                'array'=>is_array($value)?$value:(json_decode((string)$value,true,512,JSON_THROW_ON_ERROR)),
                default=>(string)$value,
            };}catch(\Throwable){$errors[$name]="Ungültiger Wert für Typ {$type}.";continue;}
            if(isset($def['options'])&&is_array($def['options'])&&!in_array($clean[$name],$def['options'],true))$errors[$name]='Wert ist nicht erlaubt.';
        }
        return ['valid'=>$errors===[],'values'=>$clean,'errors'=>$errors];
    }
}
