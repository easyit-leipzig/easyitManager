<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\State\AssistantStateStore;
use EasyIT\Assistant\Workflow\WorkflowDefinition;
use EasyIT\Assistant\Workflow\WorkflowProgressService;
use EasyIT\Assistant\Workflow\WorkflowPersistenceService;

final class WorkflowAssistant implements AssistantInterface
{
    private const SCOPE = 'active-standard-workflow';

    public function __construct(
        private AssistantStateStore $store,
        private WorkflowProgressService $progress,
        private WorkflowPersistenceService $persistence
    ) {}

    public function getId(): string { return WorkflowDefinition::ID; }
    public function getTitle(): string { return 'Workflow-Assistent'; }
    public function getDescription(): string
    {
        return 'Führt Projekt, Datenquelle, DataForm, Felder, Beziehungen, Events, Aktionen und Diagnose als zusammenhängenden, projektbezogen persistenten Ablauf.';
    }

    public function getSteps(AssistantContext $context): array
    {
        return array_map(
            static fn (array $step): AssistantStep => new AssistantStep($step['id'], $step['title'], $step['description']),
            WorkflowDefinition::steps()
        );
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));
        $contextProject = trim((string) ($context->getProjectId() ?? ''));
        $contextDataForm = trim((string) ($context->getDataFormId() ?? ''));

        $state = $this->store->get($this->getId(), self::SCOPE);
        if ($state === []) {
            $state = $this->persistence->load($contextProject !== '' ? $contextProject : null);
        }
        $state = $this->withDefaults($state);

        // A directly supplied project always wins. On project switch, load that project's
        // own workflow state instead of carrying optional flags over from another project.
        if ($contextProject !== '' && ($state['projectId'] ?? '') !== $contextProject) {
            $projectState = $this->persistence->load($contextProject);
            $state = $this->withDefaults($projectState !== [] ? $projectState : [
                'projectId' => $contextProject,
                'dataFormId' => $contextDataForm,
            ]);
        }
        if ($contextProject !== '') { $state['projectId'] = $contextProject; }
        if ($contextDataForm !== '') { $state['dataFormId'] = $contextDataForm; }

        if ($this->truthy($context->input('reset', false))) {
            $resetProject = trim((string) ($state['projectId'] ?? $contextProject));
            $this->store->clear($this->getId(), self::SCOPE);
            if ($resetProject !== '') { $this->persistence->clear($resetProject); }
            $state = $this->withDefaults([
                'projectId' => $contextProject,
                'dataFormId' => $contextDataForm,
            ]);
        }

        if ($method === 'POST') {
            $postedProject = trim((string) $context->input('workflow_project_id', ''));
            $postedDataForm = trim((string) $context->input('workflow_dataform_id', ''));
            if ($postedProject !== '' && $postedProject !== ($state['projectId'] ?? '')) {
                $projectState = $this->persistence->load($postedProject);
                $state = $this->withDefaults($projectState !== [] ? $projectState : ['projectId' => $postedProject]);
            }
            if ($postedProject !== '') { $state['projectId'] = $postedProject; }
            if ($postedDataForm !== '') { $state['dataFormId'] = $postedDataForm; }
            $action = (string) $context->input('workflow_action', '');
            if ($action === 'skip_relations') { $state['optional']['relationsSkipped'] = true; }
            if ($action === 'use_relations') { $state['optional']['relationsSkipped'] = false; }
            if ($action === 'skip_events') { $state['optional']['eventsSkipped'] = true; }
            if ($action === 'use_events') { $state['optional']['eventsSkipped'] = false; }
        }

        $evaluation = $this->progress->evaluate($state, (string) ($state['projectId'] ?? ''), (string) ($state['dataFormId'] ?? ''));
        $state['projectId'] = $evaluation['projectId'];
        $state['dataFormId'] = $evaluation['dataFormId'];
        $state['lastOpenStep'] = $evaluation['nextStep'];
        $state['updatedAt'] = gmdate('c');
        unset($state['_persistenceProjectId']);
        $this->store->put($this->getId(), $state, self::SCOPE);
        if ($evaluation['projectId'] !== '') {
            $this->persistence->save($evaluation['projectId'], $evaluation['dataFormId'], $state);
        }

        $requestedStep = $stepId ?: ($evaluation['nextStep'] ?: 'diagnostics');
        $validIds = array_column(WorkflowDefinition::steps(), 'id');
        if (!in_array($requestedStep, $validIds, true)) {
            $requestedStep = $evaluation['nextStep'] ?: 'project';
        }

        $assistantSteps = [];
        foreach ($this->getSteps($context) as $step) {
            $status = 'pending';
            foreach ($evaluation['steps'] as $ws) {
                if ($ws['id'] === $step->getId()) {
                    $status = $ws['status'];
                    break;
                }
            }
            $stateName = $status === 'complete'
                ? AssistantStep::STATE_COMPLETE
                : ($step->getId() === $requestedStep
                    ? AssistantStep::STATE_CURRENT
                    : ($status === 'blocked' ? AssistantStep::STATE_BLOCKED : AssistantStep::STATE_PENDING));
            $assistantSteps[] = $step->withState($stateName);
        }

        $workflowRows = [];
        foreach ($evaluation['steps'] as $ws) {
            $workflowRows[] = $ws + [
                'url' => $this->targetUrl($ws, $evaluation['projectId'], $evaluation['dataFormId'], is_array($state['assistantLastSteps'] ?? null) ? $state['assistantLastSteps'] : []),
                'available' => $ws['status'] !== 'blocked',
            ];
        }

        $data = [
            'phase' => 13,
            'workflow' => [
                'schema' => 'easyit.assistant.workflow.v1',
                'id' => 'standard',
                'projectId' => $evaluation['projectId'],
                'dataFormId' => $evaluation['dataFormId'],
                'steps' => $workflowRows,
                'completeCount' => $evaluation['completeCount'],
                'totalCount' => $evaluation['totalCount'],
                'percent' => $evaluation['percent'],
                'nextStep' => $evaluation['nextStep'],
                'allComplete' => $evaluation['allComplete'],
                'startedAt' => $state['startedAt'],
                'updatedAt' => $state['updatedAt'],
                'optional' => $state['optional'],
                'resume' => [
                    'lastAssistantId' => $state['lastAssistantId'] ?? null,
                    'lastAssistantStep' => $state['lastAssistantStep'] ?? null,
                    'assistantLastSteps' => is_array($state['assistantLastSteps'] ?? null) ? $state['assistantLastSteps'] : [],
                ],
                'persistence' => $this->persistence->info($evaluation['projectId']),
            ],
            'configurationReady' => $evaluation['allComplete'],
            'exportLabel' => 'Workflow-Status als JSON herunterladen',
        ];

        return AssistantResult::success($this->getId(), $requestedStep, $assistantSteps, $data);
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function withDefaults(array $state): array
    {
        $defaults = [
            'projectId' => '',
            'dataFormId' => '',
            'optional' => ['relationsSkipped' => false, 'eventsSkipped' => false],
            'lastOpenStep' => null,
            'assistantLastSteps' => [],
            'lastAssistantId' => null,
            'lastAssistantStep' => null,
            'startedAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
        ];
        $state = array_replace_recursive($defaults, $state);
        $state['optional'] = array_replace($defaults['optional'], is_array($state['optional'] ?? null) ? $state['optional'] : []);
        return $state;
    }

    /** @param array<string,mixed> $step */
    private function targetUrl(array $step, string $projectId, string $dataFormId, array $assistantLastSteps = []): string
    {
        $assistantId = (string) $step['assistant'];
        $resumeStep = trim((string) ($assistantLastSteps[$assistantId] ?? ''));
        $query = [
            'assistant' => $assistantId,
            'step' => $resumeStep !== '' ? $resumeStep : (string) $step['startStep'],
            'workflow' => 'standard',
            'surface' => 'assistant.center',
        ];
        if ($projectId !== '' && $step['id'] !== 'project') { $query['project_id'] = $projectId; }
        if ($dataFormId !== '' && in_array($step['id'], ['fields', 'relations', 'events', 'actions', 'diagnostics'], true)) {
            $query['dataform_id'] = $dataFormId;
        }
        return 'run.php?' . http_build_query($query);
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}
