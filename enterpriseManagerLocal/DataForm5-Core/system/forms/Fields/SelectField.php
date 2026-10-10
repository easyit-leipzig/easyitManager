<?php
declare(strict_types=1);
namespace DataForm5\Forms\Fields;
use DataForm5\Forms\Core\Field;
final class SelectField extends Field
{
    public function __construct(string $name, array $options, string $label='', string|array $rules=[], array $attributes=[], mixed $value=null)
    { parent::__construct($name,'select',$label,$rules,$attributes,$options,$value); }
}
