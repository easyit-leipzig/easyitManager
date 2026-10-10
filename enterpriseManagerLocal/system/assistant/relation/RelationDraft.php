<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Relation;

final class RelationDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
    }

    public static function defaults(): array
    {
        return [
            'relation' => [
                'name' => '',
                'type' => 'one_to_many',
            ],
            'parent' => [
                'dataForm' => '',
                'source' => '',
                'keyField' => 'id',
            ],
            'child' => [
                'dataForm' => '',
                'source' => '',
                'foreignKeyField' => '',
            ],
            'binding' => [
                'enabled' => true,
                'valueSource' => 'parent.currentRecord',
                'parentValueField' => 'id',
                'childTargetField' => '',
                'fillOnNewRecord' => true,
                'readOnly' => true,
            ],
            'manyToMany' => [
                'junctionSource' => '',
                'parentForeignKeyField' => '',
                'childForeignKeyField' => '',
                'childKeyField' => 'id',
            ],
            'display' => [
                'paginationPosition' => 'below-records',
            ],
        ];
    }

    public function merge(array $changes): self
    {
        return new self(array_replace_recursive($this->data, $changes));
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }
}
