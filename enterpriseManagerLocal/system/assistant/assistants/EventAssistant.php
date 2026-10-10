<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Event\EventConfigCompiler;
use EasyIT\Assistant\Event\EventDraft;
use EasyIT\Assistant\Event\EventDraftValidator;
use EasyIT\Assistant\State\AssistantStateStore;

final class EventAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private EventDraftValidator $validator,
        private EventConfigCompiler $compiler
    ) {}

    public function getId(): string { return 'dataform.events'; }
    public function getTitle(): string { return 'Event- und JavaScript-Assistent'; }
    public function getDescription(): string
    {
        return 'Konfiguriert beforeSave, afterSave, beforeDelete und afterDelete mit einem einheitlichen DataForm-Übergabeobjekt.';
    }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('context', '1. Übergabeobjekt', 'Name des JavaScript-Objekts festlegen, das den aktuellen DataForm-Inhalt und Zustand an jede Methode übergibt.', [
                'fields' => [
                    ['name' => 'context_object_name', 'label' => 'Name des Übergabeobjekts', 'type' => 'text', 'required' => true, 'default' => 'dataFormContext'],
                ],
                'fixed' => ['schema' => 'easyit.dataform.action-context.v1'],
            ]),
            new AssistantStep('before_save', '2. Vor dem Speichern', 'Optionale JavaScript-Methode vor dem Speichern. Rückgabe false bricht das Speichern ab.', [
                'fields' => [
                    ['name' => 'before_save_enabled', 'label' => 'beforeSave aktiv', 'type' => 'checkbox'],
                    ['name' => 'before_save_handler', 'label' => 'JavaScript-Methode', 'type' => 'text', 'placeholder' => 'beforeSave'],
                ],
            ]),
            new AssistantStep('after_save', '3. Nach dem Speichern', 'JavaScript-Methode nach erfolgreichem Speichern, z. B. afterSave(dataFormContext).', [
                'fields' => [
                    ['name' => 'after_save_enabled', 'label' => 'afterSave aktiv', 'type' => 'checkbox'],
                    ['name' => 'after_save_handler', 'label' => 'JavaScript-Methode', 'type' => 'text', 'placeholder' => 'afterSave'],
                ],
            ]),
            new AssistantStep('before_delete', '4. Vor dem Löschen', 'Optionale JavaScript-Methode vor dem Löschen. Rückgabe false bricht das Löschen ab.', [
                'fields' => [
                    ['name' => 'before_delete_enabled', 'label' => 'beforeDelete aktiv', 'type' => 'checkbox'],
                    ['name' => 'before_delete_handler', 'label' => 'JavaScript-Methode', 'type' => 'text', 'placeholder' => 'beforeDelete'],
                ],
            ]),
            new AssistantStep('after_delete', '5. Nach dem Löschen', 'JavaScript-Methode nach erfolgreichem Löschen.', [
                'fields' => [
                    ['name' => 'after_delete_enabled', 'label' => 'afterDelete aktiv', 'type' => 'checkbox'],
                    ['name' => 'after_delete_handler', 'label' => 'JavaScript-Methode', 'type' => 'text', 'placeholder' => 'afterDelete'],
                ],
            ]),
            new AssistantStep('review', '6. Prüfen und übernehmen', 'Event-Konfiguration validieren und als JSON bereitstellen.'),
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

        $draft = new EventDraft($this->stateStore->get($this->getId(), $scope));
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));
        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $draft = $this->applyStepInput($draft, $context, $stepId);
            $this->stateStore->put($this->getId(), $draft->toArray(), $scope);
        }

        if ($method === 'POST') {
            $validation = $this->validator->validate($draft, $stepId === 'review' ? null : $stepId);
        } elseif ($stepId === 'review') {
            $validation = $this->validator->validate($draft, null);
        } else {
            $validation = ['errors' => [], 'warnings' => []];
        }

        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $index => $step) {
            $state = $index < $currentIndex ? AssistantStep::STATE_COMPLETE : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING);
            $stateful[] = $step->withState($state);
        }

        $data = [
            'phase' => 4,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => $stepId === 'review' ? $this->compiler->compile($draft) : null,
            'configurationTitle' => 'DataForm-Event-Konfiguration',
            'exportLabel' => 'JSON-Event-Konfiguration herunterladen',
            'rules' => [
                'singleContextForAllActions' => true,
                'noEval' => true,
                'beforeEventsCanCancel' => true,
                'afterSaveSignature' => 'afterSave(dataFormContext)',
            ],
        ];

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function applyStepInput(EventDraft $draft, AssistantContext $context, string $stepId): EventDraft
    {
        return match ($stepId) {
            'context' => $draft->merge(['context' => [
                'objectName' => trim((string) $context->input('context_object_name', 'dataFormContext')),
                'schema' => 'easyit.dataform.action-context.v1',
            ]]),
            'before_save' => $draft->merge(['events' => ['beforeSave' => [
                'enabled' => $this->boolInput($context, 'before_save_enabled'),
                'handler' => trim((string) $context->input('before_save_handler', '')),
                'blocking' => true,
            ]]]),
            'after_save' => $draft->merge(['events' => ['afterSave' => [
                'enabled' => $this->boolInput($context, 'after_save_enabled'),
                'handler' => trim((string) $context->input('after_save_handler', '')),
                'blocking' => false,
            ]]]),
            'before_delete' => $draft->merge(['events' => ['beforeDelete' => [
                'enabled' => $this->boolInput($context, 'before_delete_enabled'),
                'handler' => trim((string) $context->input('before_delete_handler', '')),
                'blocking' => true,
            ]]]),
            'after_delete' => $draft->merge(['events' => ['afterDelete' => [
                'enabled' => $this->boolInput($context, 'after_delete_enabled'),
                'handler' => trim((string) $context->input('after_delete_handler', '')),
                'blocking' => false,
            ]]]),
            default => $draft,
        };
    }

    private function formValues(EventDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        return match ($stepId) {
            'context' => ['context_object_name' => $d['context']['objectName']],
            'before_save' => ['before_save_enabled' => $d['events']['beforeSave']['enabled'], 'before_save_handler' => $d['events']['beforeSave']['handler']],
            'after_save' => ['after_save_enabled' => $d['events']['afterSave']['enabled'], 'after_save_handler' => $d['events']['afterSave']['handler']],
            'before_delete' => ['before_delete_enabled' => $d['events']['beforeDelete']['enabled'], 'before_delete_handler' => $d['events']['beforeDelete']['handler']],
            'after_delete' => ['after_delete_enabled' => $d['events']['afterDelete']['enabled'], 'after_delete_handler' => $d['events']['afterDelete']['handler']],
            default => [],
        };
    }

    private function scope(AssistantContext $context): string
    {
        return ($context->getProjectId() ?: 'global') . '|' . ($context->getDataFormId() ?: 'dataform');
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        $value = $context->input($key, false);
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    /** @param list<string> $ids */
    private function nextStep(array $ids, string $current): ?string
    {
        $i = array_search($current, $ids, true);
        return $i !== false && isset($ids[$i + 1]) ? $ids[$i + 1] : null;
    }

    /** @param list<string> $ids */
    private function previousStep(array $ids, string $current): ?string
    {
        $i = array_search($current, $ids, true);
        return $i !== false && $i > 0 ? $ids[$i - 1] : null;
    }
}
