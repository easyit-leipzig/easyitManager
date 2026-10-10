<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\Action\ActionConfigCompiler;
use EasyIT\Assistant\Action\ActionDraft;
use EasyIT\Assistant\Action\ActionDraftValidator;
use EasyIT\Assistant\Action\DataFormActionRegistry;
use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\State\AssistantStateStore;

final class ActionAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private DataFormActionRegistry $registry,
        private ActionDraftValidator $validator,
        private ActionConfigCompiler $compiler
    ) {}

    public function getId(): string { return 'dataform.actions'; }
    public function getTitle(): string { return 'Aktions- und Button-Assistent'; }
    public function getDescription(): string
    {
        return 'Konfiguriert DataForm-Aktionen ausschließlich aus der zentralen Aktions-/Button-Registry mit zentralem title und aria-label.';
    }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('context', '1. DataForm', 'DataForm festlegen, für das die Aktionen gelten.', [
                'fields' => [
                    ['name' => 'dataform_name', 'label' => 'DataForm-Name', 'type' => 'text', 'required' => true],
                ],
            ]),
            new AssistantStep('actions', '2. Aktionen', 'Nur zentral registrierte Aktionen auswählen. open wird auf show, create auf new normalisiert.', [
                'fields' => $this->actionFields(),
            ]),
            new AssistantStep('registry', '3. Button-Registry', 'Zentrale Button-Metadaten prüfen. Lokale title-/aria-label-Texte und CSS-Farbbuttons sind nicht zulässig.'),
            new AssistantStep('review', '4. Prüfen und übernehmen', 'Aktionskonfiguration validieren und als JSON bereitstellen.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $steps = $this->getSteps($context);
        $ids = array_map(static fn (AssistantStep $step): string => $step->getId(), $steps);
        $stepId = $stepId ?: 'context';
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        $scope = $this->scope($context);
        if ($this->boolInput($context, 'reset')) {
            $this->stateStore->clear($this->getId(), $scope);
        }

        $draft = new ActionDraft($this->stateStore->get($this->getId(), $scope));
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));
        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $draft = $this->applyInput($draft, $context, $stepId);
            $this->stateStore->put($this->getId(), $draft->toArray(), $scope);
        }

        if ($method === 'POST') {
            $validation = $this->validator->validate($draft, $stepId === 'review' ? null : $stepId);
        } elseif (in_array($stepId, ['registry', 'review'], true)) {
            $validation = $this->validator->validate($draft, $stepId === 'review' ? null : $stepId);
        } else {
            $validation = ['errors' => [], 'warnings' => []];
        }

        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $index => $step) {
            $stateful[] = $step->withState($index < $currentIndex ? AssistantStep::STATE_COMPLETE : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING));
        }

        $data = [
            'phase' => 5,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => in_array($stepId, ['registry', 'review'], true) ? $this->compiler->compile($draft) : null,
            'configurationTitle' => 'DataForm-Aktionskonfiguration',
            'exportLabel' => 'JSON-Aktionskonfiguration herunterladen',
            'registry' => $this->registry,
            'rules' => [
                'centralButtonRegistryOnly' => true,
                'titleAndAriaFromRegistryOnly' => true,
                'noColoredCssBackgrounds' => true,
                'allActionsReceiveDataFormActionContext' => true,
                'openAlias' => 'show',
            ],
        ];

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function actionFields(): array
    {
        $fields = [];
        foreach ($this->registry->all() as $definition) {
            $fields[] = [
                'name' => 'action_' . $definition->getId(),
                'label' => $definition->getLabel(),
                'type' => 'checkbox',
                'default' => true,
            ];
        }
        return $fields;
    }

    private function applyInput(ActionDraft $draft, AssistantContext $context, string $stepId): ActionDraft
    {
        if ($stepId === 'context') {
            return $draft->merge(['dataForm' => ['name' => trim((string) $context->input('dataform_name', ''))]]);
        }
        if ($stepId === 'actions') {
            $actions = [];
            foreach ($this->registry->all() as $id => $definition) {
                $actions[$id] = $this->boolInput($context, 'action_' . $id);
            }
            return $draft->merge(['actions' => $actions]);
        }
        return $draft;
    }

    private function formValues(ActionDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        if ($stepId === 'context') {
            return ['dataform_name' => $d['dataForm']['name'] !== '' ? $d['dataForm']['name'] : ''];
        }
        if ($stepId === 'actions') {
            $values = [];
            foreach ($this->registry->all() as $id => $definition) {
                $values['action_' . $id] = !empty($d['actions'][$id]);
            }
            return $values;
        }
        return [];
    }

    private function scope(AssistantContext $context): string
    {
        return ($context->getProjectId() ?: 'global') . '|' . ($context->getDataFormId() ?: 'dataform');
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        return in_array($context->input($key, false), [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function nextStep(array $ids, string $current): ?string
    {
        $i = array_search($current, $ids, true);
        return $i !== false && isset($ids[$i + 1]) ? $ids[$i + 1] : null;
    }

    private function previousStep(array $ids, string $current): ?string
    {
        $i = array_search($current, $ids, true);
        return $i !== false && $i > 0 ? $ids[$i - 1] : null;
    }
}
