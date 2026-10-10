<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Field\FieldConfigCompiler;
use EasyIT\Assistant\Field\FieldDraft;
use EasyIT\Assistant\Field\FieldDraftValidator;
use EasyIT\Assistant\Field\FieldTypeRegistry;
use EasyIT\Assistant\State\AssistantStateStore;

final class FieldAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private FieldTypeRegistry $types,
        private FieldDraftValidator $validator,
        private FieldConfigCompiler $compiler
    ) {}

    public function getId(): string { return 'dataform.fields'; }
    public function getTitle(): string { return 'Feld-, Lookup- und Enum-Assistent'; }
    public function getDescription(): string
    {
        return 'Konfiguriert DataForm-Felder, Lookup-Quellen und abgeleitete Mehrfach-Enums mit verbindlich kommaseparierter Speicherung.';
    }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('context', '1. DataForm-Kontext', 'DataForm, Hauptquelle und Primärschlüssel festlegen oder aus dem DataForm-Assistenten übernehmen.', [
                'fields' => [
                    ['name' => 'dataform_name', 'label' => 'DataForm-Name', 'type' => 'text', 'required' => true],
                    ['name' => 'source_profile', 'label' => 'Datenquellenprofil', 'type' => 'text'],
                    ['name' => 'source_name', 'label' => 'Haupttabelle / View / CSV-Tabelle', 'type' => 'text'],
                    ['name' => 'primary_key', 'label' => 'Primärschlüssel', 'type' => 'text', 'required' => true, 'default' => 'id'],
                ],
            ]),
            new AssistantStep('fields', '2. Felder', 'Eine Zeile pro Feld: name|label|type|required|readonly|default. Unterstützt u. a. text, integer, decimal, boolean, date, datetime, lookup und derived_enum.', [
                'fields' => [
                    ['name' => 'field_lines', 'label' => 'Felddefinitionen', 'type' => 'textarea', 'rows' => 12, 'placeholder' => "id|ID|integer|1|1|\nname|Name|text|1|0|\nto_type_id|Typ|lookup|0|0|\ntags|Merkmale|derived_enum|0|0|"],
                ],
            ]),
            new AssistantStep('lookups', '3. Lookup-Felder', 'Eine Zeile pro Lookup: feld|profil|quelle|wertfeld|anzeigefeld|filterfeld|filterwertquelle.', [
                'fields' => [
                    ['name' => 'lookup_lines', 'label' => 'Lookup-Definitionen', 'type' => 'textarea', 'rows' => 8, 'placeholder' => 'to_type_id|project-main|ed_ev_type|id|name||'],
                ],
            ]),
            new AssistantStep('derived_enum', '4. Abgeleitete Mehrfach-Enums', 'Eine Zeile pro Derived Enum: feld|profil|quelle|wertfeld|anzeigefeld|filterfeld|filterwertquelle. Speicherung ist immer kommasepariert und Mehrfachauswahl immer aktiv.', [
                'fields' => [
                    ['name' => 'derived_enum_lines', 'label' => 'Derived-Enum-Definitionen', 'type' => 'textarea', 'rows' => 8, 'placeholder' => 'tags|project-main|ed_ev_person|id|name|to_ev_id|record.id'],
                ],
                'fixed' => [
                    'storage' => 'comma-separated',
                    'delimiter' => ',',
                    'multiple' => true,
                    'trim' => true,
                    'deduplicate' => true,
                ],
            ]),
            new AssistantStep('review', '5. Prüfen und übernehmen', 'Feldkonfiguration vollständig validieren, JSON erzeugen und optional in den DataForm-Assistenten zurückführen.'),
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

        $draft = new FieldDraft($this->stateStore->get($this->getId(), $scope));
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));

        if ($method === 'GET' && $this->boolInput($context, 'import_dataform')) {
            $dfState = $this->stateStore->get('dataform.create', $context->getProjectId() ?: 'global');
            if (is_array($dfState) && $dfState !== []) {
                $draft = $draft->merge([
                    'context' => [
                        'dataForm' => (string) ($dfState['identity']['dataFormName'] ?? ($context->getDataFormId() ?? '')),
                        'sourceProfile' => (string) ($dfState['source']['profile'] ?? ''),
                        'sourceName' => (string) ($dfState['source']['name'] ?? ''),
                        'primaryKey' => (string) ($dfState['identity']['primaryKey'] ?? 'id'),
                    ],
                    'fields' => is_array($dfState['fields'] ?? null) ? $dfState['fields'] : [],
                ]);
                $this->stateStore->put($this->getId(), $draft->toArray(), $scope);
            }
        }

        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $draft = $this->applyInput($draft, $context, $stepId);
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
            $stateful[] = $step->withState($index < $currentIndex ? AssistantStep::STATE_COMPLETE : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING));
        }

        $compiled = $stepId === 'review' ? $this->compiler->compile($draft) : null;
        $data = [
            'phase' => 7,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => $compiled,
            'configurationTitle' => 'DataForm-Feldkonfiguration',
            'exportLabel' => 'JSON-Feldkonfiguration herunterladen',
            'fieldTypes' => $this->types->all(),
            'rules' => [
                'lookupIsSingleValue' => true,
                'derivedEnumIsMultiple' => true,
                'derivedEnumStorage' => 'comma-separated',
                'derivedEnumDelimiter' => ',',
                'derivedEnumKeysMustNotContainComma' => true,
                'trimAndDeduplicate' => true,
            ],
        ];

        if ($stepId === 'review' && $validation['errors'] === []) {
            $query = [
                'assistant' => 'dataform.create',
                'step' => 'fields',
                'import_fields' => '1',
            ];
            if ($context->getProjectId()) { $query['project_id'] = $context->getProjectId(); }
            $data['handoffUrl'] = 'run.php?' . http_build_query($query);
            $data['handoffLabel'] = 'Feldkonfiguration in DataForm-Assistent übernehmen';
        }

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function applyInput(FieldDraft $draft, AssistantContext $context, string $stepId): FieldDraft
    {
        return match ($stepId) {
            'context' => $draft->merge(['context' => [
                'dataForm' => trim((string) $context->input('dataform_name', '')),
                'sourceProfile' => trim((string) $context->input('source_profile', '')),
                'sourceName' => trim((string) $context->input('source_name', '')),
                'primaryKey' => trim((string) $context->input('primary_key', 'id')),
            ]]),
            'fields' => $draft->merge(['fields' => $this->parseFields((string) $context->input('field_lines', ''))]),
            'lookups' => $draft->merge(['lookups' => $this->parseLookups((string) $context->input('lookup_lines', ''))]),
            'derived_enum' => $draft->merge(['derivedEnums' => $this->parseDerivedEnums((string) $context->input('derived_enum_lines', ''))]),
            default => $draft,
        };
    }

    private function parseFields(string $text): array
    {
        $result = [];
        foreach ($this->lines($text) as $parts) {
            $result[] = [
                'name' => $parts[0] ?? '',
                'label' => $parts[1] ?? ($parts[0] ?? ''),
                'type' => $parts[2] ?? 'text',
                'required' => $this->truthy($parts[3] ?? '0'),
                'readOnly' => $this->truthy($parts[4] ?? '0'),
                'default' => array_key_exists(5, $parts) && $parts[5] !== '' ? $parts[5] : null,
            ];
        }
        return $result;
    }

    private function parseLookups(string $text): array
    {
        $result = [];
        foreach ($this->lines($text) as $parts) {
            $result[] = [
                'field' => $parts[0] ?? '',
                'profile' => $parts[1] ?? '',
                'source' => $parts[2] ?? '',
                'valueField' => $parts[3] ?? 'id',
                'labelField' => $parts[4] ?? 'name',
                'filterField' => $parts[5] ?? '',
                'filterValueSource' => $parts[6] ?? '',
            ];
        }
        return $result;
    }

    private function parseDerivedEnums(string $text): array
    {
        $result = [];
        foreach ($this->lines($text) as $parts) {
            $result[] = [
                'field' => $parts[0] ?? '',
                'profile' => $parts[1] ?? '',
                'source' => $parts[2] ?? '',
                'valueField' => $parts[3] ?? 'id',
                'labelField' => $parts[4] ?? 'name',
                'filterField' => $parts[5] ?? '',
                'filterValueSource' => $parts[6] ?? '',
                'multiple' => true,
                'delimiter' => ',',
            ];
        }
        return $result;
    }

    /** @return list<list<string>> */
    private function lines(string $text): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $lines[] = array_map('trim', explode('|', $line));
        }
        return $lines;
    }

    private function formValues(FieldDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        return match ($stepId) {
            'context' => [
                'dataform_name' => $d['context']['dataForm'],
                'source_profile' => $d['context']['sourceProfile'],
                'source_name' => $d['context']['sourceName'],
                'primary_key' => $d['context']['primaryKey'],
            ],
            'fields' => ['field_lines' => $this->fieldLines($d['fields'] ?? [])],
            'lookups' => ['lookup_lines' => $this->lookupLines($d['lookups'] ?? [])],
            'derived_enum' => ['derived_enum_lines' => $this->derivedEnumLines($d['derivedEnums'] ?? [])],
            default => [],
        };
    }

    private function fieldLines(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $lines[] = implode('|', [
                $field['name'] ?? '', $field['label'] ?? '', $field['type'] ?? 'text',
                !empty($field['required']) ? '1' : '0', !empty($field['readOnly']) ? '1' : '0', $field['default'] ?? '',
            ]);
        }
        return implode("\n", $lines);
    }

    private function lookupLines(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = implode('|', [
                $item['field'] ?? '', $item['profile'] ?? '', $item['source'] ?? '', $item['valueField'] ?? 'id',
                $item['labelField'] ?? 'name', $item['filterField'] ?? '', $item['filterValueSource'] ?? '',
            ]);
        }
        return implode("\n", $lines);
    }

    private function derivedEnumLines(array $items): string
    {
        return $this->lookupLines($items);
    }

    private function scope(AssistantContext $context): string
    {
        return ($context->getProjectId() ?: 'global') . '|' . ($context->getDataFormId() ?: 'dataform');
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        return $this->truthy($context->input($key, false));
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
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
