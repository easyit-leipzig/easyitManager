<?php
declare(strict_types=1);

namespace EasyIT\Assistant\State;

final class AssistantScopeResolver
{
    public static function resolve(string $assistantId, string $projectId, ?string $dataFormId = null): string
    {
        $dataFormId = trim((string) $dataFormId);
        return match ($assistantId) {
            'dataform.fields', 'dataform.relations', 'dataform.events', 'dataform.actions' => $projectId . '|' . ($dataFormId !== '' ? $dataFormId : 'dataform'),
            'workflow.standard' => 'workflow.standard',
            default => $projectId,
        };
    }

    public static function requiresDataForm(string $assistantId): bool
    {
        return in_array($assistantId, ['dataform.fields', 'dataform.relations', 'dataform.events', 'dataform.actions'], true);
    }
}
