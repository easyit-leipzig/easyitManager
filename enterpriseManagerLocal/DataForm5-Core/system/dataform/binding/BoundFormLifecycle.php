<?php
declare(strict_types=1);

namespace EasyIT\DataForm5\Binding;

/**
 * Dünne Integrationsschicht für DataForm-/Recordset-Runtime.
 */
final class BoundFormLifecycle
{
    public function __construct(
        private readonly BoundFormBindingService $service
    ) {
    }

    /**
     * @param array<string,mixed> $parentRecord
     * @param array<string,mixed> $childDefaults
     * @return array{values:array<string,mixed>,boundField:array<string,mixed>}
     */
    public function onNewChildRecord(
        BindingDefinition $binding,
        array $parentRecord,
        array $childDefaults
    ): array {
        return [
            'values' => $this->service->applyNewRecordDefaults(
                $binding,
                $parentRecord,
                $childDefaults
            ),
            'boundField' => $this->service->buildBoundFieldState(
                $binding,
                $parentRecord
            ),
        ];
    }

    /**
     * @param array<string,mixed> $parentRecord
     * @param array<string,mixed> $submitted
     * @return array<string,mixed>
     */
    public function beforeChildPersist(
        BindingDefinition $binding,
        array $parentRecord,
        array $submitted,
        bool $isInsert
    ): array {
        return $this->service->enforceBeforePersist(
            $binding,
            $parentRecord,
            $submitted,
            $isInsert
        );
    }

    /**
     * @param array<string,mixed> $parentRecord
     * @return array<string,mixed>
     */
    public function onParentChanged(
        BindingDefinition $binding,
        array $parentRecord
    ): array {
        return [
            'filter' =>
                $this->service->buildChildFilter(
                    $binding,
                    $parentRecord
                ),
            'newRecordDefaults' =>
                $this->service->applyNewRecordDefaults(
                    $binding,
                    $parentRecord
                ),
            'canCreateChild' =>
                $this->service->canCreateChild(
                    $binding,
                    $parentRecord
                ),
        ];
    }
}
