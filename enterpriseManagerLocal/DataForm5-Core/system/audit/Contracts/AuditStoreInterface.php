<?php
declare(strict_types=1);
namespace DataForm5\Audit\Contracts;
use DataForm5\Audit\Core\AuditEntry;
use DataForm5\Audit\Core\AuditQuery;
interface AuditStoreInterface
{
    public function append(AuditEntry $entry): AuditEntry;
    /** @return list<AuditEntry> */
    public function search(?AuditQuery $query = null): array;
    public function verify(): bool;
}
