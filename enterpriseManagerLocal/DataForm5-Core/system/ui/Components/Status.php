<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class Status implements ComponentInterface
{
    public function __construct(private string $label,private string $state){ }
    public function render(): string
    {
        $state=preg_replace('/[^a-z0-9_-]/i','',$this->state);
        return '<span class="df5-status df5-status--'.$state.'"><span class="df5-status__dot" aria-hidden="true"></span>'.htmlspecialchars($this->label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</span>';
    }
}
