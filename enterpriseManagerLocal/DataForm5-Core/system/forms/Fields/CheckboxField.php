<?php
declare(strict_types=1);
namespace DataForm5\Forms\Fields;
use DataForm5\Forms\Core\Field;
final class CheckboxField extends Field
{
    public function __construct(string $name, string $label='', string|array $rules='boolean', array $attributes=[], mixed $value=false)
    { parent::__construct($name,'checkbox',$label,$rules,$attributes,[], $value); }
}
