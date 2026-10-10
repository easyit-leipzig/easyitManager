<?php
declare(strict_types=1);
namespace DataForm5\Recovery\Core;
use DataForm5\Recovery\Contracts\BackupStoreInterface;
final class RecoveryManager
{
    public function __construct(private readonly BackupStoreInterface $store,private readonly int $retain=10){}
    public function backup(string $source,string $name,array $metadata=[]): array {$manifest=$this->store->create($source,$name,$metadata);$this->prune();return $manifest;}
    public function restore(string $backupId,string $target): void {$this->store->restore($backupId,$target);}
    public function verify(string $backupId): bool {return $this->store->verify($backupId);}
    public function backups(): array {return $this->store->list();}
    public function prune(): int {$items=$this->store->list();$removed=0;foreach(array_slice($items,max(0,$this->retain)) as $item){$this->store->delete((string)$item['id']);$removed++;}return $removed;}
}
