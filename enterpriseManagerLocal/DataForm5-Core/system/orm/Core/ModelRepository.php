<?php
declare(strict_types=1);

namespace DataForm5\ORM\Core;

use DataForm\Database\Contracts\DatabaseInterface;
use DataForm5\ORM\Contracts\ModelInterface;
use DataForm5\ORM\Exceptions\OrmException;

final class ModelRepository
{
    /** @param class-string<ModelInterface> $modelClass */
    public function __construct(
        private readonly DatabaseInterface $database,
        private readonly string $modelClass
    ) {
        if (!is_a($modelClass, ModelInterface::class, true)) {
            throw new OrmException("{$modelClass} implementiert ModelInterface nicht.");
        }
    }

    /** @return list<ModelInterface> */
    public function all(): array
    {
        return array_map(fn(array $row): ModelInterface => $this->hydrate($row), $this->database->all($this->table()));
    }

    public function find(int $id): ?ModelInterface
    {
        $row = $this->database->find($this->table(), $id);
        return $row === null ? null : $this->hydrate($row);
    }

    public function findOrFail(int $id): ModelInterface
    {
        return $this->find($id) ?? throw new OrmException("Datensatz {$this->table()}#{$id} wurde nicht gefunden.");
    }

    /** @return list<ModelInterface> */
    public function where(array $criteria): array
    {
        return array_map(fn(array $row): ModelInterface => $this->hydrate($row), $this->database->where($this->table(), $criteria));
    }

    public function firstWhere(array $criteria): ?ModelInterface
    {
        return $this->where($criteria)[0] ?? null;
    }

    public function create(array $attributes): ModelInterface
    {
        $model = new $this->modelClass($attributes);
        return $this->save($model);
    }

    public function save(ModelInterface $model): ModelInterface
    {
        if (!$model instanceof $this->modelClass) {
            throw new OrmException('Das Modell gehört nicht zu diesem Repository.');
        }
        $data = $model->attributes();
        unset($data[$model::primaryKey()]);

        if ($model->exists()) {
            $id = $model->getKey();
            if ($id === null || !$this->database->update($this->table(), $id, $data)) {
                throw new OrmException('Modell konnte nicht aktualisiert werden.');
            }
            return $this->findOrFail($id);
        }

        $id = $this->database->insert($this->table(), $data);
        return $this->findOrFail($id);
    }

    public function delete(ModelInterface|int $model): bool
    {
        $id = is_int($model) ? $model : $model->getKey();
        return $id !== null && $this->database->delete($this->table(), $id);
    }

    /** @return list<ModelInterface> */
    public function hasMany(ModelInterface $parent, string $foreignKey): array
    {
        $id = $parent->getKey();
        return $id === null ? [] : $this->where([$foreignKey => $id]);
    }

    public function belongsTo(ModelInterface $child, string $foreignKey): ?ModelInterface
    {
        $id = $child->attributes()[$foreignKey] ?? null;
        return $id === null ? null : $this->find((int)$id);
    }

    private function table(): string
    {
        return $this->modelClass::table();
    }

    private function hydrate(array $row): ModelInterface
    {
        return new $this->modelClass($row, true);
    }
}
