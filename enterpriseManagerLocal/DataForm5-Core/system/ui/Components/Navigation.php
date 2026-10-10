<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class Navigation implements ComponentInterface
{
    /** @param list<array{label:string,href:string,key?:string}> $items */
    public function __construct(private array $items,private ?string $active=null){ }
    public function render(): string
    {
        $html='<nav class="df5-nav" aria-label="Hauptnavigation"><ul>';
        foreach($this->items as $item){$key=(string)($item['key']??$item['href']);$current=$this->active===$key?' aria-current="page"':'';$html.='<li><a href="'.htmlspecialchars($item['href'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"'.$current.'>'.htmlspecialchars($item['label'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</a></li>';}
        return $html.'</ul></nav>';
    }
}
