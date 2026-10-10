<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Assistants;

use EasyIT\Assistant\AssistantContext;
use EasyIT\Assistant\AssistantInterface;
use EasyIT\Assistant\AssistantResult;
use EasyIT\Assistant\AssistantStep;
use EasyIT\Assistant\Recovery\ProjectArchiveService;
use EasyIT\Assistant\State\AssistantStateStore;

final class ProjectRecoveryAssistant implements AssistantInterface
{
    public function __construct(private AssistantStateStore $store, private ProjectArchiveService $archives) {}

    public function getId(): string { return 'project.recovery'; }
    public function getTitle(): string { return 'Backup-, Restore- und Recovery-Assistent'; }
    public function getDescription(): string
    {
        return 'Sichert Projekte als geprüftes ZIP mit Manifest und SHA-256 oder stellt Projekt-ZIPs konfliktfrei wieder her und prüft den restaurierten Stand.';
    }

    public function getSteps(AssistantContext $context): array
    {
        $scope = $this->scope($context);
        $state = array_replace_recursive($this->defaults(), $this->store->get($this->getId(), $scope));
        $mode = (string) ($state['mode'] ?? 'backup');
        $operationFields = [
            ['name' => 'mode', 'label' => 'Vorgang', 'type' => 'select', 'options' => ['backup' => 'Projekt sichern', 'restore' => 'Projekt wiederherstellen']],
        ];
        $executeFields = $mode === 'restore' ? [
            ['name' => 'restore_archive', 'label' => 'Projekt-Backup ZIP', 'type' => 'file', 'accept' => '.zip,application/zip', 'required' => true],
            ['name' => 'target_project_id', 'label' => 'Ziel-Projekt-ID (leer = Original-ID)', 'type' => 'text'],
            ['name' => 'confirm_restore', 'label' => 'Projekt wirklich wiederherstellen', 'type' => 'checkbox'],
        ] : [
            ['name' => 'project_id', 'label' => 'Projekt-ID', 'type' => 'text', 'required' => true],
            ['name' => 'include_database', 'label' => 'Projektdatenbank mitsichern', 'type' => 'checkbox', 'default' => true],
            ['name' => 'include_backups', 'label' => 'Vorhandene projektinterne Backups mit einschließen', 'type' => 'checkbox'],
            ['name' => 'confirm_backup', 'label' => 'Projekt jetzt als ZIP sichern', 'type' => 'checkbox'],
        ];

        return [
            new AssistantStep('operation', '1. Vorgang', 'Backup oder Restore auswählen.', ['fields' => $operationFields]),
            new AssistantStep('execute', '2. Ausführen', $mode === 'restore' ? 'Backup-ZIP prüfen und konfliktfrei wiederherstellen.' : 'Projekt auswählen und Backup-Umfang festlegen.', ['fields' => $executeFields]),
            new AssistantStep('report', '3. Ergebnis und Recovery-Prüfung', 'Ergebnis, SHA-256 und Recovery-Diagnose prüfen.'),
        ];
    }

