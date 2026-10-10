<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

use EasyIT\Assistant\AssistantContext;

final class AssistantLauncherRenderer
{
    public function __construct(private AssistantIntegrationRegistry $registry) {}

    public function render(string $surface, AssistantContext $context, string $baseUrl = '/admin/assistants/run.php'): string
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $title = $this->registry->getSurfaceTitle($surface);
        $entries = $this->registry->entries($surface, $context, $baseUrl);

        $html = '<nav class="eit-assistant-launcher" data-eit-assistant-surface="' . $e($surface) . '" aria-label="Assistenten für ' . $e($title) . '">';
        $html .= '<span class="eit-assistant-launcher-title">Assistenten</span><ul>';
        foreach ($entries as $entry) {
            $html .= '<li>';
            if ($entry['available']) {
                $html .= '<a href="' . $e($entry['url']) . '" data-button-key="' . $e($entry['buttonKey']) . '" title="' . $e($entry['linkTitle']) . '" aria-label="' . $e($entry['ariaLabel']) . '">' . $e($entry['title']) . '</a>';
            } else {
                $html .= '<span class="eit-assistant-launcher-disabled" aria-disabled="true" title="Fehlender Kontext: ' . $e(implode(', ', $entry['missing'])) . '">' . $e($entry['title']) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul></nav>';
        return $html;
    }
}
