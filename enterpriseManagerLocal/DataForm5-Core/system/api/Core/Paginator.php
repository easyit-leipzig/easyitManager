<?php
declare(strict_types=1);
namespace DataForm5\Api\Core;
final class Paginator implements \JsonSerializable
{
    public function __construct(private readonly array $items, private readonly int $total, private readonly int $page=1, private readonly int $perPage=20, private readonly string $baseUrl='') { if($page<1||$perPage<1) throw new \InvalidArgumentException('page und perPage müssen größer als 0 sein.'); }
    public static function fromArray(array $all,int $page=1,int $perPage=20,string $baseUrl=''):self { $offset=($page-1)*$perPage; return new self(array_slice($all,$offset,$perPage),count($all),$page,$perPage,$baseUrl); }
    public function items():array{return $this->items;} public function total():int{return $this->total;} public function lastPage():int{return max(1,(int)ceil($this->total/$this->perPage));}
    public function meta():array{return ['current_page'=>$this->page,'per_page'=>$this->perPage,'total'=>$this->total,'last_page'=>$this->lastPage(),'from'=>$this->total===0?null:(($this->page-1)*$this->perPage)+1,'to'=>$this->total===0?null:min($this->page*$this->perPage,$this->total)];}
    public function links():array { $url=fn(int $p)=>$this->baseUrl===''?null:$this->baseUrl.(str_contains($this->baseUrl,'?')?'&':'?').'page='.$p; return ['first'=>$url(1),'last'=>$url($this->lastPage()),'prev'=>$this->page>1?$url($this->page-1):null,'next'=>$this->page<$this->lastPage()?$url($this->page+1):null]; }
    public function jsonSerialize():array{return ['data'=>$this->items,'meta'=>$this->meta(),'links'=>$this->links()];}
}
