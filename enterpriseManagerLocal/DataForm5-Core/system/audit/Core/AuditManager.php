<?php
declare(strict_types=1);
namespace DataForm5\Audit\Core;
use DataForm5\Audit\Contracts\AuditStoreInterface;
final class AuditManager
{
    public function __construct(private readonly AuditStoreInterface $store){}
    /** @param array<string,mixed> $metadata */
    public function record(string $event,array $metadata=[],?string $actorType=null,?string $actorId=null,?string $subjectType=null,?string $subjectId=null,?string $ipAddress=null,?string $userAgent=null): AuditEntry
    {
        $entry=new AuditEntry(bin2hex(random_bytes(16)),$event,(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format(DATE_ATOM),$actorType,$actorId,$subjectType,$subjectId,$metadata,$ipAddress,$userAgent);
        return $this->store->append($entry);
    }
    /** @return list<AuditEntry> */
    public function search(?AuditQuery $query=null):array{return $this->store->search($query);}
    public function verify():bool{return $this->store->verify();}
}
