<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Workflow;

use EasyIT\Assistant\Action\ActionDraft;
use EasyIT\Assistant\Action\ActionDraftValidator;
use EasyIT\Assistant\Action\DataFormActionRegistry;
use EasyIT\Assistant\DataForm\DataFormDraft;
use EasyIT\Assistant\DataForm\DataFormDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceDraft;
use EasyIT\Assistant\DataSource\DataSourceDraftValidator;
use EasyIT\Assistant\DataSource\DataSourceRegistry;
use EasyIT\Assistant\Diagnostic\DataFormDiagnosticService;
use EasyIT\Assistant\Event\EventDraft;
use EasyIT\Assistant\Event\EventDraftValidator;
use EasyIT\Assistant\Field\FieldDraft;
use EasyIT\Assistant\Field\FieldDraftValidator;
use EasyIT\Assistant\Field\FieldTypeRegistry;
use EasyIT\Assistant\Project\ProjectDraft;
use EasyIT\Assistant\Project\ProjectDraftValidator;
use EasyIT\Assistant\Relation\RelationDraft;
use EasyIT\Assistant\Relation\RelationDraftValidator;
use EasyIT\Assistant\State\AssistantStateStore;

final class WorkflowProgressService
{
    public function __construct(
        private AssistantStateStore $store,
        private string $projectRoot,
        private DataSourceRegistry $dataSources,
        private DataFormActionRegistry $actions,
        private FieldTypeRegistry $fieldTypes,
        private DataFormDiagnosticService $diagnostics
    ) {}

    /**
     * @param array<string,mixed> $workflowState
     * @return array{projectId:string,dataFormId:string,steps:list<array<string,mixed>>,completeCount:int,totalCount:int,percent:int,nextStep:?string,allComplete:bool}
     */
    public function evaluate(array $workflowState, ?string $contextProject = null, ?string $contextDataForm = null): array
    {
        $projectId = trim((string) ($contextProject ?: ($workflowState['projectId'] ?? '')));
        $dataFormId = trim((string) ($contextDataForm ?: ($workflowState['dataFormId'] ?? '')));

        if ($projectId === '') {
            $projectDraft = new ProjectDraft($this->store->get('project.create', 'project-create'));
            $pd = $projectDraft->toArray();
            if (!empty($pd['provision']['created'])) {
                $projectId = trim((string) ($pd['identity']['slug'] ?? ''));
            }
        }

        if ($dataFormId === '' && $projectId !== '') {
            $dfState = $this->store->get('dataform.create', $projectId);
            $dataFormId = trim((string) ($dfState['identity']['dataFormName'] ?? ''));
        }

        $optional = is_array($workflowState['optional'] ?? null) ? $workflowState['optional'] : [];
        $steps = [];
        $previousComplete = true;
        foreach (WorkflowDefinition::steps() as $definition) {
            $assessment = $this->assess($definition['id'], $projectId, $dataFormId, $optional);
            if (!$previousComplete && $assessment['status'] !== 'complete') {
                $assessment['status'] = 'blocked';
                $assessment['message'] = 'Vorheriger Workflow-Schritt ist noch nicht abgeschlossen.';
            }
            $steps[] = array_merge($definition, $assessment);
            $previousComplete = $previousComplete && $assessment['status'] === 'complete';
        }

        $completeCount = count(array_filter($steps, static fn (array $s): bool => $s['status'] === 'complete'));
        $totalCount = count($steps);
        $next = null;
        foreach ($steps as $step) {
            if ($step['status'] !== 'complete') {
                $next = $step['id'];
                break;
            }
        }

        return [
            'projectId' => $projectId,
            'dataFormId' => $dataFormId,
            'steps' => $steps,
            'completeCount' => $completeCount,
            'totalCount' => $totalCount,
            'percent' => $totalCount > 0 ? (int) floor(($completeCount / $totalCount) * 100) : 0,
            'nextStep' => $next,
            'allComplete' => $completeCount === $totalCount,
        ];
    }

