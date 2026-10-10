<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Relation;

final class BoundValueResolver
{
    /**
     * Erzeugt die Defaultwerte eines neuen gebundenen Kinddatensatzes.
     * Der Wert kommt immer aus dem aktuell angezeigten Eltern-Datensatz.
     *
     * @return array{values:array<string,mixed>,readOnlyFields:list<string>}
     */
    public function resolve(array $relationConfig, array $currentParentRecord): array
    {
        if (($relationConfig['type'] ?? '') !== 'one_to_many' || empty($relationConfig['binding']['enabled'])) {
            return ['values' => [], 'readOnlyFields' => []];
        }

        $sourceField = trim((string) ($relationConfig['binding']['parentValueField'] ?? 'id'));
        $targetField = trim((string) ($relationConfig['binding']['childTargetField'] ?? ''));
        if ($sourceField === '' || $targetField === '') {
            throw new \InvalidArgumentException('Ungültige Bindung: Quell- oder Zielfeld fehlt.');
        }
        if (!array_key_exists($sourceField, $currentParentRecord)) {
            throw new \OutOfBoundsException('Der aktuelle Eltern-Datensatz enthält das Schlüsselfeld "' . $sourceField . '" nicht.');
        }

        $readOnlyFields = !empty($relationConfig['binding']['readOnly']) ? [$targetField] : [];
        return [
            'values' => [$targetField => $currentParentRecord[$sourceField]],
            'readOnlyFields' => $readOnlyFields,
        ];
    }
}
