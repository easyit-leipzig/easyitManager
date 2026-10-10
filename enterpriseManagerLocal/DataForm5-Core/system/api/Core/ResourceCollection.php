<?php
declare(strict_types=1);
namespace DataForm5\Api\Core;
use DataForm5\Api\Contracts\ArrayableInterface;
final class ResourceCollection implements ArrayableInterface, \JsonSerializable
{
    private array $items=[]; private array $meta=[]; private array $links=[];
    public function __construct(iterable $items, private readonly ?string $resourceClass=null) { foreach($items as $item)$this->items[]=$item; }
    public function additional(array $meta=[], array $links=[]): self { $c=clone $this; $c->meta=$meta; $c->links=$links; return $c; }
    public function toArray(): array {
        $data=array_map(function(mixed $item):mixed {
            if ($this->resourceClass !== null) { $r=new $this->resourceClass($item); return $r->toArray(); }
            if ($item instanceof ArrayableInterface) return $item->toArray();
            if ($item instanceof \JsonSerializable) return $item->jsonSerialize();
            return is_object($item) && method_exists($item,'toArray') ? $item->toArray() : $item;
        },$this->items);
        $out=['data'=>$data]; if($this->meta!==[])$out['meta']=$this->meta; if($this->links!==[])$out['links']=$this->links; return $out;
    }
    public function jsonSerialize(): array { return $this->toArray(); }
}
