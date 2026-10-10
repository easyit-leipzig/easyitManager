<?php
declare(strict_types=1);
namespace DataForm5\UI\Components;
use DataForm5\UI\Contracts\ComponentInterface;
final class DataTable implements ComponentInterface
{
    /** @param array<string,string> $columns @param list<array<string,mixed>> $rows */
    public function __construct(private array $columns,private array $rows,private array $options=[]){ }
    public function render(): string
    {
        $caption=isset($this->options['caption'])?'<caption>'.htmlspecialchars((string)$this->options['caption'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</caption>':'';
        $head='';foreach($this->columns as $label){$head.='<th scope="col">'.htmlspecialchars((string)$label,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</th>';}
        $body='';foreach($this->rows as $row){$cells='';foreach(array_keys($this->columns) as $key){$value=$row[$key]??'';$cells.='<td>'.htmlspecialchars(is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</td>';}$body.='<tr>'.$cells.'</tr>';}
        if($body===''){$body='<tr><td colspan="'.max(1,count($this->columns)).'" class="df5-table__empty">Keine Einträge vorhanden.</td></tr>';}
        return '<div class="df5-table-wrap" role="region" aria-label="Datentabelle" tabindex="0"><table class="df5-table">'.$caption.'<thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>';
    }
}
