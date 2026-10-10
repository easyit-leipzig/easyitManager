<?php
declare(strict_types=1);
namespace DataForm5\Modules\Migrations;

use PDO;

final class ModuleMigrationRepository
{
    public function __construct(private readonly PDO $pdo) { $this->ensureSchema(); }

    public function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS enterprise_module_migrations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            module_name VARCHAR(190) NOT NULL,
            migration_version VARCHAR(190) NOT NULL,
            checksum CHAR(64) NOT NULL,
            batch INT UNSIGNED NOT NULL DEFAULT 1,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_module_migration (module_name,migration_version),
            KEY idx_module_migration_batch (module_name,batch)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<string,array<string,mixed>> */
    public function applied(string $module): array
    {
        $stmt=$this->pdo->prepare('SELECT migration_version,checksum,batch,applied_at FROM enterprise_module_migrations WHERE module_name=? ORDER BY migration_version');
        $stmt->execute([$module]); $rows=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(string)$row['migration_version']]=$row;
        return $rows;
    }

    public function nextBatch(string $module): int
    {
        $stmt=$this->pdo->prepare('SELECT COALESCE(MAX(batch),0)+1 FROM enterprise_module_migrations WHERE module_name=?');
        $stmt->execute([$module]); return max(1,(int)$stmt->fetchColumn());
    }

    public function record(string $module,string $version,string $checksum,int $batch): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO enterprise_module_migrations(module_name,migration_version,checksum,batch) VALUES (?,?,?,?)');
        $stmt->execute([$module,$version,$checksum,$batch]);
    }

    public function forget(string $module,string $version): void
    {
        $stmt=$this->pdo->prepare('DELETE FROM enterprise_module_migrations WHERE module_name=? AND migration_version=?');
        $stmt->execute([$module,$version]);
    }
}
