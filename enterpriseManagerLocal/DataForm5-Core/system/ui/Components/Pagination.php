<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class Pagination implements ComponentInterface
{
    public function __construct(private int $page,private int $lastPage,private string $baseUrl){$this->page=max(1,$page);$this->lastPage=max(1,$lastPage);}
    public function render(): string
    {
        $html='<nav class="df5-pagination" aria-label="Seitennavigation"><ul>';
        for($i=1;$i<=$this->lastPage;$i++){$sep=str_contains($this->baseUrl,'?')?'&':'?';$href=$this->baseUrl.$sep.'page='.$i;$current=$i===$this->page?' aria-current="page"':'';$html.='<li><a href="'.htmlspecialchars($href,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"'.$current.'>'.$i.'</a></li>';}
        return $html.'</ul></nav>';
    }
}
