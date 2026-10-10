<?php
declare(strict_types=1);

namespace DataForm5\Cluster\Core;

final class ClusterRegistry
{
    public function __construct(private readonly string $file) {}

    /** @return array<string,ClusterNode> */
    public function all(): array
    {
        if(!is_file($this->file)) return [];
        $data=json_decode((string)@file_get_contents($this->file),true);
        if(!is_array($data)) return [];
        $nodes=[];
        foreach($data as $id=>$row){
            if(!is_array($row)) continue;
            $node=ClusterNode::fromArray($row);
            if($node->id!=='') $nodes[$node->id]=$node;
        }
        ksort($nodes);
        return $nodes;
    }

    public function upsert(ClusterNode $node): void
    {
        $nodes=$this->all();
        $nodes[$node->id]=$node;
        $payload=[];
        foreach($nodes as $id=>$item) $payload[$id]=$item->toArray();
        $this->write($payload);
    }

    public function remove(string $nodeId): bool
    {
        $nodes=$this->all();
        if(!isset($nodes[$nodeId])) return false;
        unset($nodes[$nodeId]);
        $payload=[];
        foreach($nodes as $id=>$item) $payload[$id]=$item->toArray();
        $this->write($payload);
        return true;
    }

    private function write(array $data): void
    {
        $dir=dirname($this->file);
        if(!is_dir($dir) && !@mkdir($dir,0775,true) && !is_dir($dir)){
            throw new \RuntimeException("Cluster-Registry kann nicht angelegt werden: {$dir}");
        }
        $tmp=$this->file.'.tmp.'.bin2hex(random_bytes(4));
        $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json,LOCK_EX)===false || !@rename($tmp,$this->file)){
            @unlink($tmp);
            throw new \RuntimeException('Cluster-Registry konnte nicht gespeichert werden.');
        }
    }
}
