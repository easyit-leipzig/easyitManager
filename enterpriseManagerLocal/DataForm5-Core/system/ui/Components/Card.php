<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class Card implements ComponentInterface
{
    public function __construct(private string $title,private string $content,private ?string $footer=null){ }
    public function render(): string
    {
        $footer=$this->footer!==null?'<footer class="df5-card__footer">'.$this->footer.'</footer>':'';
        return '<section class="df5-card"><header class="df5-card__header"><h2>'.htmlspecialchars($this->title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h2></header><div class="df5-card__body">'.$this->content.'</div>'.$footer.'</section>';
    }
}