    public function run(AssistantContext $context, ?string $stepId = null): AssistantResult
    {
        $scope = $this->scope($context);
        $state = array_replace_recursive($this->defaults(), $this->store->get($this->getId(), $scope));
        if ($this->truthy($context->input('reset', false))) {
            $this->store->clear($this->getId(), $scope);
            $state = $this->defaults();
        }
        if (($state['projectId'] ?? '') === '' && $context->getProjectId()) { $state['projectId'] = $context->getProjectId(); }
        $stepId = $stepId ?: 'operation';
        $ids = ['operation', 'execute', 'report'];
        $steps = $this->getSteps($context);
        if (!in_array($stepId, $ids, true)) {
            return AssistantResult::failure($this->getId(), ['Unbekannter Schritt: ' . $stepId], $stepId, $steps);
        }

        $method = strtoupper((string) $context->meta('requestMethod', 'GET'));
        $errors = [];
        $warnings = [];
        if ($method === 'POST' && $stepId === 'operation') {
            $mode = strtolower(trim((string) $context->input('mode', 'backup')));
            if (!in_array($mode, ['backup', 'restore'], true)) { $errors[] = 'Ungültiger Recovery-Vorgang.'; }
            else {
                $state['mode'] = $mode;
                $state['result'] = null;
                $state['diagnosticReport'] = null;
                $this->store->put($this->getId(), $state, $scope);
                $steps = $this->getSteps($context);
            }
        }

        if ($method === 'POST' && $stepId === 'execute' && $errors === []) {
            if (($state['mode'] ?? 'backup') === 'backup') {
                $state['projectId'] = trim((string) $context->input('project_id', $state['projectId'] ?? ''));
                $state['includeDatabase'] = $this->truthy($context->input('include_database', false));
                $state['includeBackups'] = $this->truthy($context->input('include_backups', false));
                if (!$this->truthy($context->input('confirm_backup', false))) {
                    $errors[] = 'Bitte die Projektsicherung ausdrücklich bestätigen.';
                } elseif ($state['projectId'] === '') {
                    $errors[] = 'Projekt-ID fehlt.';
                } else {
                    $result = $this->archives->backup($state['projectId'], $state['includeDatabase'], $state['includeBackups']);
                    $state['result'] = $result->jsonSerialize();
                    $warnings = $result->getWarnings();
                    if (!$result->isOk()) { $errors[] = $result->getMessage(); }
                    else {
                        $state['downloadFile'] = basename((string) $result->getPath());
                        $state['shaFile'] = basename((string) $result->getPath()) . '.sha256';
                    }
                }
            } else {
                $state['targetProjectId'] = trim((string) $context->input('target_project_id', ''));
                if (!$this->truthy($context->input('confirm_restore', false))) {
                    $errors[] = 'Bitte die Wiederherstellung ausdrücklich bestätigen.';
                } else {
                    $files = $context->meta('files', []);
                    $upload = is_array($files) && isset($files['restore_archive']) && is_array($files['restore_archive']) ? $files['restore_archive'] : [];
                    $result = $this->archives->restoreUploaded($upload, $state['targetProjectId']);
                    $state['result'] = $result->jsonSerialize();
                    $warnings = $result->getWarnings();
                    if (!$result->isOk()) { $errors[] = $result->getMessage(); }
                    $details = $result->getDetails();
                    if (is_array($details['diagnosticReport'] ?? null)) {
                        $state['diagnosticReport'] = $details['diagnosticReport'];
                    }
                    if (!empty($details['targetProjectId'])) { $state['restoredProjectId'] = (string) $details['targetProjectId']; }
                }
            }
            $this->store->put($this->getId(), $state, $scope);
        }

        $currentIndex = array_search($stepId, $ids, true);
        $stateful = [];
        foreach ($steps as $index => $step) {
            $stateful[] = $step->withState($index < $currentIndex ? AssistantStep::STATE_COMPLETE : ($index === $currentIndex ? AssistantStep::STATE_CURRENT : AssistantStep::STATE_PENDING));
        }

        $resultState = is_array($state['result'] ?? null) ? $state['result'] : null;
        $canContinue = $stepId === 'operation' || ($stepId === 'execute' && $resultState !== null && !empty($resultState['ok']) && $errors === []);
        $data = [
            'phase' => 10,
            'mode' => $state['mode'],
            'formValues' => $this->formValues($state, $stepId),
            'nextStep' => $canContinue ? ($stepId === 'operation' ? 'execute' : ($stepId === 'execute' ? 'report' : null)) : null,
            'previousStep' => $stepId === 'execute' ? 'operation' : ($stepId === 'report' ? 'execute' : null),
            'recoveryResult' => $resultState,
            'recoveryReport' => $state['diagnosticReport'] ?? null,
            'configurationReady' => $stepId === 'report' && $resultState !== null && !empty($resultState['ok']),
            'downloadUrl' => !empty($state['downloadFile']) ? 'download.php?file=' . rawurlencode((string) $state['downloadFile']) : null,
            'shaDownloadUrl' => !empty($state['shaFile']) ? 'download.php?file=' . rawurlencode((string) $state['shaFile']) : null,
            'exportLabel' => 'Recovery-Bericht als JSON herunterladen',
            'rules' => [
                'existingProjectNeverOverwritten' => true,
                'manifestRequired' => true,
                'sha256PerFile' => true,
                'archiveSha256' => true,
                'pathTraversalRejected' => true,
                'externalDatabaseAutoImport' => false,
            ],
        ];
        if ($errors !== []) { return AssistantResult::failure($this->getId(), $errors, $stepId, $stateful, $data); }
        return AssistantResult::success($this->getId(), $stepId, $stateful, $data, $warnings);
    }

    private function defaults(): array
    {
        return [
            'mode' => 'backup',
            'projectId' => '',
            'includeDatabase' => true,
            'includeBackups' => false,
            'targetProjectId' => '',
            'result' => null,
            'diagnosticReport' => null,
            'downloadFile' => null,
            'shaFile' => null,
            'restoredProjectId' => null,
        ];
    }

    private function formValues(array $state, string $stepId): array
    {
        return match ($stepId) {
            'operation' => ['mode' => $state['mode']],
            'execute' => ($state['mode'] ?? 'backup') === 'restore'
                ? ['target_project_id' => $state['targetProjectId'] ?? '', 'confirm_restore' => false]
                : ['project_id' => $state['projectId'] ?? '', 'include_database' => $state['includeDatabase'] ?? true, 'include_backups' => $state['includeBackups'] ?? false, 'confirm_backup' => false],
            default => [],
        };
    }

    private function scope(AssistantContext $context): string
    {
        return $context->getProjectId() ?: 'recovery-global';
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }
}
