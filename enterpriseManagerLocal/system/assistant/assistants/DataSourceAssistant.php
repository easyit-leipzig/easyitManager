<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\DataSource\DataSourceConfigCompiler;
use EasyIT\Assistant\DataSource\DataSourceDraft;
use EasyIT\Assistant\DataSource\DataSourceDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceRegistry;
use EasyIT\Assistant\State\AssistantStateStore;

final class DataSourceAssistant implements AssistantInterface
{
    public function __construct(
        private AssistantStateStore $stateStore,
        private DataSourceRegistry $registry,
        private DataSourceDraftValidator $validator,
        private DataSourceConfigCompiler $compiler
    ) {}

    public function getId(): string { return 'datasource.configure'; }
    public function getTitle(): string { return 'Datenquellen-Assistent'; }
    public function getDescription(): string
    {
        return 'Konfiguriert und testet MySQL/MariaDB, PostgreSQL, SQLite, CSV und Oracle, erkennt Tabellen/Views und erzeugt ein DataForm5-Datenquellenprofil.';
    }

    public function getSteps(AssistantContext $context): array
    {
        $draft = new DataSourceDraft($this->stateStore->get($this->getId(), $this->scope($context)));
        $d = $draft->toArray();
        $driver = strtolower((string) ($d['profile']['driver'] ?? 'mysql'));

        return [
            new AssistantStep('profile', '1. Datenquellentyp', 'Profilname und Datenquellentyp festlegen.', [
                'fields' => [
                    ['name' => 'profile_name', 'label' => 'Profilname', 'type' => 'text', 'required' => true, 'default' => 'project'],
                    ['name' => 'driver', 'label' => 'Datenquellentyp', 'type' => 'select', 'options' => $this->registry->options()],
                ],
            ]),
            new AssistantStep('connection', '2. Verbindung konfigurieren und testen', 'Verbindungsdaten eingeben. Kennwörter werden nur für den Test verwendet und nicht in der Assistentenkonfiguration gespeichert.', [
                'fields' => $this->connectionFields($driver),
            ]),
            new AssistantStep('source', '3. Tabelle / View auswählen', 'Nach erfolgreichem Verbindungstest eine erkannte Hauptquelle auswählen.', [
                'fields' => [
                    ['name' => 'source_name', 'label' => 'Tabelle / View / CSV-Tabelle', 'type' => 'select', 'options' => $this->sourceOptions($d['discovery'] ?? [])],
                ],
            ]),
            new AssistantStep('review', '4. Prüfen und übernehmen', 'Datenquellenprofil validieren, exportieren und an den DataForm-Assistenten übergeben.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $stepId = $stepId ?: 'profile';
        $scope = $this->scope($context);
        if ($this->boolInput($context, 'reset')) {
            $this->stateStore->clear($this->getId(), $scope);
        }

        $draft = new DataSourceDraft($this->stateStore->get($this->getId(), $scope));
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));
        $extraErrors = [];
        $extraWarnings = [];

        if ($method === 'POST' && !$this->boolInput($context, 'reset')) {
            $draft = $this->applyStepInput($draft, $context, $stepId);

            if ($stepId === 'connection') {
                [$draft, $testErrors, $testWarnings] = $this->testAndDiscover($draft, $context);
                $extraErrors = array_merge($extraErrors, $testErrors);
                $extraWarnings = array_merge($extraWarnings, $testWarnings);
            }

            $this->stateStore->put($this->getId(), $draft->toArray(), $scope);
        }

        $steps = $this->getSteps($context);
        $ids = array_map(static fn (AssistantStep $step): string => $step->getId(), $steps);
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        if ($method === 'POST') {
            $validation = $this->validator->validate($draft, $stepId === 'review' ? null : $stepId);
        } elseif ($stepId === 'review') {
            $validation = $this->validator->validate($draft, null);
        } else {
            $validation = ['errors' => [], 'warnings' => []];
        }
        $validation['errors'] = array_values(array_unique(array_merge($validation['errors'], $extraErrors)));
        $validation['warnings'] = array_values(array_unique(array_merge($validation['warnings'], $extraWarnings)));

        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $index => $step) {
            $state = $index < $currentIndex
                ? AssistantStep::STATE_COMPLETE
                : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING);
            $stateful[] = $step->withState($state);
        }

        $compiled = $stepId === 'review' ? $this->compiler->compile($draft) : null;
        $d = $draft->toArray();
        $data = [
            'phase' => 6,
            'draft' => $draft,
            'formValues' => $this->formValues($draft, $stepId),
            'nextStep' => $this->nextStep($ids, $stepId),
            'previousStep' => $this->previousStep($ids, $stepId),
            'configurationReady' => $stepId === 'review' && $validation['errors'] === [],
            'compiledConfig' => $compiled,
            'configurationTitle' => 'Datenquellen-Konfiguration',
            'exportLabel' => 'JSON-Datenquellenprofil herunterladen',
            'connectionTest' => $d['test'],
            'discoveredSources' => $d['discovery'],
            'rules' => [
                'supportedDrivers' => array_keys($this->registry->options()),
                'plaintextPasswordStored' => false,
                'csvDelimiterDefault' => '|',
                'csvIdFieldRequired' => 'id',
                'coreDatabaseAbstractionRequired' => true,
            ],
        ];

        if ($compiled !== null && $validation['errors'] === []) {
            $query = [
                'assistant' => 'dataform.create',
                'step' => 'source',
                'import_datasource' => '1',
            ];
            if ($context->getProjectId()) { $query['project_id'] = $context->getProjectId(); }
            $data['handoffUrl'] = 'run.php?' . http_build_query($query);
            $data['handoffLabel'] = 'Profil in DataForm-Assistent übernehmen';
        }

        if ($validation['errors'] !== []) {
            return AssistantResult::failure($this->getId(), $validation['errors'], $stepId, $stateful, $data);
        }

        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $validation['warnings']);
    }

    private function applyStepInput(DataSourceDraft $draft, AssistantContext $context, string $stepId): DataSourceDraft
    {
        if ($stepId === 'profile') {
            $driver = strtolower(trim((string) $context->input('driver', 'mysql')));
            $defaults = DataSourceDraft::defaults()['connection'];
            if ($driver === 'oracle') { $defaults['port'] = 1521; $defaults['charset'] = 'AL32UTF8'; } elseif ($driver === 'pgsql') { $defaults['port'] = 5432; $defaults['charset'] = 'UTF8'; $defaults['schema'] = 'public'; } elseif ($driver === 'mssql') { $defaults['port'] = 1433; $defaults['charset'] = 'UTF-8'; }
            return $draft->merge([
                'profile' => [
                    'name' => trim((string) $context->input('profile_name', 'project')),
                    'driver' => $driver,
                ],
                'connection' => $defaults,
                'test' => DataSourceDraft::defaults()['test'],
                'discovery' => [],
                'selection' => DataSourceDraft::defaults()['selection'],
            ]);
        }

        if ($stepId === 'connection') {
            $d = $draft->toArray();
            $driver = strtolower((string) ($d['profile']['driver'] ?? 'mysql'));
            $connection = $d['connection'];
            if (in_array($driver,['mysql','pgsql','mssql'],true)) {
                $connection = array_replace($connection, [
                    'host' => trim((string) $context->input('host', '127.0.0.1')),
                    'port' => (int) $context->input('port', $driver==='pgsql'?5432:($driver==='mssql'?1433:3306)),
                    'database' => trim((string) $context->input('database', '')),
                    'schema' => $driver==='pgsql' ? (trim((string) $context->input('schema', 'public')) ?: 'public') : (string)($connection['schema']??'public'),
                    'username' => trim((string) $context->input('username', '')),
                    'passwordRef' => trim((string) $context->input('password_ref', '')),
                    'charset' => trim((string) $context->input('charset', $driver==='pgsql'?'UTF8':($driver==='mssql'?'UTF-8':'utf8mb4'))) ?: ($driver==='pgsql'?'UTF8':($driver==='mssql'?'UTF-8':'utf8mb4')),
                    'encrypt' => $driver==='mssql' ? $this->boolInput($context, 'encrypt', true) : null,
                    'trustServerCertificate' => $driver==='mssql' ? $this->boolInput($context, 'trust_server_certificate', false) : null,
                ]);
            } elseif ($driver === 'oracle') {
                $connection = array_replace($connection, [
                    'host' => trim((string) $context->input('host', '127.0.0.1')),
                    'port' => (int) $context->input('port', 1521),
                    'service' => trim((string) $context->input('service', '')),
                    'username' => trim((string) $context->input('username', '')),
                    'passwordRef' => trim((string) $context->input('password_ref', '')),
                    'charset' => trim((string) $context->input('charset', 'AL32UTF8')) ?: 'AL32UTF8',
                ]);
            } elseif ($driver === 'sqlite') {
                $connection = array_replace($connection, [
                    'path' => trim((string) $context->input('path', '')),
                ]);
            } elseif ($driver === 'csv') {
                $connection = array_replace($connection, [
                    'path' => trim((string) $context->input('path', '')),
                    'delimiter' => (string) $context->input('delimiter', '|'),
                    'header' => true,
                ]);
            }
            return $draft->merge([
                'connection' => $connection,
                'test' => DataSourceDraft::defaults()['test'],
                'discovery' => [],
                'selection' => DataSourceDraft::defaults()['selection'],
            ]);
        }

        if ($stepId === 'source') {
            $sourceName = trim((string) $context->input('source_name', ''));
            $sourceType = '';
            foreach ($draft->toArray()['discovery'] as $source) {
                if ((string) ($source['name'] ?? '') === $sourceName) {
                    $sourceType = (string) ($source['type'] ?? 'table');
                    break;
                }
            }
            return $draft->merge(['selection' => ['sourceName' => $sourceName, 'sourceType' => $sourceType]]);
        }

        return $draft;
    }

    /** @return array{0:DataSourceDraft,1:list<string>,2:list<string>} */
    private function testAndDiscover(DataSourceDraft $draft, AssistantContext $context): array
    {
        $d = $draft->toArray();
        $driver = strtolower((string) ($d['profile']['driver'] ?? ''));
        if (!$this->registry->has($driver)) {
            return [$draft, ['Nicht unterstützter Datenquellentyp: ' . $driver], []];
        }

        $connectionValidation = $this->validator->validate($draft, 'connection');
        if ($connectionValidation['errors'] !== []) {
            return [$draft, $connectionValidation['errors'], $connectionValidation['warnings']];
        }

        $runtime = $d['connection'];
        if (in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true)) {
            $password = (string) $context->input('password', '');
            $ref = trim((string) ($runtime['passwordRef'] ?? ''));
            if ($password === '' && $ref !== '') {
                $envValue = getenv($ref);
                if ($envValue !== false) { $password = (string) $envValue; }
            }
            $runtime['password'] = $password;
        }

        $adapter = $this->registry->get($driver);
        $test = $adapter->test($runtime);
        if (!$test->isOk()) {
            $draft = $draft->merge(['test' => [
                'ok' => false,
                'message' => $test->getMessage(),
                'details' => $test->getDetails(),
                'warnings' => $test->getWarnings(),
                'testedAt' => gmdate('c'),
            ], 'discovery' => []]);
            return [$draft, [$test->getMessage()], $test->getWarnings()];
        }

        try {
            $sources = $adapter->discover($runtime);
        } catch (\Throwable $e) {
            $draft = $draft->merge(['test' => [
                'ok' => false,
                'message' => 'Verbindung erfolgreich, Quellenerkennung fehlgeschlagen: ' . $e->getMessage(),
                'details' => $test->getDetails(),
                'warnings' => $test->getWarnings(),
                'testedAt' => gmdate('c'),
            ], 'discovery' => []]);
            return [$draft, ['Quellenerkennung fehlgeschlagen: ' . $e->getMessage()], $test->getWarnings()];
        }

        $warnings = $test->getWarnings();
        if ($sources === []) {
            $warnings[] = 'Verbindung erfolgreich, aber keine Tabellen/Views wurden erkannt.';
        }
        $draft = $draft->merge([
            'test' => [
                'ok' => true,
                'message' => $test->getMessage(),
                'details' => $test->getDetails(),
                'warnings' => $warnings,
                'testedAt' => gmdate('c'),
            ],
            'discovery' => $sources,
        ]);
        return [$draft, [], $warnings];
    }

    private function connectionFields(string $driver): array
    {
        return match ($driver) {
            'sqlite' => [
                ['name' => 'path', 'label' => 'SQLite-Datei', 'type' => 'text', 'required' => true],
            ],
            'csv' => [
                ['name' => 'path', 'label' => 'CSV-Datenbankordner', 'type' => 'text', 'required' => true],
                ['name' => 'delimiter', 'label' => 'Trennzeichen', 'type' => 'text', 'required' => true, 'default' => '|'],
            ],
            'pgsql' => [
                ['name' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'default' => 5432],
                ['name' => 'database', 'label' => 'Datenbank', 'type' => 'text', 'required' => true],
                ['name' => 'schema', 'label' => 'Schema', 'type' => 'text', 'required' => true, 'default' => 'public'],
                ['name' => 'username', 'label' => 'Benutzername', 'type' => 'text'],
                ['name' => 'password', 'label' => 'Kennwort (nur Verbindungstest)', 'type' => 'password'],
                ['name' => 'password_ref', 'label' => 'Kennwort-Referenz / ENV-Name', 'type' => 'text'],
                ['name' => 'charset', 'label' => 'Zeichensatz', 'type' => 'text', 'default' => 'UTF8'],
            ],
            'oracle' => [
                ['name' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'default' => 1521],
                ['name' => 'service', 'label' => 'Service Name', 'type' => 'text', 'required' => true],
                ['name' => 'username', 'label' => 'Benutzername', 'type' => 'text'],
                ['name' => 'password', 'label' => 'Kennwort (nur Verbindungstest)', 'type' => 'password'],
                ['name' => 'password_ref', 'label' => 'Kennwort-Referenz / ENV-Name', 'type' => 'text'],
                ['name' => 'charset', 'label' => 'Zeichensatz', 'type' => 'text', 'default' => 'AL32UTF8'],
            ],
            'mssql' => [
                ['name' => 'host', 'label' => 'Server/Host', 'type' => 'text', 'required' => true, 'default' => '127.0.0.1'],
                ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'default' => 1433],
                ['name' => 'database', 'label' => 'Datenbank', 'type' => 'text', 'required' => true],
                ['name' => 'username', 'label' => 'Benutzer', 'type' => 'text', 'required' => true],
                ['name' => 'password_ref', 'label' => 'Passwort-Referenz/ENV', 'type' => 'text'],
                ['name' => 'charset', 'label' => 'Zeichensatz', 'type' => 'text', 'default' => 'UTF-8'],
                ['name' => 'encrypt', 'label' => 'TLS-Verschlüsselung', 'type' => 'checkbox', 'default' => true],
                ['name' => 'trust_server_certificate', 'label' => 'Serverzertifikat ungeprüft vertrauen', 'type' => 'checkbox', 'default' => false],
            ],
            default => [
                ['name' => 'host', 'label' => 'Host', 'type' => 'text', 'required' => true],
                ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'min' => 1, 'max' => 65535, 'default' => 3306],
                ['name' => 'database', 'label' => 'Datenbank', 'type' => 'text', 'required' => true],
                ['name' => 'username', 'label' => 'Benutzername', 'type' => 'text'],
                ['name' => 'password', 'label' => 'Kennwort (nur Verbindungstest)', 'type' => 'password'],
                ['name' => 'password_ref', 'label' => 'Kennwort-Referenz / ENV-Name', 'type' => 'text'],
                ['name' => 'charset', 'label' => 'Zeichensatz', 'type' => 'text', 'default' => 'utf8mb4'],
            ],
        };
    }

    /** @return array<string,string> */
    private function sourceOptions(array $sources): array
    {
        $out = ['' => '– bitte auswählen –'];
        foreach ($sources as $source) {
            $out[(string) ($source['name'] ?? '')] = (string) ($source['label'] ?? $source['name'] ?? '');
        }
        return $out;
    }

    private function formValues(DataSourceDraft $draft, string $stepId): array
    {
        $d = $draft->toArray();
        $c = $d['connection'];
        return match ($stepId) {
            'profile' => [
                'profile_name' => $d['profile']['name'],
                'driver' => $d['profile']['driver'],
            ],
            'connection' => [
                'host' => $c['host'], 'port' => $c['port'], 'database' => $c['database'], 'schema' => $c['schema'] ?? 'public', 'username' => $c['username'],
                'password' => '', 'password_ref' => $c['passwordRef'], 'charset' => $c['charset'], 'path' => $c['path'],
                'service' => $c['service'], 'delimiter' => $c['delimiter'],
            ],
            'source' => ['source_name' => $d['selection']['sourceName']],
            default => [],
        };
    }

    private function boolInput(AssistantContext $context, string $key): bool
    {
        return in_array($context->input($key, false), [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function scope(AssistantContext $context): string { return $context->getProjectId() ?: 'global'; }

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
