<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;use DataForm5\UI\Core\Attributes;
final class Dialog implements ComponentInterface
{
    public function __construct(private string $id,private string $title,private string $content,private array $options=[]){ }
    public function render(): string
    {
        $id=preg_replace('/[^a-zA-Z0-9_-]/','',$this->id) ?: 'dialog';
        $open=($this->options['open']??false)?true:null;
        $closeLabel=htmlspecialchars((string)($this->options['close_label']??'Schließen'),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        return '<dialog'.Attributes::render(['id'=>$id,'class'=>'df5-dialog','open'=>$open,'aria-labelledby'=>$id.'-title']).'><header class="df5-dialog__header"><h2 id="'.$id.'-title">'.htmlspecialchars($this->title,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h2><button type="button" class="df5-dialog__close" data-button="schliessen" data-dialog-close="'.$id.'" aria-label="'.$closeLabel.'">×</button></header><div class="df5-dialog__body">'.$this->content.'</div></dialog>';
    }
}
