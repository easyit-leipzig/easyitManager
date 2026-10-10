<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantRegistry;

final class AssistantIntegrationRegistry
{
    private AssistantCatalog $catalog;

    public function __construct(private AssistantRegistry $assistantRegistry)
    {
        $this->catalog = new AssistantCatalog($assistantRegistry);
    }

    public function hasSurface(string $surface): bool { return $this->catalog->hasSurface($surface); }
    /** @return list<string> */
    public function surfaceIds(): array { return $this->catalog->surfaceIds(); }
    public function getSurfaceTitle(string $surface): string { return $this->catalog->surfaceTitle($surface); }
    public function getCatalog(): AssistantCatalog { return $this->catalog; }

    /** @return list<array{id:string,title:string,description:string,url:string,requires:array{project:bool,dataForm:bool},available:bool,missing:list<string>,buttonKey:string,linkTitle:string,ariaLabel:string}> */
    public function entries(string $surface, AssistantContext $context, string $baseUrl = '/admin/assistants/run.php'): array
    {
        if (!$this->hasSurface($surface)) {
            throw new \InvalidArgumentException('Unbekannte Assistenten-Oberfläche: ' . $surface);
        }
        $ui = new AssistantUiActionRegistry();
        $open = $ui->get('open');
        $entries = [];
        foreach ($this->catalog->assistantIdsForSurface($surface) as $assistantId) {
            $assistant = $this->assistantRegistry->get($assistantId);
            $requirements = $this->catalog->requirements($assistantId);
            $missing = [];
            if ($requirements['project'] && !$context->getProjectId()) { $missing[] = 'Projekt'; }
            if ($requirements['dataForm'] && !$context->getDataFormId()) { $missing[] = 'DataForm'; }
            $entries[] = [
                'id' => $assistantId,
                'title' => $assistant->getTitle(),
                'description' => $assistant->getDescription(),
                'url' => AssistantUrlBuilder::runUrl($assistantId, $context, $baseUrl, $surface),
                'requires' => $requirements,
                'available' => $missing === [],
                'missing' => $missing,
                'buttonKey' => $open['buttonKey'],
                'linkTitle' => $open['title'] . ': ' . $assistant->getTitle(),
                'ariaLabel' => $open['ariaLabel'] . ': ' . $assistant->getTitle(),
            ];
        }
        return $entries;
    }
}
