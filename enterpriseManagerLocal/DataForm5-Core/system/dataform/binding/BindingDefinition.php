<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Binding;

use InvalidArgumentException;

final class BindingDefinition
{
    public function __construct(
        public readonly string $parentRecordsetId,
        public readonly string $parentField,
        public readonly string $childRecordsetId,
        public readonly string $childField,
        public readonly bool $inheritParentValue = true,
        public readonly bool $boundFieldReadonly = true,
        public readonly ?string $relationId = null,
    ) {
        foreach ([
            'parentRecordsetId' => $parentRecordsetId,
            'parentField' => $parentField,
            'childRecordsetId' => $childRecordsetId,
            'childField' => $childField,
        ] as $name => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    $name . ' darf nicht leer sein.'
                );
            }
        }

        if ($inheritParentValue !== true) {
            throw new InvalidArgumentException(
                'Eine gebundene DataForm muss den Hauptwert übernehmen.'
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'relationId' => $this->relationId,
            'parentRecordsetId' => $this->parentRecordsetId,
            'parentField' => $this->parentField,
            'childRecordsetId' => $this->childRecordsetId,
            'childField' => $this->childField,
            'inheritParentValue' => $this->inheritParentValue,
            'boundFieldReadonly' => $this->boundFieldReadonly,
        ];
    }
}
