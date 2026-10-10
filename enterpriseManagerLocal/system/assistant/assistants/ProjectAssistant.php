<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Project\ProjectConfigCompiler;
use EasyIT\Assistant\Project\ProjectDraft;
use EasyIT\Assistant\Project\ProjectDraftValidator;
use EasyIT\Assistant\Project\ProjectProvisioner;
use EasyIT\Assistant\State\AssistantStateStore;

final class ProjectAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private ProjectDraftValidator $validator,
        private ProjectConfigCompiler $compiler,
        private ProjectProvisioner $provisioner
    ) {}

    public function getId(): string { return 'project.create'; }
    public function getTitle(): string { return 'Projekt-Assistent'; }
    public function getDescription(): string
    {
        return 'Legt ein easyIT-Enterprise-Projekt mit sicherer Verzeichnisstruktur und Datenquellen-Startprofil an und führt anschließend zur Datenquelle und zum DataForm.';
    }

    /** @return array<string,string> */
    private static function runtimeDriverOptions(): array
    {
        $pdo = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        $all = [
            'mysql' => ['MySQL / MariaDB', 'mysql'],
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
            new AssistantStep('identity', '1. Projekt', 'Projektname, eindeutige Projektkennung und Beschreibung festlegen.', [
                'fields' => [
                    ['name' => 'project_name', 'label' => 'Projektname', 'type' => 'text', 'required' => true],
                    ['name' => 'project_slug', 'label' => 'Projektkennung', 'type' => 'text', 'placeholder' => 'z. B. muster_csv'],
                    ['name' => 'description', 'label' => 'Beschreibung', 'type' => 'textarea', 'rows' => 4],
                ],
            ]),
            new AssistantStep('storage', '2. Datenhaltung', 'Festlegen, ob die Projektdaten in MySQL/MariaDB, PostgreSQL, SQLite, CSV oder Oracle geführt werden.', [
                'fields' => [
                    ['name' => 'driver', 'label' => 'Datenhaltung', 'type' => 'select', 'options' => self::runtimeDriverOptions()],
                ],
            ]),
            new AssistantStep('datasource', '3. Datenquellenprofil', 'Startprofil für den Datenquellen-Assistenten erzeugen. Kennwörter werden hier nicht gespeichert.', [
                'fields' => [
                    ['name' => 'profile_name', 'label' => 'Profilname', 'type' => 'text', 'required' => true, 'default' => 'main'],
                    ['name' => 'database_name', 'label' => 'Datenbankname (MySQL / PostgreSQL / MSSQL)', 'type' => 'text'],
                    ['name' => 'schema_name', 'label' => 'PostgreSQL-Schema', 'type' => 'text', 'default' => 'public'],
                ],
            ]),
            new AssistantStep('structure', '4. Projektstruktur', 'Abgeleitete Verzeichnisse und lokale Datenpfade prüfen.'),
            new AssistantStep('provision', '5. Projekt anlegen', 'Projektstruktur nach vollständiger Prüfung tatsächlich im Ordner projects/ erzeugen.', [
                'fields' => [
                    ['name' => 'confirm_create', 'label' => 'Projekt jetzt anlegen', 'type' => 'checkbox'],
                ],
            ]),
            new AssistantStep('review', '6. Initialprüfung und Übergabe', 'Erzeugten Projektstand prüfen und an den Datenquellen-Assistenten übergeben.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $steps = $this->getSteps($context);
        $ids = array_map(static fn (AssistantStep $step): string => $step->getId(), $steps);
        $stepId = $stepId ?: 'identity';
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        $scope = 'project-create';
        if ($this->boolInput($context, 'reset')) {
            $this->stateStore->clear($this->getId(), $scope);
        }
        $draft = new ProjectDraft($this->stateStore->get($this->getId(), $scope));
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));
        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $draft = $this->applyStepInput($draft, $context, $stepId);
            $validation = $this->validator->validate($draft, $stepId === 'review' ? 'review' : $stepId);
            if ($stepId === 'provision' && $validation['errors'] === []) {
                $result = $this->provisioner->provision($draft, $this->compiler);
                if ($result->isOk()) {
                    $draft = $draft->merge(['provision' => [
                        'created' => true,
                        'createdAt' => gmdate('c'),
                        'projectAbsolutePath' => $result->getProjectAbsolutePath(),
                        'createdDirectories' => $result->getDirectories(),
                        'createdFiles' => $result->getFiles(),
                        'message' => $result->getMessage(),
                    ]]);
                    $this->seedDataSourceAssistant($draft);
                } else {
                    $validation['errors'][] = $result->getMessage();
                }
            }
            $this->stateStore->put($this->getId(), $draft->toArray(), $scope);
        } elseif ($stepId === 'review') {
            $validation = $this->validator->validate($draft, 'review');
        } else {
            $validation = ['errors' => [], 'warnings' => []];
        }

        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $index => $step) {
            $state = $index < $currentIndex ? AssistantStep::STATE_COMPLETE : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING);
            $stateful[] = $step->withState($state);
        }

        $compiled = $stepId === 'review' ? $this->compiler->compile($draft) : null;
        $data = [
            'phase' => 9,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => $compiled,
            'configurationTitle' => 'Projekt-Konfiguration',
            'exportLabel' => 'Projekt-Konfiguration als JSON herunterladen',
            'provisioningPlan' => $this->provisioner->plan($draft),
            'provisioningResult' => $draft->toArray()['provision'],
            'rules' => [
                'projectRoot' => 'projects/',
                'noPlaintextPasswords' => true,
                'existingProjectsNeverOverwritten' => true,
                'flow' => ['project.create', 'datasource.configure', 'dataform.create'],
            ],
        ];

        if ($stepId === 'review' && $validation['errors'] === []) {
            $slug = (string) $draft->toArray()['identity']['slug'];
            $data['handoffUrl'] = 'run.php?' . http_build_query([
                'assistant' => 'datasource.configure',
                'step' => 'connection',
                'project_id' => $slug,
            ]);
            $data['handoffLabel'] = 'Datenquelle konfigurieren und testen';
        }

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function applyStepInput(ProjectDraft $draft, AssistantContext $context, string $stepId): ProjectDraft
    {
        $d = $draft->toArray();
        if ($stepId === 'identity') {
            $name = trim((string) $context->input('project_name', ''));
            $slug = trim((string) $context->input('project_slug', ''));
            if ($slug === '') { $slug = $this->slugify($name); }
            $draft = $draft->merge(['identity' => [
                'name' => $name,
                'slug' => $slug,
                'description' => trim((string) $context->input('description', '')),
            ]]);
            return $this->derivePaths($draft);
        }
        if ($stepId === 'storage') {
            $driver = strtolower(trim((string) $context->input('driver', 'mysql')));
            $draft = $draft->merge(['storage' => ['driver' => $driver], 'dataSource' => ['driver' => $driver]]);
            return $this->derivePaths($draft);
        }
        if ($stepId === 'datasource') {
            $database = trim((string) $context->input('database_name', ''));
            if ($database === '' && in_array(($d['storage']['driver'] ?? ''), ['mysql','pgsql','mssql'], true)) { $database = (string) ($d['identity']['slug'] ?? ''); }
            $schema = ($d['storage']['driver'] ?? '') === 'pgsql' ? (trim((string)$context->input('schema_name', 'public')) ?: 'public') : '';
            return $this->derivePaths($draft->merge(['dataSource' => [
                'profileName' => trim((string) $context->input('profile_name', 'main')) ?: 'main',
                'databaseName' => $database,
                'schemaName' => $schema,
            ]]));
        }
        if ($stepId === 'provision') {
            return $draft->merge(['provision' => ['confirmCreate' => $this->boolInput($context, 'confirm_create')]]);
        }
        return $this->derivePaths($draft);
    }

    private function derivePaths(ProjectDraft $draft): ProjectDraft
    {
        $d = $draft->toArray();
        $slug = trim((string) ($d['identity']['slug'] ?? ''));
        $driver = strtolower((string) ($d['storage']['driver'] ?? 'mysql'));
        $projectPath = $slug !== '' ? 'projects/' . $slug : '';
        $dataMode = in_array($driver, ['sqlite', 'csv'], true) ? 'local' : 'external';
        $localPath = match ($driver) {
            'sqlite' => $projectPath !== '' ? $projectPath . '/data/' . $slug . '.sqlite' : '',
            'csv' => $projectPath !== '' ? $projectPath . '/data/csv/' . $slug : '',
            default => '',
        };
        $database = (string) ($d['dataSource']['databaseName'] ?? '');
        if (in_array($driver, ['mysql','pgsql','mssql'], true) && $database === '') { $database = $slug; }
        $schema = $driver === 'pgsql' ? (trim((string)($d['dataSource']['schemaName'] ?? 'public')) ?: 'public') : '';
        return $draft->merge([
            'storage' => ['projectPath' => $projectPath, 'dataMode' => $dataMode],
            'dataSource' => [
                'driver' => $driver,
                'databaseName' => $database,
                'schemaName' => $schema,
                'localPath' => $localPath,
                'requiresConnectionConfiguration' => in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true),
            ],
        ]);
    }

    private function seedDataSourceAssistant(ProjectDraft $draft): void
    {
        $d = $draft->toArray();
        $slug = (string) $d['identity']['slug'];
        $seed = $this->provisioner->buildDataSourceSeed($draft);
        $this->stateStore->put('datasource.configure', $seed, $slug);
    }

    private function formValues(ProjectDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        return match ($stepId) {
            'identity' => [
                'project_name' => $d['identity']['name'], 'project_slug' => $d['identity']['slug'], 'description' => $d['identity']['description'],
            ],
            'storage' => ['driver' => $d['storage']['driver']],
            'datasource' => ['profile_name' => $d['dataSource']['profileName'], 'database_name' => $d['dataSource']['databaseName'], 'schema_name' => $d['dataSource']['schemaName'] ?? 'public'],
            'provision' => ['confirm_create' => $d['provision']['confirmCreate']],
            default => [],
        };
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted)) { $value = $converted; }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-_');
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        return in_array($context->input($key, false), [true, 1, '1', 'true', 'yes', 'on'], true);
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
