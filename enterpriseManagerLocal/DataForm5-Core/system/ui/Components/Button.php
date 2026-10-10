<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;use DataForm5\UI\Core\Attributes;
final class Button implements ComponentInterface
{
    public function __construct(private string $label,private string $href='#',private string $variant='primary',private array $attributes=[]){ }
    public function render(): string
    {
        $class='df5-btn df5-btn--'.preg_replace('/[^a-z0-9_-]/i','',$this->variant);
        $attrs=array_merge(['class'=>$class,'href'=>$this->href],$this->attributes);
        return '<a'.Attributes::render($attrs).'>'.htmlspecialchars($this->label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</a>';
    }
}
