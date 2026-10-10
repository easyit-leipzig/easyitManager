<?php
declare(strict_types=1);
namespace DataForm5\Audit\Core;
final class AuditEntry implements \JsonSerializable
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly string $occurredAt,
        public readonly ?string $actorType=null,
        public readonly ?string $actorId=null,
        public readonly ?string $subjectType=null,
        public readonly ?string $subjectId=null,
        public readonly array $metadata=[],
        public readonly ?string $ipAddress=null,
        public readonly ?string $userAgent=null,
        public readonly string $previousHash='',
        public readonly string $hash=''
    ){}
    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string)$data['id'],(string)$data['event'],(string)$data['occurred_at'],$data['actor_type']??null,$data['actor_id']??null,$data['subject_type']??null,$data['subject_id']??null,is_array($data['metadata']??null)?$data['metadata']:[],$data['ip_address']??null,$data['user_agent']??null,(string)($data['previous_hash']??''),(string)($data['hash']??''));
    }
    public function withIntegrity(string $previousHash,string $hash): self
    {
        return new self($this->id,$this->event,$this->occurredAt,$this->actorType,$this->actorId,$this->subjectType,$this->subjectId,$this->metadata,$this->ipAddress,$this->userAgent,$previousHash,$hash);
    }
    /** @return array<string,mixed> */
    public function payload(): array
    {
        return ['id'=>$this->id,'event'=>$this->event,'occurred_at'=>$this->occurredAt,'actor_type'=>$this->actorType,'actor_id'=>$this->actorId,'subject_type'=>$this->subjectType,'subject_id'=>$this->subjectId,'metadata'=>$this->metadata,'ip_address'=>$this->ipAddress,'user_agent'=>$this->userAgent];
    }
    public function jsonSerialize(): array { return $this->payload()+['previous_hash'=>$this->previousHash,'hash'=>$this->hash]; }
}
