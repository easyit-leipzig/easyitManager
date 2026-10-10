<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Binding;

use DomainException;

final class BoundFormBindingService
{
    public function __construct(
        private readonly bool $requirePersistedParent = true,
        private readonly bool $enforceReadonlyOnServer = true,
    ) {
    }

    /**
     * Liefert den aktuellen Hauptwert.
     *
     * @param array<string,mixed> $parentRecord
     */
    public function getParentValue(
        BindingDefinition $binding,
        array $parentRecord
    ): mixed {
        return $parentRecord[$binding->parentField] ?? null;
    }

    /**
     * Darf ein neuer Kinddatensatz erzeugt werden?
     *
     * @param array<string,mixed> $parentRecord
     */
    public function canCreateChild(
        BindingDefinition $binding,
        array $parentRecord
    ): bool {
        if (!$this->requirePersistedParent) {
            return true;
        }

        $value = $this->getParentValue($binding, $parentRecord);

        return $value !== null && $value !== '';
    }

    /**
     * Initialisiert einen neuen Kinddatensatz.
     *
     * @param array<string,mixed> $parentRecord
     * @param array<string,mixed> $childDefaults
     * @return array<string,mixed>
     */
    public function applyNewRecordDefaults(
        BindingDefinition $binding,
        array $parentRecord,
        array $childDefaults = []
    ): array {
        $parentValue = $this->getParentValue(
            $binding,
            $parentRecord
        );

        if (
            $this->requirePersistedParent
            && ($parentValue === null || $parentValue === '')
        ) {
            throw new DomainException(
                'Hauptdatensatz zuerst speichern. '
                . 'Danach können gebundene Datensätze angelegt werden.'
            );
        }

        // Bei einer gebundenen DataForm ist dies immer die Initialbelegung.
        $childDefaults[$binding->childField] = $parentValue;

        return $childDefaults;
    }


    /**
     * Liefert den verbindlichen UI-Zustand des gebundenen Feldes
     * für eine neue Kindzeile.
     *
     * Der sichtbare Inhalt darf bei vorhandenem Parent niemals
     * "Eltern-Datensatz wählen" sein.
     *
     * @param array<string,mixed> $parentRecord
     * @return array{
     *   field:string,
     *   value:mixed,
     *   displayValue:string,
     *   readonly:bool,
     *   inherited:bool
     * }
     */
    public function buildBoundFieldState(
        BindingDefinition $binding,
        array $parentRecord
    ): array {
        $parentValue = $this->getParentValue(
            $binding,
            $parentRecord
        );

        if (
            $this->requirePersistedParent
            && ($parentValue === null || $parentValue === '')
        ) {
            throw new DomainException(
                'Hauptdatensatz zuerst speichern. '
                . 'Danach können gebundene Datensätze angelegt werden.'
            );
        }

        return [
            'field' => $binding->childField,
            'value' => $parentValue,
            'displayValue' => '#' . (string)$parentValue,
            'readonly' => $binding->boundFieldReadonly,
            'inherited' => true,
        ];
    }

    /**
     * Erzwingt die serverseitige Bindungsregel vor INSERT/UPDATE.
     *
     * Read-only = TRUE:
     *   Der Request-Wert wird durch den aktuellen Hauptwert ersetzt.
     *
     * Read-only = FALSE:
     *   Der Hauptwert ist nur die Initialbelegung. Ein absichtlich vom
     *   Benutzer geänderter Wert bleibt bestehen.
     *
     * @param array<string,mixed> $parentRecord
     * @param array<string,mixed> $submittedData
     * @param bool $isInsert
     * @return array<string,mixed>
     */
    public function enforceBeforePersist(
        BindingDefinition $binding,
        array $parentRecord,
        array $submittedData,
        bool $isInsert
    ): array {
        $parentValue = $this->getParentValue(
            $binding,
            $parentRecord
        );

        if (
            $this->requirePersistedParent
            && ($parentValue === null || $parentValue === '')
        ) {
            throw new DomainException(
                'Gebundener Datensatz kann ohne gespeicherten '
                . 'Hauptdatensatz nicht persistiert werden.'
            );
        }

        if (
            $binding->boundFieldReadonly
            && $this->enforceReadonlyOnServer
        ) {
            $submittedData[$binding->childField] = $parentValue;
            return $submittedData;
        }

        // Editierbare Bindung:
        // Bei INSERT wird nur dann vorbelegt, wenn kein expliziter Wert
        // übertragen wurde.
        if (
            $isInsert
            && !array_key_exists($binding->childField, $submittedData)
        ) {
            $submittedData[$binding->childField] = $parentValue;
        }

        return $submittedData;
    }

    /**
     * Filter für die aktuell gebundene Kindansicht.
     *
     * @param array<string,mixed> $parentRecord
     * @return array<string,mixed>
     */
    public function buildChildFilter(
        BindingDefinition $binding,
        array $parentRecord
    ): array {
        return [
            $binding->childField =>
                $this->getParentValue($binding, $parentRecord),
        ];
    }

    /**
     * Daten für dataformContext.relations.parent.
     *
     * @param array<string,mixed> $parentRecord
     * @return array<string,mixed>
     */
    public function buildContextParent(
        BindingDefinition $binding,
        array $parentRecord,
        ?string $parentRecordsetName = null
    ): array {
        return [
            'relationId' => $binding->relationId,
            'recordsetId' => $binding->parentRecordsetId,
            'recordsetName' => $parentRecordsetName,
            'keyField' => $binding->parentField,
            'keyValue' => $this->getParentValue(
                $binding,
                $parentRecord
            ),
            'childRecordsetId' => $binding->childRecordsetId,
            'childField' => $binding->childField,
            'binding' => [
                'inherited' => true,
                'readonly' => $binding->boundFieldReadonly,
            ],
        ];
    }
}
