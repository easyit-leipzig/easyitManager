<?php
declare(strict_types=1);

namespace DataForm5\Replication\Core;

final class ReplicationEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $channel,
        public readonly string $type,
        public readonly string $sourceNode,
        public readonly string $createdAt,
        public readonly array $payload,
        public readonly string $checksum,
        public readonly array $security = []
    ) {}

    public static function create(string $channel,string $type,string $sourceNode,array $payload): self
    {
        $id=bin2hex(random_bytes(16));
        $created=date(DATE_ATOM);
        $canonical=json_encode([
            'id'=>$id,'channel'=>$channel,'type'=>$type,'source_node'=>$sourceNode,
            'created_at'=>$created,'payload'=>$payload
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return new self($id,$channel,$type,$sourceNode,$created,$payload,hash('sha256',$canonical),[]);
    }

    public function toArray(): array
    {
        return [
            'id'=>$this->id,'channel'=>$this->channel,'type'=>$this->type,
            'source_node'=>$this->sourceNode,'created_at'=>$this->createdAt,
            'payload'=>$this->payload,'checksum'=>$this->checksum,'security'=>$this->security,
        ];
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (string)($row['id']??''),(string)($row['channel']??''),
            (string)($row['type']??''),(string)($row['source_node']??''),
            (string)($row['created_at']??''),is_array($row['payload']??null)?$row['payload']:[],
            (string)($row['checksum']??''),is_array($row['security']??null)?$row['security']:[]
        );
    }

    public function withSecurity(array $security): self
    {
        return new self($this->id,$this->channel,$this->type,$this->sourceNode,$this->createdAt,$this->payload,$this->checksum,$security);
    }

    public function signingPayload(): array
    {
        return [
            'id'=>$this->id,'channel'=>$this->channel,'type'=>$this->type,
            'source_node'=>$this->sourceNode,'created_at'=>$this->createdAt,
            'payload'=>$this->payload,'checksum'=>$this->checksum,
        ];
    }

    public function verify(): bool
    {
        $canonical=json_encode([
            'id'=>$this->id,'channel'=>$this->channel,'type'=>$this->type,
            'source_node'=>$this->sourceNode,'created_at'=>$this->createdAt,'payload'=>$this->payload
        ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        return hash_equals($this->checksum,hash('sha256',$canonical));
    }
}
