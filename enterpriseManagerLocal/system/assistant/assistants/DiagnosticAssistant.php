<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Diagnostic\DataFormDiagnosticService;
use EasyIT\Assistant\State\AssistantStateStore;

final class DiagnosticAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store, private DataFormDiagnosticService $service) {}

    public function getId(): string { return 'dataform.diagnostics'; }
    public function getTitle(): string { return 'Test- und Diagnose-Assistent'; }
    public function getDescription(): string
    {
        return 'Prüft DataForm, Datenquelle, CRUD, Beziehungen, gebundene Werte, Events, Felder und zentrale Buttons mit PASS/FAIL/WARN/SKIP.';
    }

    public function getSteps(AssistantContext $context): array
    {
        return [
            new AssistantStep('context', '1. Testumfang', 'DataForm und Testoptionen festlegen.', [
                'fields' => [
                    ['name' => 'dataform_name', 'label' => 'DataForm', 'type' => 'text', 'required' => true],
                    ['name' => 'runtime_datasource', 'label' => 'Datenquelle jetzt erneut real testen', 'type' => 'checkbox'],
                    ['name' => 'sample_parent_value', 'label' => 'Testwert für Eltern-ID', 'type' => 'text', 'default' => '42'],
                ],
            ]),
            new AssistantStep('report', '2. Diagnosebericht', 'Alle Prüfungen ausführen und detaillierten Bericht erzeugen.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $stepId = $stepId ?: 'context';
        $scope = $context->getProjectId() ?: 'global';
        if ($this->truthy($context->input('reset', false))) { $this->store->clear($this->getId(), $scope); }
        $state = $this->store->get($this->getId(), $scope);
        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));

        if ($method === 'POST' && $stepId === 'context') {
            $state = [
                'dataForm' => trim((string) $context->input('dataform_name', $context->getDataFormId() ?? '')),
                'runtimeDataSource' => $this->truthy($context->input('runtime_datasource', false)),
                'sampleParentValue' => $this->scalar((string) $context->input('sample_parent_value', '42')),
            ];
            $this->store->put($this->getId(), $state, $scope);
        }
        if (($state['dataForm'] ?? '') === '') {
            $dfState = $this->store->get('dataform.create', $scope);
            $state['dataForm'] = trim((string) ($dfState['identity']['dataFormName'] ?? ($context->getDataFormId() ?? '')));
        }
        $state += ['runtimeDataSource' => false, 'sampleParentValue' => 42];

        $steps = $this->getSteps($context);
        $ids = ['context', 'report'];
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }
        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $i => $step) {
            $stateful[] = $step->withState($i < $currentIndex ? AssistantStep::STATE_COMPLETE : ($i === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING));
        }

        $errors = [];
        if ($stepId === 'context' && $method === 'POST' && trim((string) ($state['dataForm'] ?? '')) === '') {
            $errors[] = 'DataForm-Name fehlt.';
        }
        $report = null;
        if ($stepId === 'report' && trim((string) ($state['dataForm'] ?? '')) !== '') {
            $report = $this->service->diagnose($scope, (string) $state['dataForm'], $state);
        }
        $data = [
            'phase' => 8,
            'formValues' => [
                'dataform_name' => (string) ($state['dataForm'] ?? ''),
                'runtime_datasource' => (bool) ($state['runtimeDataSource'] ?? false),
                'sample_parent_value' => (string) ($state['sampleParentValue'] ?? 42),
            ],
            'nextStep' => $stepId === 'context' ? 'report' : null,
            'previousStep' => $stepId === 'report' ? 'context' : null,
            'diagnosticReport' => $report,
            'configurationReady' => $report !== null && !$report->hasFailures(),
            'exportLabel' => 'Diagnosebericht als JSON herunterladen',
        ];
        if ($errors !== []) { return AssistantResult::failure($this->getId(), $errors, $stepId, $stateful, $data); }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data);
    }

    private function truthy(mixed $value): bool { return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true); }
    private function scalar(string $value): mixed
    {
        $value = trim($value);
        if (preg_match('/^-?\d+$/', $value)) { return (int) $value; }
        if (is_numeric($value)) { return (float) $value; }
        return $value;
    }
}
