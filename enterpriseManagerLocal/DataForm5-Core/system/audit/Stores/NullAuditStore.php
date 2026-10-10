<?php
declare(strict_types=1);
namespace DataForm5\Audit\Stores;
use DataForm5\Audit\Contracts\AuditStoreInterface;use DataForm5\Audit\Core\{AuditEntry,AuditQuery};
final class NullAuditStore implements AuditStoreInterface
{
 public function append(AuditEntry $entry):AuditEntry{return $entry;}
 public function search(?AuditQuery $query=null):array{return [];}
 public function verify():bool{return true;}
}
