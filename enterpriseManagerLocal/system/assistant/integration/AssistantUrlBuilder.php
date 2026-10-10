<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

use EasyIT\Assistant\AssistantContext;

final class AssistantUrlBuilder
{
    public static function runUrl(string $assistantId, AssistantContext $context, string $baseUrl = '/admin/assistants/run.php', ?string $surface = null): string
    {
        $query = ['assistant' => $assistantId];
        if ($context->getProjectId()) { $query['project_id'] = $context->getProjectId(); }
        if ($context->getDataFormId()) { $query['dataform_id'] = $context->getDataFormId(); }
        if ($context->getRecordId()) { $query['record_id'] = $context->getRecordId(); }
        if ($surface) { $query['surface'] = $surface; }
        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
    }

    public static function contextualUrl(string $surface, AssistantContext $context, string $baseUrl = '/admin/assistants/contextual.php'): string
    {
        $query = ['surface' => $surface];
        if ($context->getProjectId()) { $query['project_id'] = $context->getProjectId(); }
        if ($context->getDataFormId()) { $query['dataform_id'] = $context->getDataFormId(); }
        if ($context->getRecordId()) { $query['record_id'] = $context->getRecordId(); }
        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . http_build_query($query);
    }
}
