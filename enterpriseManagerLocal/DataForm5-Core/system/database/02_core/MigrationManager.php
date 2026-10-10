<?php
declare(strict_types=1);

namespace DataForm\Database\Core;

final class MigrationManager
{
    public function __construct(private readonly DatabaseManager $databases) {}
    public function run(callable $migration, string $connection = 'default'): void
    {
        $db = $this->databases->connection($connection);
        $db->beginTransaction();
        try { $migration($db); $db->commit(); }
        catch (\Throwable $e) { $db->rollBack(); throw $e; }
    }
}
