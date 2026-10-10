<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\DataForm\DataFormDraft;
use EasyIT\Assistant\DataForm\DataFormDraftValidator;
use EasyIT\Assistant\DataForm\DataFormConfigCompiler;
use EasyIT\Assistant\Field\FieldDraft;
use EasyIT\Assistant\Field\FieldTypeRegistry;
use EasyIT\Assistant\Field\FieldDraftValidator;
use EasyIT\Assistant\Field\FieldConfigCompiler;
use EasyIT\Assistant\State\AssistantStateStore;

final class DataFormAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private DataFormDraftValidator $validator,
        private DataFormConfigCompiler $compiler
    ) {}

    public function getId(): string { return 'dataform.create'; }
    public function getTitle(): string { return 'DataForm-Assistent'; }
    public function getDescription(): string
    {
        return 'Erzeugt schrittweise einen validierten DataForm-Entwurf mit Datenquelle, Schlüssel, Suche/Filter, Pagination, CRUD und Feldern.';
    }

    /** @return array<string,string> */
    private static function runtimeDriverOptions(): array
    {
        $pdo = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        $all = [
            'mysql' => ['MySQL', 'mysql'],
            'pgsql' => ['PostgreSQL', 'pgsql'],
            'sqlite' => ['SQLite', 'sqlite'],
            'csv' => ['CSV', null],
            'oracle' => ['Oracle', 'oci'],
            'mssql' => ['Microsoft SQL Server', 'sqlsrv'],
        ];
        $out = [];
        foreach ($all as $driver => [$label, $pdoDriver]) {
            if ($pdoDriver === null || in_array($pdoDriver, $pdo, true)) {
                $out[$driver] = $label;
            }
        }
        return $out;
    }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('source', '1. Datenquelle und Hauptquelle', 'Datenquellentyp, Verbindung und Haupttabelle bzw. View festlegen.', [
                'fields' => [
                    ['name' => 'driver', 'label' => 'Datenquellentyp', 'type' => 'select', 'options' => self::runtimeDriverOptions()],
                    ['name' => 'connection', 'label' => 'Verbindung / Datenquelle', 'type' => 'text'],
                    ['name' => 'source_name', 'label' => 'Haupttabelle / View / CSV-Tabelle', 'type' => 'text', 'required' => true],
                ],
            ]),
            new AssistantStep('identity', '2. DataForm und Primärschlüssel', 'DataForm-Namen und Primärschlüssel festlegen.', [
                'fields' => [
                    ['name' => 'dataform_name', 'label' => 'DataForm-Name', 'type' => 'text', 'required' => true],
                    ['name' => 'primary_key', 'label' => 'Primärschlüssel', 'type' => 'text', 'required' => true, 'default' => 'id'],
                ],
            ]),
            new AssistantStep('features', '3. Suche, Filter und Paginierung', 'Volltextsuche und Filter schalten; die Pagination bleibt verbindlich unter den Datensätzen.', [
                'fields' => [
                    ['name' => 'full_text_search', 'label' => 'Volltextsuche', 'type' => 'checkbox'],
                    ['name' => 'filter_enabled', 'label' => 'Filter', 'type' => 'checkbox'],
                    ['name' => 'page_size', 'label' => 'Datensätze pro Seite', 'type' => 'number', 'min' => 1, 'max' => 500, 'default' => 20],
                ],
                'fixed' => [
                    'pagination.enabled' => true,
                    'pagination.position' => 'below-records',
                    'pagination.windowLeft' => 2,
                    'pagination.windowRight' => 2,
                    'pagination.showFirst' => true,
                    'pagination.showLast' => true,
                ],
            ]),
            new AssistantStep('crud', '4. CRUD-Aktionen', 'Verfügbare DataForm-Aktionen festlegen. Die Darstellung der Aktionsbuttons bleibt Aufgabe der zentralen Button-Registry.', [
                'fields' => [
                    ['name' => 'crud_create', 'label' => 'Neu', 'type' => 'checkbox'],
                    ['name' => 'crud_show', 'label' => 'Anzeigen', 'type' => 'checkbox'],
                    ['name' => 'crud_edit', 'label' => 'Bearbeiten', 'type' => 'checkbox'],
                    ['name' => 'crud_delete', 'label' => 'Löschen', 'type' => 'checkbox'],
                    ['name' => 'crud_save', 'label' => 'Speichern', 'type' => 'checkbox'],
                ],
            ]),
            new AssistantStep('fields', '5. Felder', 'Grundkonfiguration der Felder. Eine Zeile pro Feld: name|label|type|required|readonly.', [
                'fields' => [
                    ['name' => 'field_lines', 'label' => 'Felddefinitionen', 'type' => 'textarea', 'rows' => 10, 'placeholder' => "id|ID|integer|1|1\nname|Name|text|1|0"],
                ],
            ]),
            new AssistantStep('review', '6. Prüfen und übernehmen', 'Gesamten DataForm-Entwurf validieren und als Konfigurationsobjekt bereitstellen.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $steps = $this->getSteps($context);
        $ids = array_map(static fn (AssistantStep $step): string => $step->getId(), $steps);
        $stepId = $stepId ?: 'source';
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        $scope = $this->scope($context);
        if ($this->boolInput($context, 'reset')) {
            $this->stateStore->clear($this->getId(), $scope);
        }

        $draft = new DataFormDraft($this->stateStore->get($this->getId(), $scope));
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));

        // Phase 6 handoff: import a validated data-source assistant profile without copying secrets.
        if ($method === 'GET' && $this->boolInput($context, 'import_datasource')) {
            $sourceState = $this->stateStore->get('datasource.configure', $scope);
            if (is_array($sourceState) && !empty($sourceState['test']['ok']) && !empty($sourceState['selection']['sourceName'])) {
                $profile = (string) ($sourceState['profile']['name'] ?? 'project');
                $draft = $draft->merge(['source' => [
                    'profile' => $profile,
                    'driver' => (string) ($sourceState['profile']['driver'] ?? 'mysql'),
                    'connection' => 'profile:' . $profile,
                    'name' => (string) ($sourceState['selection']['sourceName'] ?? ''),
                ]]);
                $this->persistDraft($context, $draft, $scope);
            }
        }
        if ($method === 'GET' && $this->boolInput($context, 'import_fields')) {
            $dataFormName = trim((string) (($draft->toArray()['identity']['dataFormName'] ?? '')));
            if ($dataFormName !== '') {
                $fieldScope = ($context->getProjectId() ?: 'global') . '|' . $dataFormName;
                $fieldState = $this->stateStore->get('dataform.fields', $fieldScope);
                if (is_array($fieldState) && $fieldState !== []) {
                    $typeRegistry = new FieldTypeRegistry();
                    $fieldDraft = new FieldDraft($fieldState);
                    $fieldValidation = (new FieldDraftValidator($typeRegistry))->validate($fieldDraft, null);
                    if ($fieldValidation['errors'] === []) {
                        $fieldConfig = (new FieldConfigCompiler($typeRegistry))->compile($fieldDraft);
                        $draft = $draft->merge(['fields' => $fieldConfig['fields'] ?? []]);
                        $this->persistDraft($context, $draft, $scope);
                    }
                }
            }
        }

        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $draft = $this->applyStepInput($draft, $context, $stepId);
            $this->persistDraft($context, $draft, $scope);
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
            $state = $index < $currentIndex
                ? AssistantStep::STATE_COMPLETE
                : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING);
            $stateful[] = $step->withState($state);
        }

        $data = [
            'phase' => 7,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => $stepId === 'review' ? $this->compiler->compile($draft) : null,
            'configurationTitle' => 'DataForm-Konfiguration',
            'exportLabel' => 'JSON-DataForm-Konfiguration herunterladen',
            'rules' => [
                'paginationAlwaysBelowRecords' => true,
                'paginationWindow' => 'first, two left, current, two right, last',
                'buttonRegistryRequired' => true,
            ],
        ];

        if ($stepId === 'review' && $validation['errors'] === []) {
            $dataFormName = trim((string) (($draft->toArray()['identity']['dataFormName'] ?? '')));
            if ($dataFormName !== '') {
                $query = [
                    'assistant' => 'dataform.fields',
                    'step' => 'context',
                    'import_dataform' => '1',
                    'dataform_id' => $dataFormName,
                ];
                if ($context->getProjectId()) { $query['project_id'] = $context->getProjectId(); }
                $data['handoffUrl'] = 'run.php?' . http_build_query($query);
                $data['handoffLabel'] = 'Felder, Lookups und Mehrfach-Enums konfigurieren';
            }
        }

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }

        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function applyStepInput(DataFormDraft $draft, AssistantContext $context, string $stepId): DataFormDraft
    {
        return match ($stepId) {
            'source' => $draft->merge(['source' => [
                'driver' => trim((string) $context->input('driver', 'mysql')),
                'connection' => trim((string) $context->input('connection', '')),
                'name' => trim((string) $context->input('source_name', '')),
            ]]),
            'identity' => $draft->merge(['identity' => [
                'dataFormName' => trim((string) $context->input('dataform_name', '')),
                'primaryKey' => trim((string) $context->input('primary_key', 'id')),
            ]]),
            'features' => $draft->merge(['features' => [
                'fullTextSearch' => $this->boolInput($context, 'full_text_search'),
                'filter' => $this->boolInput($context, 'filter_enabled'),
                'pagination' => [
                    'enabled' => true,
                    'position' => 'below-records',
                    'pageSize' => (int) $context->input('page_size', 20),
                    'windowLeft' => 2,
                    'windowRight' => 2,
                    'showFirst' => true,
                    'showLast' => true,
                ],
            ]]),
            'crud' => $draft->merge(['crud' => [
                'create' => $this->boolInput($context, 'crud_create'),
                'show' => $this->boolInput($context, 'crud_show'),
                'edit' => $this->boolInput($context, 'crud_edit'),
                'delete' => $this->boolInput($context, 'crud_delete'),
                'save' => $this->boolInput($context, 'crud_save'),
            ]]),
            'fields' => $draft->merge(['fields' => $this->parseFieldLines((string) $context->input('field_lines', ''))]),
            default => $draft,
        };
    }

    private function parseFieldLines(string $text): array
    {
        $fields = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line));
            $fields[] = [
                'name' => $parts[0] ?? '',
                'label' => $parts[1] ?? ($parts[0] ?? ''),
                'type' => $parts[2] ?? 'text',
                'required' => $this->truthy($parts[3] ?? '0'),
                'readOnly' => $this->truthy($parts[4] ?? '0'),
            ];
        }
        return $fields;
    }

    private function formValues(DataFormDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        return match ($stepId) {
            'source' => [
                'driver' => $d['source']['driver'], 'connection' => $d['source']['connection'], 'source_name' => $d['source']['name'],
            ],
            'identity' => [
                'dataform_name' => $d['identity']['dataFormName'] !== '' ? $d['identity']['dataFormName'] : $d['source']['name'], 'primary_key' => $d['identity']['primaryKey'],
            ],
            'features' => [
                'full_text_search' => $d['features']['fullTextSearch'], 'filter_enabled' => $d['features']['filter'], 'page_size' => $d['features']['pagination']['pageSize'],
            ],
            'crud' => [
                'crud_create' => $d['crud']['create'], 'crud_show' => $d['crud']['show'], 'crud_edit' => $d['crud']['edit'], 'crud_delete' => $d['crud']['delete'], 'crud_save' => $d['crud']['save'],
            ],
            'fields' => ['field_lines' => $this->fieldLines($d['fields'])],
            default => [],
        };
    }

    private function fieldLines(array $fields): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $lines[] = implode('|', [
                $field['name'] ?? '', $field['label'] ?? '', $field['type'] ?? 'text', !empty($field['required']) ? '1' : '0', !empty($field['readOnly']) ? '1' : '0',
            ]);
        }
        return implode("\n", $lines);
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        return $this->truthy($context->input($key, false));
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function persistDraft(AssistantContext $context, DataFormDraft $draft, string $legacyScope): void
    {
        $state = $draft->toArray();
        $this->stateStore->put($this->getId(), $state, $legacyScope);
        $projectId = $context->getProjectId();
        $dataFormName = trim((string)($state['identity']['dataFormName'] ?? ''));
        if ($projectId && $dataFormName !== '') {
            $this->stateStore->putForProject($this->getId(), $projectId, $state, $projectId . '|' . $dataFormName);
        }
    }

    private function scope(AssistantContext $context): string
    {
        return $context->getProjectId() ?: 'global';
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
