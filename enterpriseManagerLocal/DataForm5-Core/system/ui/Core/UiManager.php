<?php
declare(strict_types=1);
namespace DataForm5\UI\Core;
use DataForm5\UI\Components\{Alert,Badge,Button,Card,DataTable,Dialog,HelpPanel,Navigation,Pagination,Status};
final class UiManager
{
    public function button(string $label,string $href='#',string $variant='primary',array $attributes=[]): Button{return new Button($label,$href,$variant,$attributes);}
    public function alert(string $message,string $type='info',?string $title=null): Alert{return new Alert($message,$type,$title);}
    public function badge(string $label,string $variant='neutral'): Badge{return new Badge($label,$variant);}
    public function card(string $title,string $content,?string $footer=null): Card{return new Card($title,$content,$footer);}
    public function table(array $columns,array $rows,array $options=[]): DataTable{return new DataTable($columns,$rows,$options);}
    public function dialog(string $id,string $title,string $content,array $options=[]): Dialog{return new Dialog($id,$title,$content,$options);}
    public function navigation(array $items,?string $active=null): Navigation{return new Navigation($items,$active);}
    public function pagination(int $page,int $lastPage,string $baseUrl): Pagination{return new Pagination($page,$lastPage,$baseUrl);}
    public function status(string $label,string $state): Status{return new Status($label,$state);}
    public function helpPanel(string $title,array $modes,string $activeMode='short'): HelpPanel{return new HelpPanel($title,$modes,$activeMode);}
}
