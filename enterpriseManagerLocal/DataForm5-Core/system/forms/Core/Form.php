<?php
declare(strict_types=1);
namespace DataForm5\Forms\Core;
use DataForm5\Forms\Contracts\{FieldInterface,FormInterface};
use DataForm5\Forms\Exceptions\FormException;
use DataForm5\Validation\Core\Validator;
class Form implements FormInterface
{
    /** @var array<string,FieldInterface> */ private array $fieldMap=[];
    private array $bound=[];
    public function __construct(private readonly string $formName, array $fields, private readonly Validator $validator, private readonly array $attributes=[])
    {
        foreach ($fields as $field) {
            if (!$field instanceof FieldInterface) throw new FormException('Formularfelder müssen FieldInterface implementieren.');
            if (isset($this->fieldMap[$field->name()])) throw new FormException('Doppeltes Formularfeld: '.$field->name());
            $this->fieldMap[$field->name()]=$field;
        }
    }
    public function name(): string { return $this->formName; }
    public function fields(): array { return array_values($this->fieldMap); }
    public function attributes(): array { return $this->attributes; }
    public function bind(array $data): static { $clone=clone $this; $clone->bound=$data; foreach($clone->fieldMap as $name=>$field){$clone->fieldMap[$name]=$field->withValue(self::get($data,$name));} return $clone; }
    public function values(): array { $out=[]; foreach($this->fieldMap as $name=>$field){self::set($out,$name,$field->value());} return $out; }
    public function validate(array $data): FormResult
    {
        $rules=[]; foreach($this->fieldMap as $name=>$field){$rules[$name]=$field->rules();}
        $result=$this->validator->validate($data,$rules);
        $clean = []; foreach ($this->fieldMap as $name => $_field) { $value = self::get($data, $name); self::set($clean, $name, $value); }
        return new FormResult($result->passes() ? $result->validated() : $clean, $result->errors());
    }
    private static function get(array $data,string $path): mixed { $v=$data; foreach(explode('.',$path) as $s){if(!is_array($v)||!array_key_exists($s,$v))return null;$v=$v[$s];} return $v; }
    private static function set(array &$data,string $path,mixed $value): void { $ref=&$data;$parts=explode('.',$path);foreach($parts as $i=>$s){if($i===count($parts)-1){$ref[$s]=$value;break;}if(!isset($ref[$s])||!is_array($ref[$s]))$ref[$s]=[];$ref=&$ref[$s];} }
}
