<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class Badge implements ComponentInterface
{
    public function __construct(private string $label,private string $variant='neutral'){ }
    public function render(): string
    {
        $variant=preg_replace('/[^a-z0-9_-]/i','',$this->variant);
        return '<span class="df5-badge df5-badge--'.$variant.'">'.htmlspecialchars($this->label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>';
    }
}
