<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

use EasyIT\Assistant\AssistantContext;

final class AssistantPageNavigationRenderer
{
    public function __construct(private AssistantCatalog $catalog, private AssistantUiActionRegistry $actions) {}

    public function render(AssistantContext $context, ?string $assistantId = null, bool $showHistory = false, bool $showWorkflow = false): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $query = [];
        if ($context->getProjectId()) { $query['project_id'] = $context->getProjectId(); }
        if ($context->getDataFormId()) { $query['dataform_id'] = $context->getDataFormId(); }
        if ($context->getRecordId()) { $query['record_id'] = $context->getRecordId(); }
        $surface = (string) $context->input('surface', '');
        if ($surface !== '') { $query['surface'] = $surface; }

        $html = '<nav class="eit-assistant-toolbar" aria-label="Assistenten-Navigation"><ul>';
        $center = $this->actions->get('center');
        $html .= '<li><a href="index.php?' . $e(http_build_query($query)) . '" data-button-key="' . $e($center['buttonKey']) . '" title="' . $e($center['title']) . '" aria-label="' . $e($center['ariaLabel']) . '">Assistentenzentrale</a></li>';
        if ($surface !== '' && $this->catalog->hasSurface($surface)) {
            $ctx = $this->actions->get('contextual');
            $q = array_merge(['surface' => $surface], $query);
            $html .= '<li><a href="contextual.php?' . $e(http_build_query($q)) . '" data-button-key="' . $e($ctx['buttonKey']) . '" title="' . $e($ctx['title']) . '" aria-label="' . $e($ctx['ariaLabel']) . '">Kontext-Assistenten</a></li>';
        }
        if ($showHistory && $assistantId && $context->getProjectId() && $this->catalog->hasHistory($assistantId)) {
            $history = $this->actions->get('history');
            $q = ['assistant' => $assistantId, 'project_id' => $context->getProjectId()];
            if ($context->getDataFormId()) { $q['dataform_id'] = $context->getDataFormId(); }
            $html .= '<li><a href="history.php?' . $e(http_build_query($q)) . '" data-button-key="' . $e($history['buttonKey']) . '" title="' . $e($history['title']) . '" aria-label="' . $e($history['ariaLabel']) . '">Verlauf / Undo / Redo</a></li>';
        }
        if ($showWorkflow && $assistantId !== 'workflow.standard' && (string) $context->input('workflow', '') === 'standard') {
            $workflow = $this->actions->get('workflow');
            $q = ['assistant' => 'workflow.standard'];
            if ($context->getProjectId()) { $q['project_id'] = $context->getProjectId(); }
            if ($context->getDataFormId()) { $q['dataform_id'] = $context->getDataFormId(); }
            $html .= '<li><a href="run.php?' . $e(http_build_query($q)) . '" data-button-key="' . $e($workflow['buttonKey']) . '" title="' . $e($workflow['title']) . '" aria-label="' . $e($workflow['ariaLabel']) . '">Workflow-Fortschritt</a></li>';
        }
        $html .= '</ul></nav>';
        return $html;
    }
}
