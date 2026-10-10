<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class HelpPanel implements ComponentInterface
{
    /** @param array<string,string|list<string>> $modes */
    public function __construct(private string $title,private array $modes,private string $activeMode='short'){ }
    public function render(): string
    {
        $buttons='';$panels='';foreach($this->modes as $mode=>$content){$active=$mode===$this->activeMode;$buttons.='<button type="button" role="tab" data-button-skip="navigation" aria-selected="'.($active?'true':'false').'" data-help-mode="'.htmlspecialchars($mode,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.htmlspecialchars($mode,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</button>';$body=is_array($content)?'<ol><li>'.implode('</li><li>',array_map(fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),$content)).'</li></ol>':'<p>'.htmlspecialchars((string)$content,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>';$panels.='<section role="tabpanel" data-help-panel="'.htmlspecialchars($mode,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"'.($active?'':' hidden').'>'.$body.'</section>';}
        return '<aside class="df5-help-panel" aria-label="Kontexthilfe"><h2>'.htmlspecialchars($this->title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h2><div class="df5-help-panel__tabs" role="tablist">'.$buttons.'</div>'.$panels.'</aside>';
    }
}
