<?php
declare(strict_types=1);
namespace DataForm5\Recovery\Contracts;
interface BackupStoreInterface
{
    public function create(string $source, string $name, array $metadata = []): array;
    public function verify(string $backupId): bool;
    public function restore(string $backupId, string $target): void;
    public function list(): array;
    public function delete(string $backupId): void;
}
