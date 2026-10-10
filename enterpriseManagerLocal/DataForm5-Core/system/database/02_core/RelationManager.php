<?php
declare(strict_types=1);

namespace DataForm\Database\Core;

use DataForm\Database\Contracts\RelationalDatabaseInterface;

final class RelationManager
{
    public function __construct(private readonly RelationalDatabaseInterface $database) {}
    public function createOneToMany(string $parent, string $child, string $foreignKey, string $onDelete = 'RESTRICT'): void { $this->database->createOneToManyRelation($parent, $child, $foreignKey, $onDelete); }
    public function createManyToMany(string $left, string $right, ?string $pivot = null, ?string $leftKey = null, ?string $rightKey = null, string $onDelete = 'CASCADE'): void { $this->database->createManyToManyRelation($left, $right, $pivot, $leftKey, $rightKey, $onDelete); }
    /** @deprecated Verwenden Sie createOneToMany(). */
    public function oneToMany(string $parent, string $child, string $foreignKey, string $onDelete = 'RESTRICT'): void
    {
        $this->createOneToMany($parent, $child, $foreignKey, $onDelete);
    }

    /** @deprecated Verwenden Sie createManyToMany(). */
    public function manyToMany(string $left, string $right, ?string $pivot = null, ?string $leftKey = null, ?string $rightKey = null, string $onDelete = 'CASCADE'): void
    {
        $this->createManyToMany($left, $right, $pivot, $leftKey, $rightKey, $onDelete);
    }

    public function attach(string $left, int $leftId, string $right, int $rightId, ?string $pivot = null): int { return $this->database->attach($left, $leftId, $right, $rightId, $pivot); }
    public function detach(string $left, int $leftId, string $right, int $rightId, ?string $pivot = null): bool { return $this->database->detach($left, $leftId, $right, $rightId, $pivot); }
    public function sync(string $left, int $leftId, string $right, array $rightIds, ?string $pivot = null): void { $this->database->sync($left, $leftId, $right, $rightIds, $pivot); }
    public function related(string $source, int $sourceId, string $target, ?string $pivot = null): array { return $this->database->related($source, $sourceId, $target, $pivot); }
}