    /** @return array{status:string,message:string,details:array<string,mixed>} */
    private function assess(string $stepId, string $projectId, string $dataFormId, array $optional): array
    {
        if ($stepId === 'project') {
            if ($projectId === '') {
                return $this->pending('Noch kein Projekt ausgewählt oder angelegt.');
            }
            $path = $this->projectRoot . '/projects/' . $projectId;
            if (is_dir($path) && is_file($path . '/config/project.json')) {
                return $this->complete('Projekt ist vorhanden und besitzt eine Projektkonfiguration.', ['projectPath' => $path]);
            }
            $draft = new ProjectDraft($this->store->get('project.create', 'project-create'));
            $validation = (new ProjectDraftValidator())->validate($draft, 'review');
            if ($validation['errors'] === [] && (string) ($draft->toArray()['identity']['slug'] ?? '') === $projectId) {
                return $this->complete('Projekt-Assistent wurde vollständig abgeschlossen.');
            }
            return $this->pending('Projektkonfiguration ist noch nicht vollständig.', ['errors' => $validation['errors']]);
        }

        if ($projectId === '') {
            return $this->blocked('Projektkontext fehlt.');
        }

        if ($stepId === 'datasource') {
            $draft = new DataSourceDraft($this->store->get('datasource.configure', $projectId));
            $validation = (new DataSourceDraftValidator($this->dataSources))->validate($draft, null);
            $d = $draft->toArray();
            if ($validation['errors'] === [] && !empty($d['test']['ok']) && trim((string) ($d['selection']['sourceName'] ?? '')) !== '') {
                return $this->complete('Datenquelle wurde erfolgreich getestet und eine Hauptquelle ausgewählt.', ['source' => $d['selection']['sourceName']]);
            }
            return $this->pending('Datenquelle muss getestet und eine Hauptquelle ausgewählt werden.', ['errors' => $validation['errors']]);
        }

        if ($stepId === 'dataform') {
            $draft = new DataFormDraft($this->store->get('dataform.create', $projectId));
            $validation = (new DataFormDraftValidator())->validate($draft, null);
            $name = trim((string) ($draft->toArray()['identity']['dataFormName'] ?? ''));
            if ($validation['errors'] === [] && $name !== '' && ($dataFormId === '' || $name === $dataFormId)) {
                return $this->complete('DataForm-Grundkonfiguration ist vollständig validiert.', ['dataForm' => $name]);
            }
            return $this->pending('DataForm-Grundkonfiguration ist noch unvollständig.', ['errors' => $validation['errors']]);
        }

        if ($dataFormId === '') {
            return $this->blocked('DataForm-Kontext fehlt.');
        }
        $scope = $projectId . '|' . $dataFormId;

        if ($stepId === 'fields') {
            $raw = $this->store->get('dataform.fields', $scope);
            if ($raw === []) {
                return $this->pending('Feld-Assistent wurde für dieses DataForm noch nicht ausgeführt.');
            }
            $draft = new FieldDraft($raw);
            $validation = (new FieldDraftValidator($this->fieldTypes))->validate($draft, null);
            if ($validation['errors'] === []) {
                return $this->complete('Feld-, Lookup- und Enum-Konfiguration ist validiert.', ['warnings' => $validation['warnings']]);
            }
            return $this->pending('Feldkonfiguration enthält noch Fehler.', ['errors' => $validation['errors']]);
        }

        if ($stepId === 'relations') {
            if (!empty($optional['relationsSkipped'])) {
                return $this->complete('Für dieses DataForm wurden Beziehungen ausdrücklich als nicht benötigt bestätigt.', ['skipped' => true]);
            }
            $raw = $this->store->get('dataform.relations', $scope);
            if ($raw === []) {
                return $this->pending('Noch keine Beziehung konfiguriert. Falls keine benötigt wird, kann der Schritt ausdrücklich übersprungen werden.');
            }
            $validation = (new RelationDraftValidator())->validate(new RelationDraft($raw), null);
            if ($validation['errors'] === []) {
                return $this->complete('Beziehungskonfiguration ist validiert.', ['warnings' => $validation['warnings']]);
            }
            return $this->pending('Beziehungskonfiguration enthält noch Fehler.', ['errors' => $validation['errors']]);
        }

        if ($stepId === 'events') {
            if (!empty($optional['eventsSkipped'])) {
                return $this->complete('Für dieses DataForm wurden Events ausdrücklich als nicht benötigt bestätigt.', ['skipped' => true]);
            }
            $raw = $this->store->get('dataform.events', $scope);
            if ($raw === []) {
                return $this->pending('Noch keine Event-Konfiguration geprüft. Falls keine Events benötigt werden, kann der Schritt ausdrücklich übersprungen werden.');
            }
            $validation = (new EventDraftValidator())->validate(new EventDraft($raw), null);
            if ($validation['errors'] === []) {
                return $this->complete('Event-Konfiguration ist technisch valide.', ['warnings' => $validation['warnings']]);
            }
            return $this->pending('Event-Konfiguration enthält noch Fehler.', ['errors' => $validation['errors']]);
        }

        if ($stepId === 'actions') {
            $raw = $this->store->get('dataform.actions', $scope);
            if ($raw === []) {
                return $this->pending('Aktions-Assistent wurde für dieses DataForm noch nicht ausgeführt.');
            }
            $validation = (new ActionDraftValidator($this->actions))->validate(new ActionDraft($raw), null);
            if ($validation['errors'] === []) {
                return $this->complete('Aktions- und Button-Konfiguration ist validiert.', ['warnings' => $validation['warnings']]);
            }
            return $this->pending('Aktionskonfiguration enthält noch Fehler.', ['errors' => $validation['errors']]);
        }

        if ($stepId === 'diagnostics') {
            $diagState = $this->store->get('dataform.diagnostics', $projectId);
            if (trim((string) ($diagState['dataForm'] ?? '')) === '') {
                return $this->pending('Gesamtdiagnose wurde noch nicht ausgeführt.');
            }
            try {
                $report = $this->diagnostics->diagnose($projectId, $dataFormId, $diagState + ['runtimeDataSource' => false, 'sampleParentValue' => 42]);
                if (!$report->hasFailures()) {
                    return $this->complete('Gesamtdiagnose ist ohne FAIL abgeschlossen.', ['verdict' => $report->verdict(), 'counts' => $report->counts()]);
                }
                return $this->pending('Gesamtdiagnose enthält mindestens einen FAIL.', ['verdict' => $report->verdict(), 'counts' => $report->counts()]);
            } catch (\Throwable $e) {
                return $this->pending('Gesamtdiagnose konnte noch nicht erfolgreich ausgewertet werden.', ['error' => $e->getMessage()]);
            }
        }

        return $this->pending('Workflow-Schritt ist noch nicht geprüft.');
    }

    private function complete(string $message, array $details = []): array { return ['status' => 'complete', 'message' => $message, 'details' => $details]; }
    private function pending(string $message, array $details = []): array { return ['status' => 'pending', 'message' => $message, 'details' => $details]; }
    private function blocked(string $message, array $details = []): array { return ['status' => 'blocked', 'message' => $message, 'details' => $details]; }
}
