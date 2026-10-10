<?php
declare(strict_types=1);
namespace DataForm5\Forms\Core;
use DataForm5\Forms\Contracts\FieldInterface;
use DataForm5\Forms\Fields\{CheckboxField,SelectField,TextField};
use DataForm5\Validation\Core\Validator;
final class FormBuilder
{
    private array $fields=[]; private array $attributes=[];
    public function __construct(private readonly Validator $validator, private readonly string $name) {}
    public function field(FieldInterface $field): self { $this->fields[]=$field; return $this; }
    public function text(string $name,string $label='',string|array $rules=[],array $attributes=[]): self { return $this->field(new TextField($name,$label,$rules,$attributes)); }
    public function select(string $name,array $options,string $label='',string|array $rules=[],array $attributes=[]): self { return $this->field(new SelectField($name,$options,$label,$rules,$attributes)); }
    public function checkbox(string $name,string $label='',string|array $rules='boolean',array $attributes=[]): self { return $this->field(new CheckboxField($name,$label,$rules,$attributes)); }
    public function attributes(array $attributes): self { $this->attributes=$attributes; return $this; }
    public function build(): Form { return new Form($this->name,$this->fields,$this->validator,$this->attributes); }
}
