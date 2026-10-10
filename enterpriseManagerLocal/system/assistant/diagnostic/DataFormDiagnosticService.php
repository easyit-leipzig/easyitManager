<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Diagnostic;

use EasyIT\Assistant\Action\ActionDraft;
use EasyIT\Assistant\Action\ActionDraftValidator;
use EasyIT\Assistant\Action\DataFormActionRegistry;
use EasyIT\Assistant\DataForm\DataFormDraft;
use EasyIT\Assistant\DataForm\DataFormDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceDraft;
use EasyIT\Assistant\DataSource\DataSourceDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceRegistry;
use EasyIT\Assistant\Event\DataFormActionContextBuilder;
use EasyIT\Assistant\Event\EventDraft;
use EasyIT\Assistant\Event\EventDraftValidator;
use EasyIT\Assistant\Field\FieldDraft;
use EasyIT\Assistant\Field\FieldDraftValidator;
use EasyIT\Assistant\Field\FieldTypeRegistry;
use EasyIT\Assistant\Relation\BoundValueResolver;
use EasyIT\Assistant\Relation\RelationConfigCompiler;
use EasyIT\Assistant\Relation\RelationDraft;
use EasyIT\Assistant\Relation\RelationDraftValidator;
use EasyIT\Assistant\State\AssistantStateStore;

final class DataFormDiagnosticService
{
    public function __construct(
        private AssistantStateStore $store,
        private DataSourceRegistry $dataSources,
        private DataFormActionRegistry $actions,
        private FieldTypeRegistry $fieldTypes
    ) {}

    public function diagnose(string $projectId, string $dataFormId, array $options = []): DiagnosticReport
    {
        $projectScope = $projectId !== '' ? $projectId : 'global';
        $dataFormState = [];
        if ($dataFormId !== '') {
            $dataFormState = $this->store->get('dataform.create', $projectScope . '|' . $dataFormId);
        }
        if ($dataFormState === []) {
            $legacy = $this->store->get('dataform.create', $projectScope);
            $legacyId = trim((string) ($legacy['identity']['dataFormName'] ?? ''));
            if ($dataFormId === '' || $legacyId === $dataFormId) { $dataFormState = $legacy; }
        }
        if ($dataFormId === '') {
            $dataFormId = trim((string) ($dataFormState['identity']['dataFormName'] ?? '')) ?: 'dataform';
        }
        $scoped = $projectScope . '|' . $dataFormId;
        $report = new DiagnosticReport($projectScope, $dataFormId);

        $this->checkDataForm($report, $dataFormState);
        $this->checkFields($report, $this->store->get('dataform.fields', $scoped), $dataFormState, $dataFormId);
        $this->checkDataSource($report, $this->store->get('datasource.configure', $projectScope), $dataFormState, !empty($options['runtimeDataSource']));
        $this->checkCrudAndActions($report, $dataFormState, $this->store->get('dataform.actions', $scoped), $dataFormId);
        $this->checkRelations($report, $this->store->get('dataform.relations', $scoped), $options['sampleParentValue'] ?? 42);
        $this->checkEvents($report, $this->store->get('dataform.events', $scoped), $projectScope, $dataFormId);
        $this->checkButtonRegistry($report);

        return $report;
    }

    private function checkDataForm(DiagnosticReport $report, array $state): void
    {
        if ($state === []) {
            $report->add(new DiagnosticCheck('dataform.state', 'DataForm', 'DataForm-Entwurf vorhanden', DiagnosticCheck::FAIL,
                'Für dieses Projekt wurde kein DataForm-Entwurf gefunden.', [], 'DataForm-Assistent ausführen und den Entwurf speichern.'));
            return;
        }
        $draft = new DataFormDraft($state);
        $validation = (new DataFormDraftValidator())->validate($draft, null);
        $this->validationCheck($report, 'dataform.validation', 'DataForm', 'DataForm-Konfiguration', $validation);
        $d = $draft->toArray();
        $pagination = $d['features']['pagination'] ?? [];
        $ok = ($pagination['enabled'] ?? false) === true
            && ($pagination['position'] ?? '') === 'below-records'
            && (int) ($pagination['windowLeft'] ?? 0) === 2
            && (int) ($pagination['windowRight'] ?? 0) === 2
            && ($pagination['showFirst'] ?? false) === true
            && ($pagination['showLast'] ?? false) === true;
        $report->add(new DiagnosticCheck('dataform.pagination', 'DataForm', 'Pagination', $ok ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
            $ok ? 'Pagination entspricht der DataForm5-Vorgabe.' : 'Pagination verletzt die verbindliche DataForm5-Vorgabe.',
            ['position' => $pagination['position'] ?? null, 'windowLeft' => $pagination['windowLeft'] ?? null, 'windowRight' => $pagination['windowRight'] ?? null, 'showFirst' => $pagination['showFirst'] ?? null, 'showLast' => $pagination['showLast'] ?? null],
            $ok ? null : 'Pagination unter den Datensätzen mit erster Seite, ±2 Seiten und letzter Seite konfigurieren.'));
        $report->add(new DiagnosticCheck('dataform.search-filter', 'DataForm', 'Suche und Filter', DiagnosticCheck::PASS,
            'Volltextsuche und Filter sind als explizite Ja/Nein-Eigenschaften vorhanden.',
            ['fullTextSearch' => (bool) ($d['features']['fullTextSearch'] ?? false), 'filter' => (bool) ($d['features']['filter'] ?? false)]));
    }

