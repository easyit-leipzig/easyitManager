<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class Alert implements ComponentInterface
{
    public function __construct(private string $message,private string $type='info',private ?string $title=null){ }
    public function render(): string
    {
        $type=preg_replace('/[^a-z0-9_-]/i','',$this->type);
        $title=$this->title!==null?'<strong class="df5-alert__title">'.htmlspecialchars($this->title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</strong>':'';
        return '<div class="df5-alert df5-alert--'.$type.'" role="status">'.$title.'<div class="df5-alert__message">'.htmlspecialchars($this->message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</div></div>';
    }
}
