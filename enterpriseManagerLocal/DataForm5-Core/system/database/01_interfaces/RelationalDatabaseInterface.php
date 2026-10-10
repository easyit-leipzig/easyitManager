<?php
declare(strict_types=1);

namespace DataForm\Database\Contracts;

interface RelationalDatabaseInterface extends DatabaseInterface
{
    public function createOneToManyRelation(
        string $parentTable,
        string $childTable,
        string $foreignKey,
        string $onDelete = 'RESTRICT'
    ): void;

    public function createManyToManyRelation(
        string $leftTable,
        string $rightTable,
        ?string $pivotTable = null,
        ?string $leftKey = null,
        ?string $rightKey = null,
        string $onDelete = 'CASCADE'
    ): void;

    public function attach(string $leftTable, int $leftId, string $rightTable, int $rightId, ?string $pivotTable = null): int;
    public function detach(string $leftTable, int $leftId, string $rightTable, int $rightId, ?string $pivotTable = null): bool;
    public function sync(string $leftTable, int $leftId, string $rightTable, array $rightIds, ?string $pivotTable = null): void;
    public function related(string $sourceTable, int $sourceId, string $targetTable, ?string $pivotTable = null): array;
}