    private function checkFields(DiagnosticReport $report, array $fieldState, array $dataFormState, string $dataFormId): void
    {
        $baseFields = is_array($dataFormState['fields'] ?? null) ? $dataFormState['fields'] : [];
        if ($fieldState === []) {
            $special = array_values(array_filter($baseFields, static fn(array $f): bool => in_array((string) ($f['type'] ?? ''), ['lookup', 'derived_enum'], true)));
            if ($special !== []) {
                $report->add(new DiagnosticCheck('fields.enriched', 'Felder', 'Lookup/Enum-Feldkonfiguration', DiagnosticCheck::FAIL,
                    'Lookup- oder Derived-Enum-Felder sind vorhanden, aber der Feld-Assistent wurde nicht vollständig gespeichert.',
                    ['fields' => array_map(static fn(array $f): string => (string) ($f['name'] ?? ''), $special)],
                    'Feld-Assistent ausführen und Lookup-/Derived-Enum-Quellen definieren.'));
            } else {
                $report->add(new DiagnosticCheck('fields.enriched', 'Felder', 'Erweiterte Feldkonfiguration', DiagnosticCheck::SKIP,
                    'Keine separate Feld-Assistent-Konfiguration vorhanden; für einfache Felder ist dies zulässig.'));
            }
            return;
        }
        $draft = new FieldDraft($fieldState);
        $validation = (new FieldDraftValidator($this->fieldTypes))->validate($draft, null);
        $this->validationCheck($report, 'fields.validation', 'Felder', 'Feld-, Lookup- und Enum-Konfiguration', $validation);
        $d = $draft->toArray();
        $report->add(new DiagnosticCheck('fields.summary', 'Felder', 'Feldbestand', DiagnosticCheck::PASS,
            'Feldkonfiguration wurde geladen.', ['dataForm' => $d['context']['dataForm'] ?? $dataFormId, 'fields' => count($d['fields'] ?? []), 'lookups' => count($d['lookups'] ?? []), 'derivedEnums' => count($d['derivedEnums'] ?? [])]));
    }

