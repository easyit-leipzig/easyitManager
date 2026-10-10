<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Relation\RelationConfigCompiler;
use EasyIT\Assistant\Relation\RelationDraft;
use EasyIT\Assistant\Relation\RelationDraftValidator;
use EasyIT\Assistant\State\AssistantStateStore;

final class RelationAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private RelationDraftValidator $validator,
        private RelationConfigCompiler $compiler
    ) {}

    public function getId(): string { return 'dataform.relations'; }
    public function getTitle(): string { return 'Beziehungs- und gebundene-Formulare-Assistent'; }
    public function getDescription(): string
    {
        return 'Konfiguriert 1:n- und n:m-Beziehungen sowie die automatische Bindung neuer Kinddatensätze an den aktuellen Eltern-Datensatz.';
    }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('relation', '1. Beziehung', 'Beziehungsname und Beziehungstyp festlegen.', [
                'fields' => [
                    ['name' => 'relation_name', 'label' => 'Beziehungsname', 'type' => 'text', 'required' => true],
                    ['name' => 'relation_type', 'label' => 'Beziehungstyp', 'type' => 'select', 'options' => ['one_to_many' => '1:n', 'many_to_many' => 'n:m']],
                ],
            ]),
            new AssistantStep('parent', '2. Übergeordnetes DataForm', 'Das übergeordnete DataForm und dessen Schlüsselfeld festlegen. Standard ist id.', [
                'fields' => [
                    ['name' => 'parent_dataform', 'label' => 'Übergeordnetes DataForm', 'type' => 'text', 'required' => true],
                    ['name' => 'parent_source', 'label' => 'Elterntabelle / Quelle', 'type' => 'text'],
                    ['name' => 'parent_key_field', 'label' => 'Schlüsselfeld des übergeordneten Datensatzes', 'type' => 'text', 'required' => true, 'default' => 'id'],
                ],
            ]),
            new AssistantStep('child', '3. Untergeordnetes DataForm', 'Kind-DataForm und bei 1:n das Feld angeben, in das die ID des aktuellen Eltern-Datensatzes geschrieben wird.', [
                'fields' => [
                    ['name' => 'child_dataform', 'label' => 'Untergeordnetes DataForm', 'type' => 'text', 'required' => true],
                    ['name' => 'child_source', 'label' => 'Kindtabelle / Quelle', 'type' => 'text'],
                    ['name' => 'child_foreign_key', 'label' => 'Kind-Fremdschlüsselfeld (z. B. to_ev_id)', 'type' => 'text'],
                ],
            ]),
            new AssistantStep('binding', '4. Gebundenes Kindformular', 'Für 1:n wird der Wert des aktuellen Eltern-Datensatzes automatisch in das Kind-Fremdschlüsselfeld übernommen. Read-only ist standardmäßig aktiviert.', [
                'fields' => [
                    ['name' => 'binding_enabled', 'label' => 'Kindformular an aktuellen Eltern-Datensatz binden', 'type' => 'checkbox', 'default' => true],
                    ['name' => 'child_target_field', 'label' => 'Gebundenes Zielfeld im Kind-DataForm', 'type' => 'text'],
                    ['name' => 'binding_readonly', 'label' => 'Gebundenes Kindfeld read-only', 'type' => 'checkbox', 'default' => true],
                ],
                'fixed' => [
                    'valueSource' => 'parent.currentRecord[parent.keyField]',
                    'fillOnNewRecord' => true,
                ],
            ]),
            new AssistantStep('junction', '5. n:m-Zwischentabelle', 'Nur für n:m: Zwischentabelle und beide Fremdschlüssel festlegen.', [
                'fields' => [
                    ['name' => 'junction_source', 'label' => 'Zwischentabelle', 'type' => 'text'],
                    ['name' => 'junction_parent_fk', 'label' => 'Eltern-Fremdschlüssel in Zwischentabelle', 'type' => 'text'],
                    ['name' => 'junction_child_fk', 'label' => 'Kind-Fremdschlüssel in Zwischentabelle', 'type' => 'text'],
                    ['name' => 'child_key_field', 'label' => 'Schlüsselfeld des Kind-Datensatzes', 'type' => 'text', 'default' => 'id'],
                ],
            ]),
            new AssistantStep('review', '6. Prüfen und übernehmen', 'Beziehung validieren und als Konfigurationsobjekt bereitstellen.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $steps = $this->getSteps($context);
        $ids = array_map(static fn (AssistantStep $step): string => $step->getId(), $steps);
        $stepId = $stepId ?: 'relation';
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        $scope = $this->scope($context);
        if ($this->boolInput($context, 'reset')) {
            $this->stateStore->clear($this->getId(), $scope);
        }

        $draft = new RelationDraft($this->stateStore->get($this->getId(), $scope));
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
            'phase' => 3,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => $stepId === 'review' ? $this->compiler->compile($draft) : null,
            'configurationTitle' => 'Beziehungskonfiguration',
            'exportLabel' => 'JSON-Beziehungskonfiguration herunterladen',
            'rules' => [
                'boundValueSource' => 'current parent record',
                'boundTargetIsChildForeignKey' => true,
                'fillBoundValueOnNewChildRecord' => true,
                'boundFieldReadOnlyDefault' => true,
                'paginationAlwaysBelowRecords' => true,
            ],
        ];

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function applyStepInput(RelationDraft $draft, AssistantContext $context, string $stepId): RelationDraft
    {
        $current = $draft->toArray();
        return match ($stepId) {
            'relation' => $draft->merge(['relation' => [
                'name' => trim((string) $context->input('relation_name', '')),
                'type' => trim((string) $context->input('relation_type', 'one_to_many')),
            ]]),
            'parent' => $draft->merge(['parent' => [
                'dataForm' => trim((string) $context->input('parent_dataform', '')),
                'source' => trim((string) $context->input('parent_source', '')),
                'keyField' => trim((string) $context->input('parent_key_field', 'id')),
            ]]),
            'child' => $this->applyChild($draft, $context),
            'binding' => $draft->merge(['binding' => [
                'enabled' => $this->boolInput($context, 'binding_enabled'),
                'valueSource' => 'parent.currentRecord',
                'parentValueField' => (string) ($current['parent']['keyField'] ?? 'id'),
                'childTargetField' => trim((string) $context->input('child_target_field', $current['child']['foreignKeyField'] ?? '')),
                'fillOnNewRecord' => true,
                'readOnly' => $this->boolInput($context, 'binding_readonly'),
            ]]),
            'junction' => $draft->merge(['manyToMany' => [
                'junctionSource' => trim((string) $context->input('junction_source', '')),
                'parentForeignKeyField' => trim((string) $context->input('junction_parent_fk', '')),
                'childForeignKeyField' => trim((string) $context->input('junction_child_fk', '')),
                'childKeyField' => trim((string) $context->input('child_key_field', 'id')),
            ]]),
            default => $draft,
        };
    }

    private function applyChild(RelationDraft $draft, AssistantContext $context): RelationDraft
    {
        $fk = trim((string) $context->input('child_foreign_key', ''));
        $changes = ['child' => [
            'dataForm' => trim((string) $context->input('child_dataform', '')),
            'source' => trim((string) $context->input('child_source', '')),
            'foreignKeyField' => $fk,
        ]];
        if ($fk !== '') {
            $changes['binding'] = ['childTargetField' => $fk];
        }
        return $draft->merge($changes);
    }

    private function formValues(RelationDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        return match ($stepId) {
            'relation' => ['relation_name' => $d['relation']['name'], 'relation_type' => $d['relation']['type']],
            'parent' => ['parent_dataform' => $d['parent']['dataForm'], 'parent_source' => $d['parent']['source'], 'parent_key_field' => $d['parent']['keyField']],
            'child' => ['child_dataform' => $d['child']['dataForm'], 'child_source' => $d['child']['source'], 'child_foreign_key' => $d['child']['foreignKeyField']],
            'binding' => [
                'binding_enabled' => $d['binding']['enabled'],
                'child_target_field' => $d['binding']['childTargetField'] !== '' ? $d['binding']['childTargetField'] : $d['child']['foreignKeyField'],
                'binding_readonly' => $d['binding']['readOnly'],
            ],
            'junction' => [
                'junction_source' => $d['manyToMany']['junctionSource'],
                'junction_parent_fk' => $d['manyToMany']['parentForeignKeyField'],
                'junction_child_fk' => $d['manyToMany']['childForeignKeyField'],
                'child_key_field' => $d['manyToMany']['childKeyField'],
            ],
            default => [],
        };
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        return in_array($context->input($key, false), [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function scope(AssistantContext $context): string
    {
        return ($context->getProjectId() ?: 'global') . '|' . ($context->getDataFormId() ?: 'relation');
    }

    private function nextStep(array $ids, string $stepId): ?string
    {
        $i = array_search($stepId, $ids, true);
        return ($i !== false && isset($ids[$i + 1])) ? $ids[$i + 1] : null;
    }

    private function previousStep(array $ids, string $stepId): ?string
    {
        $i = array_search($stepId, $ids, true);
        return ($i !== false && $i > 0) ? $ids[$i - 1] : null;
    }
}
