<?php
declare(strict_types=1);
namespace DataForm5\Audit\Core;
final class AuditQuery
{
    public function __construct(
        public readonly ?string $event=null,
        public readonly ?string $actorId=null,
        public readonly ?string $subjectType=null,
        public readonly ?string $subjectId=null,
        public readonly ?string $from=null,
        public readonly ?string $until=null,
        public readonly ?int $limit=null
    ){}
    public function matches(AuditEntry $entry): bool
    {
        if($this->event!==null && $entry->event!==$this->event)return false;
        if($this->actorId!==null && $entry->actorId!==$this->actorId)return false;
        if($this->subjectType!==null && $entry->subjectType!==$this->subjectType)return false;
        if($this->subjectId!==null && $entry->subjectId!==$this->subjectId)return false;
        if($this->from!==null && $entry->occurredAt<$this->from)return false;
        if($this->until!==null && $entry->occurredAt>$this->until)return false;
        return true;
    }
}
