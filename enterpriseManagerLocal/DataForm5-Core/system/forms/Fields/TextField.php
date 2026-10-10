<?php
declare(strict_types=1);
namespace DataForm5\Forms\Fields;
use DataForm5\Forms\Core\Field;
final class TextField extends Field
{
    public function __construct(string $name, string $label='', string|array $rules=[], array $attributes=[], mixed $value=null)
    { parent::__construct($name,'text',$label,$rules,$attributes,[], $value); }
}
