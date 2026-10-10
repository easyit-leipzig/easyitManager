<?php
declare(strict_types=1);

namespace DataForm5\ORM\Relations;

use DataForm\Database\Core\RelationManager;
use DataForm5\ORM\Contracts\ModelInterface;
use DataForm5\ORM\Core\ModelRepository;

final class BelongsToMany
{
    public function __construct(
        private readonly RelationManager $relations,
        private readonly ModelRepository $relatedRepository,
        private readonly ModelInterface $parent,
        private readonly string $parentTable,
        private readonly string $relatedTable
    ) {
    }

    /** @return list<ModelInterface> */
    public function get(): array
    {
        $id = $this->parent->getKey();
        if ($id === null) {
            return [];
        }
        $rows = $this->relations->related($this->parentTable, $id, $this->relatedTable);
        $models = [];
        foreach ($rows as $row) {
            $modelId = $row['id'] ?? null;
            if ($modelId !== null) {
                $model = $this->relatedRepository->find((int)$modelId);
                if ($model !== null) $models[] = $model;
            }
        }
        return $models;
    }

    public function attach(ModelInterface|int $related): void
    {
        $parentId = $this->parent->getKey();
        $relatedId = is_int($related) ? $related : $related->getKey();
        if ($parentId === null || $relatedId === null) {
            throw new \LogicException('Beide Modelle müssen gespeichert sein.');
        }
        $this->relations->attach($this->parentTable, $parentId, $this->relatedTable, $relatedId);
    }

    public function detach(ModelInterface|int|null $related = null): void
    {
        $parentId = $this->parent->getKey();
        if ($parentId === null) return;
        $relatedId = $related === null ? null : (is_int($related) ? $related : $related->getKey());
        $this->relations->detach($this->parentTable, $parentId, $this->relatedTable, $relatedId);
    }
}