    private function checkDataSource(DiagnosticReport $report, array $state, array $dataFormState, bool $runtime): void
    {
        if ($state === []) {
            $report->add(new DiagnosticCheck('datasource.state', 'Datenquelle', 'Datenquellenprofil', DiagnosticCheck::FAIL,
                'Kein Datenquellenprofil gefunden.', [], 'Datenquellen-Assistent ausführen.'));
            return;
        }
        $draft = new DataSourceDraft($state);
        $validation = (new DataSourceDraftValidator($this->dataSources))->validate($draft, null);
        $this->validationCheck($report, 'datasource.validation', 'Datenquelle', 'Datenquellen-Konfiguration', $validation);
        $d = $draft->toArray();
        $driver = strtolower((string) ($d['profile']['driver'] ?? ''));
        $source = (string) (($dataFormState['source']['name'] ?? '') !== '' ? $dataFormState['source']['name'] : ($d['selection']['sourceName'] ?? ''));
        $discovered = $d['discovery'] ?? [];
        $found = null;
        foreach ($discovered as $candidate) {
            if ((string) ($candidate['name'] ?? '') === $source) { $found = $candidate; break; }
        }
        if ($source === '') {
            $report->add(new DiagnosticCheck('datasource.source', 'Datenquelle', 'Hauptquelle vorhanden', DiagnosticCheck::FAIL, 'Keine Haupttabelle/View ausgewählt.'));
        } elseif ($found === null) {
            $report->add(new DiagnosticCheck('datasource.source', 'Datenquelle', 'Hauptquelle erkannt', DiagnosticCheck::WARN,
                'Die konfigurierte Hauptquelle ist nicht in der zuletzt gespeicherten Discovery-Liste enthalten.', ['source' => $source], 'Verbindungstest/Discovery erneut ausführen.'));
        } elseif (($found['meta']['valid'] ?? true) === false) {
            $report->add(new DiagnosticCheck('datasource.source', 'Datenquelle', 'Hauptquelle gültig', DiagnosticCheck::FAIL,
                'Die Hauptquelle wurde erkannt, ist aber für DataForm5 ungültig.', ['source' => $source, 'meta' => $found['meta'] ?? []]));
        } else {
            $report->add(new DiagnosticCheck('datasource.source', 'Datenquelle', 'Hauptquelle gültig', DiagnosticCheck::PASS,
                'Die konfigurierte Hauptquelle ist in der Discovery-Liste vorhanden.', ['source' => $source, 'driver' => $driver]));
        }

        if (!$runtime) {
            $report->add(new DiagnosticCheck('datasource.runtime', 'Datenquelle', 'Aktueller Verbindungstest', DiagnosticCheck::SKIP,
                'Erneuter Laufzeit-Verbindungstest wurde nicht angefordert.', ['lastTest' => $d['test'] ?? []]));
            return;
        }
        if (!$this->dataSources->has($driver)) {
            $report->add(new DiagnosticCheck('datasource.runtime', 'Datenquelle', 'Aktueller Verbindungstest', DiagnosticCheck::FAIL, 'Kein Adapter für Treiber ' . $driver . ' registriert.'));
            return;
        }
        $runtimeConfig = $d['connection'] ?? [];
        if (in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true)) {
            $ref = trim((string) ($runtimeConfig['passwordRef'] ?? ''));
            if ($ref !== '') {
                $secret = getenv($ref);
                if ($secret !== false) { $runtimeConfig['password'] = (string) $secret; }
            }
        }
        try {
            $test = $this->dataSources->get($driver)->test($runtimeConfig);
            $report->add(new DiagnosticCheck('datasource.runtime', 'Datenquelle', 'Aktueller Verbindungstest', $test->isOk() ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
                $test->getMessage(), $test->getDetails(), $test->isOk() ? null : 'Datenquellenprofil und Serverzugriff prüfen.'));
        } catch (\Throwable $e) {
            $report->add(new DiagnosticCheck('datasource.runtime', 'Datenquelle', 'Aktueller Verbindungstest', DiagnosticCheck::FAIL,
                'Verbindungstest warf einen Fehler: ' . $e->getMessage()));
        }
    }

    private function checkCrudAndActions(DiagnosticReport $report, array $dataFormState, array $actionState, string $dataFormId): void
    {
        if ($dataFormState === []) {
            $report->add(new DiagnosticCheck('crud.configuration', 'CRUD', 'CRUD-Konfiguration', DiagnosticCheck::SKIP, 'Ohne DataForm-Entwurf kann CRUD nicht geprüft werden.'));
            return;
        }
        $crud = $dataFormState['crud'] ?? [];
        $map = ['create' => 'new', 'show' => 'show', 'edit' => 'edit', 'delete' => 'delete', 'save' => 'save'];
        $missing = [];
        foreach ($map as $crudKey => $actionId) {
            if (!empty($crud[$crudKey]) && !$this->actions->has($actionId)) { $missing[] = $actionId; }
        }
        $report->add(new DiagnosticCheck('crud.registry', 'CRUD', 'CRUD-Aktionen registriert', $missing === [] ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
            $missing === [] ? 'Alle aktivierten CRUD-Funktionen besitzen eine zentrale Aktion.' : 'Für aktivierte CRUD-Funktionen fehlen Registry-Aktionen.', ['missingActions' => $missing, 'crud' => $crud]));

        if ($actionState === []) {
            $report->add(new DiagnosticCheck('crud.actions-state', 'CRUD', 'Aktions-Assistent', DiagnosticCheck::WARN,
                'Keine gespeicherte Aktionskonfiguration gefunden; die zentrale Registry selbst ist gültig.', [], 'Aktions-Assistent für das DataForm speichern.'));
            return;
        }
        $draft = new ActionDraft($actionState);
        $validation = (new ActionDraftValidator($this->actions))->validate($draft, null);
        $this->validationCheck($report, 'crud.actions-validation', 'CRUD', 'Aktionskonfiguration', $validation);
        $actions = $draft->toArray()['actions'] ?? [];
        $mismatch = [];
        foreach ($map as $crudKey => $actionId) {
            if (!empty($crud[$crudKey]) && empty($actions[$actionId])) { $mismatch[] = $crudKey . '→' . $actionId; }
        }
        $report->add(new DiagnosticCheck('crud.crosscheck', 'CRUD', 'CRUD-/Button-Konsistenz', $mismatch === [] ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
            $mismatch === [] ? 'Aktivierte CRUD-Funktionen und Button-Aktionen stimmen überein.' : 'Aktiviertes CRUD besitzt deaktivierte Button-Aktionen.', ['mismatches' => $mismatch, 'dataForm' => $dataFormId], $mismatch === [] ? null : 'Aktions-Assistent an die CRUD-Einstellungen angleichen.'));

        $workflowIssues = [];
        foreach (['new', 'show', 'edit', 'save', 'delete'] as $actionId) {
            if (!$this->actions->has($actionId)) { $workflowIssues[] = 'missing:' . $actionId; }
        }
        if ($this->actions->has('new') && $this->actions->get('new')->requiresRecord()) { $workflowIssues[] = 'new-requires-record'; }
        foreach (['show', 'edit', 'delete'] as $actionId) {
            if ($this->actions->has($actionId) && !$this->actions->get($actionId)->requiresRecord()) { $workflowIssues[] = $actionId . '-must-require-record'; }
        }
        if ($this->actions->normalize('open') !== 'show' || $this->actions->normalize('create') !== 'new') { $workflowIssues[] = 'aliases'; }
        $report->add(new DiagnosticCheck('crud.workflow', 'CRUD', 'Nichtdestruktiver CRUD-Workflow-Test', $workflowIssues === [] && $mismatch === [] ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
            $workflowIssues === [] && $mismatch === [] ? 'new/show/edit/save/delete und ihre Datensatzanforderungen sind konsistent verdrahtet.' : 'Der CRUD-Aktionsworkflow ist inkonsistent.',
            ['issues' => $workflowIssues, 'mismatches' => $mismatch, 'destructiveDatabaseWritesExecuted' => false],
            $workflowIssues === [] && $mismatch === [] ? null : 'CRUD- und Aktionskonfiguration korrigieren.'));
    }

    private function checkRelations(DiagnosticReport $report, array $state, mixed $sampleParentValue): void
    {
        if ($state === []) {
            $report->add(new DiagnosticCheck('relation.validation', 'Beziehungen', 'Beziehungsdefinition', DiagnosticCheck::SKIP, 'Keine Beziehungskonfiguration für dieses DataForm gespeichert.'));
            return;
        }
        $draft = new RelationDraft($state);
        $validation = (new RelationDraftValidator())->validate($draft, null);
        $this->validationCheck($report, 'relation.validation', 'Beziehungen', 'Beziehungsdefinition', $validation);
        if ($validation['errors'] !== []) { return; }
        $config = (new RelationConfigCompiler())->compile($draft);
        if (($config['type'] ?? '') !== 'one_to_many') {
            $ok = trim((string) ($config['manyToMany']['junctionSource'] ?? '')) !== '';
            $report->add(new DiagnosticCheck('relation.runtime', 'Beziehungen', 'n:m-Zwischentabelle', $ok ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
                $ok ? 'n:m-Zuordnung besitzt eine Zwischentabelle.' : 'n:m-Zuordnung besitzt keine Zwischentabelle.', ['manyToMany' => $config['manyToMany'] ?? []]));
            return;
        }
        $sourceField = (string) ($config['binding']['parentValueField'] ?? 'id');
        try {
            $resolved = (new BoundValueResolver())->resolve($config, [$sourceField => $sampleParentValue]);
            $target = (string) ($config['binding']['childTargetField'] ?? '');
            $valueOk = array_key_exists($target, $resolved['values']) && $resolved['values'][$target] === $sampleParentValue;
            $readOnlyExpected = !empty($config['binding']['readOnly']);
            $readOnlyOk = !$readOnlyExpected || in_array($target, $resolved['readOnlyFields'], true);
            $status = $valueOk && $readOnlyOk ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL;
            $report->add(new DiagnosticCheck('relation.runtime', 'Beziehungen', 'Gebundener Elternwert', $status,
                $status === DiagnosticCheck::PASS ? 'Der aktuelle Elternwert wird korrekt in den neuen Kinddatensatz übernommen.' : 'Die gebundene Wertübernahme ist inkonsistent.',
                ['sampleParentValue' => $sampleParentValue, 'sourceField' => $sourceField, 'targetField' => $target, 'resolved' => $resolved, 'readOnlyConfigured' => $readOnlyExpected]));
            if (!$readOnlyExpected) {
                $report->add(new DiagnosticCheck('relation.readonly', 'Beziehungen', 'Gebundenes Kindfeld read-only', DiagnosticCheck::WARN,
                    'Das gebundene Kindfeld ist ausdrücklich editierbar konfiguriert. Standard ist read-only.', ['targetField' => $target]));
            }
        } catch (\Throwable $e) {
            $report->add(new DiagnosticCheck('relation.runtime', 'Beziehungen', 'Gebundener Elternwert', DiagnosticCheck::FAIL, $e->getMessage()));
        }
    }

    private function checkEvents(DiagnosticReport $report, array $state, string $projectId, string $dataFormId): void
    {
        if ($state === []) {
            $report->add(new DiagnosticCheck('events.validation', 'Events', 'Event-Konfiguration', DiagnosticCheck::SKIP, 'Keine Event-Konfiguration gespeichert.'));
        } else {
            $draft = new EventDraft($state);
            $validation = (new EventDraftValidator())->validate($draft, null);
            $this->validationCheck($report, 'events.validation', 'Events', 'Event-Konfiguration', $validation);
        }
        $builder = new DataFormActionContextBuilder();
        $context = $builder->build('afterSave', 'save', [
            'project' => ['id' => $projectId],
            'dataForm' => ['id' => $dataFormId, 'name' => $dataFormId, 'mode' => 'edit'],
            'recordId' => 42,
            'originalRecord' => ['id' => 42, 'name' => 'Alt'],
            'currentRecord' => ['id' => 42, 'name' => 'Neu'],
            'pagination' => ['position' => 'below-records'],
        ]);
        $ok = ($context['schema'] ?? '') === 'easyit.dataform.action-context.v1'
            && ($context['changes']['name']['before'] ?? null) === 'Alt'
            && ($context['changes']['name']['after'] ?? null) === 'Neu';
        $report->add(new DiagnosticCheck('events.context', 'Events', 'DataFormActionContext', $ok ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
            $ok ? 'Das zentrale Übergabeobjekt enthält Originalwert, aktuellen Wert und Änderung.' : 'Das zentrale Übergabeobjekt ist unvollständig.', ['sample' => $context]));
    }

    private function checkButtonRegistry(DiagnosticReport $report): void
    {
        $issues = [];
        foreach ($this->actions->all() as $id => $definition) {
            $d = $definition->jsonSerialize();
            foreach (['buttonKey', 'title', 'ariaLabel'] as $key) {
                if (trim((string) ($d[$key] ?? '')) === '') { $issues[] = $id . ':' . $key; }
            }
        }
        if ($this->actions->normalize('open') !== 'show') { $issues[] = 'open→show'; }
        if ($this->actions->normalize('create') !== 'new') { $issues[] = 'create→new'; }
        if ($this->actions->get('show')->getButtonKey() === $this->actions->get('edit')->getButtonKey()) { $issues[] = 'show/edit buttonKey'; }
        $report->add(new DiagnosticCheck('buttons.registry', 'Buttons', 'Zentrale Button-Registry', $issues === [] ? DiagnosticCheck::PASS : DiagnosticCheck::FAIL,
            $issues === [] ? 'Button-Registry, Titel, aria-label und Aliase sind konsistent.' : 'Button-Registry enthält Inkonsistenzen.', ['issues' => $issues, 'aliases' => $this->actions->aliases()]));
    }

    private function validationCheck(DiagnosticReport $report, string $id, string $group, string $title, array $validation): void
    {
        $errors = array_values($validation['errors'] ?? []);
        $warnings = array_values($validation['warnings'] ?? []);
        if ($errors !== []) {
            $report->add(new DiagnosticCheck($id, $group, $title, DiagnosticCheck::FAIL, implode(' ', $errors), ['errors' => $errors, 'warnings' => $warnings]));
        } elseif ($warnings !== []) {
            $report->add(new DiagnosticCheck($id, $group, $title, DiagnosticCheck::WARN, implode(' ', $warnings), ['warnings' => $warnings]));
        } else {
            $report->add(new DiagnosticCheck($id, $group, $title, DiagnosticCheck::PASS, 'Prüfung ohne Fehler und Warnungen bestanden.'));
        }
    }
}
