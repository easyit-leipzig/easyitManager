<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Integration;

use EasyIT\Assistant\AssistantRegistry;

final class AssistantCatalog
{
    /** @var array<string,array{title:string,description:string,ids:list<string>}> */
    private array $categories = [
        'workflow' => [
            'title' => 'Workflow und Einstieg',
            'description' => 'Geführte Abläufe und Systemstatus.',
            'ids' => ['workflow.standard', 'core.status'],
        ],
        'project' => [
            'title' => 'Projekt und Datenquelle',
            'description' => 'Projekte, Recovery und Datenquellen verwalten.',
            'ids' => ['project.create', 'project.recovery', 'datasource.configure'],
        ],
        'dataform' => [
            'title' => 'DataForm-Konfiguration',
            'description' => 'DataForm, Felder, Beziehungen, Events, Aktionen und Diagnose.',
            'ids' => ['dataform.create', 'dataform.fields', 'dataform.relations', 'dataform.events', 'dataform.actions', 'dataform.diagnostics'],
        ],
        'reuse' => [
            'title' => 'Vorlagen und Transport',
            'description' => 'DataForms wiederverwenden, importieren, exportieren und als Module bündeln.',
            'ids' => ['dataform.templates', 'dataform.template-library', 'dataform.transport', 'dataform.module-transport', 'dataform.module-library'],
        ],
        'release' => [
            'title' => 'Module, Releases und Migrationen',
            'description' => 'Abhängigkeiten, Updates, Migrationen, Historie und Release-Gates.',
            'ids' => ['dataform.module-dependencies', 'dataform.module-updates', 'dataform.module-migrations', 'dataform.module-migration-history', 'dataform.module-release-gate'],
        ],
        'trust' => [
            'title' => 'Trust, Signierung und Cluster',
            'description' => 'Release-Vertrauen, Recovery, Federation, Failover und Cluster-Mitgliedschaft.',
            'ids' => ['dataform.release-catalog', 'dataform.trust-policy', 'dataform.trust-recovery', 'dataform.trust-federation', 'dataform.trust-failover', 'dataform.trust-membership'],
        ],
    ];

    /** @var array<string,array{title:string,categories:list<string>,extra:list<string>}> */
    private array $surfaces = [
        'project.management' => ['title' => 'Projektverwaltung', 'categories' => ['workflow','project','reuse','release','trust'], 'extra' => []],
        'project.detail' => ['title' => 'Projekt', 'categories' => ['workflow','project','dataform','reuse','release','trust'], 'extra' => []],
        'datasource' => ['title' => 'Datenquelle', 'categories' => [], 'extra' => ['workflow.standard','datasource.configure','dataform.create','dataform.diagnostics']],
        'dataform' => ['title' => 'DataForm', 'categories' => ['workflow','dataform','reuse','release','trust'], 'extra' => []],
        'dataform.fields' => ['title' => 'DataForm-Felder', 'categories' => [], 'extra' => ['dataform.fields','dataform.diagnostics']],
        'dataform.relations' => ['title' => 'DataForm-Beziehungen', 'categories' => [], 'extra' => ['dataform.relations','dataform.diagnostics']],
        'dataform.events' => ['title' => 'DataForm-Events', 'categories' => [], 'extra' => ['dataform.events','dataform.actions','dataform.diagnostics']],
        'dataform.actions' => ['title' => 'DataForm-Aktionen', 'categories' => [], 'extra' => ['dataform.actions','dataform.events','dataform.diagnostics']],
        'dataform.diagnostics' => ['title' => 'DataForm-Diagnose', 'categories' => [], 'extra' => ['dataform.diagnostics']],
        'assistant.center' => ['title' => 'Assistentenzentrale', 'categories' => ['workflow','project','dataform','reuse','release','trust'], 'extra' => []],
    ];

    public function __construct(private AssistantRegistry $registry) {}

    /** @return array<string,array{title:string,description:string,ids:list<string>}> */
    public function categories(): array
    {
        $result = [];
        foreach ($this->categories as $key => $category) {
            $ids = array_values(array_filter($category['ids'], fn(string $id): bool => $this->registry->has($id)));
            if ($ids !== []) {
                $result[$key] = ['title' => $category['title'], 'description' => $category['description'], 'ids' => $ids];
            }
        }
        return $result;
    }

    /** @return array<string,array{title:string,description:string,assistants:list<array{id:string,title:string,description:string,history:bool}>}> */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->categories() as $key => $category) {
            $assistants = [];
            foreach ($category['ids'] as $id) {
                $assistant = $this->registry->get($id);
                $assistants[] = [
                    'id' => $id,
                    'title' => $assistant->getTitle(),
                    'description' => $assistant->getDescription(),
                    'history' => $this->hasHistory($id),
                ];
            }
            $groups[$key] = ['title' => $category['title'], 'description' => $category['description'], 'assistants' => $assistants];
        }
        return $groups;
    }

    public function hasSurface(string $surface): bool
    {
        return isset($this->surfaces[$surface]);
    }

    /** @return list<string> */
    public function surfaceIds(): array
    {
        return array_keys($this->surfaces);
    }

    public function surfaceTitle(string $surface): string
    {
        return $this->surfaces[$surface]['title'] ?? $surface;
    }

    /** @return list<string> */
    public function assistantIdsForSurface(string $surface): array
    {
        if (!$this->hasSurface($surface)) {
            throw new \InvalidArgumentException('Unbekannte Assistenten-Oberfläche: ' . $surface);
        }
        $ids = [];
        foreach ($this->surfaces[$surface]['categories'] as $categoryId) {
            foreach (($this->categories[$categoryId]['ids'] ?? []) as $id) { $ids[] = $id; }
        }
        foreach ($this->surfaces[$surface]['extra'] as $id) { $ids[] = $id; }
        $ids = array_values(array_unique($ids));
        return array_values(array_filter($ids, fn(string $id): bool => $this->registry->has($id)));
    }

    /** @return array{project:bool,dataForm:bool} */
    public function requirements(string $assistantId): array
    {
        $dataFormRequired = in_array($assistantId, [
            'dataform.fields','dataform.relations','dataform.events','dataform.actions','dataform.diagnostics'
        ], true);
        $projectRequired = $dataFormRequired;
        return ['project' => $projectRequired, 'dataForm' => $dataFormRequired];
    }

    public function hasHistory(string $assistantId): bool
    {
        return in_array($assistantId, [
            'datasource.configure','dataform.create','dataform.templates','dataform.template-library','dataform.transport',
            'dataform.module-transport','dataform.module-library','dataform.module-dependencies','dataform.module-updates',
            'dataform.module-migrations','dataform.module-migration-history','dataform.module-release-gate','dataform.release-catalog',
            'dataform.trust-policy','dataform.trust-recovery','dataform.trust-federation','dataform.trust-failover','dataform.trust-membership',
            'dataform.fields','dataform.relations','dataform.events','dataform.actions','dataform.diagnostics','workflow.standard'
        ], true);
    }
}
