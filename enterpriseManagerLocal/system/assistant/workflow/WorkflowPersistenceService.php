<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Workflow;

use EasyIT\Assistant\State\AssistantStateStore;

final class WorkflowPersistenceService
{
    private const ASSISTANT_ID = 'workflow.standard';
    private const PROJECT_SCOPE = 'workflow.standard';

    public function __construct(private AssistantStateStore $store, private string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
    }

    /** @return array<string,mixed> */
    public function load(?string $projectId = null): array
    {
        $projectId = trim((string) $projectId);
        if ($projectId === '') {
            $pointer = $this->readPointer();
            $projectId = trim((string) ($pointer['projectId'] ?? ''));
        }
        if ($projectId === '') {
            return [];
        }
        $state = $this->store->getForProject(self::ASSISTANT_ID, $projectId, self::PROJECT_SCOPE);
        if ($state !== []) {
            $state['_persistenceProjectId'] = $projectId;
        }
        return $state;
    }

    /** @param array<string,mixed> $state */
    public function save(string $projectId, string $dataFormId, array $state): void
    {
        if (!$this->validProjectId($projectId) || !is_dir($this->projectRoot . '/projects/' . $projectId)) {
            return;
        }
        $copy = $state;
        unset($copy['_persistenceProjectId']);
        $this->store->putForProject(self::ASSISTANT_ID, $projectId, $copy, self::PROJECT_SCOPE);
        $this->writePointer([
            'schema' => 'easyit.assistant.workflow-pointer.v1',
            'workflow' => 'standard',
            'projectId' => $projectId,
            'dataFormId' => $dataFormId,
            'updatedAt' => gmdate('c'),
        ]);
    }

    public function recordAssistantStep(string $projectId, string $dataFormId, string $assistantId, string $stepId): void
    {
        if ($projectId === '' || $assistantId === '' || $stepId === '') { return; }
        $state = $this->load($projectId);
        if ($state === []) {
            $state = [
                'projectId' => $projectId,
                'dataFormId' => $dataFormId,
                'optional' => ['relationsSkipped' => false, 'eventsSkipped' => false],
                'startedAt' => gmdate('c'),
            ];
        }
        $state['projectId'] = $projectId;
        if ($dataFormId !== '') { $state['dataFormId'] = $dataFormId; }
        if (!is_array($state['assistantLastSteps'] ?? null)) { $state['assistantLastSteps'] = []; }
        $state['assistantLastSteps'][$assistantId] = $stepId;
        $state['lastAssistantId'] = $assistantId;
        $state['lastAssistantStep'] = $stepId;
        $state['updatedAt'] = gmdate('c');
        unset($state['_persistenceProjectId']);
        $this->save($projectId, (string) ($state['dataFormId'] ?? $dataFormId), $state);

        $runtime = $this->store->get(self::ASSISTANT_ID, 'active-standard-workflow');
        if (($runtime['projectId'] ?? '') === $projectId) {
            if (!is_array($runtime['assistantLastSteps'] ?? null)) { $runtime['assistantLastSteps'] = []; }
            $runtime['assistantLastSteps'][$assistantId] = $stepId;
            $runtime['lastAssistantId'] = $assistantId;
            $runtime['lastAssistantStep'] = $stepId;
            $runtime['updatedAt'] = $state['updatedAt'];
            $this->store->put(self::ASSISTANT_ID, $runtime, 'active-standard-workflow');
        }
    }

    public function lastStepForAssistant(string $projectId, string $assistantId): ?string
    {
        if ($projectId === '' || $assistantId === '') { return null; }
        $state = $this->load($projectId);
        $steps = is_array($state['assistantLastSteps'] ?? null) ? $state['assistantLastSteps'] : [];
        $step = trim((string) ($steps[$assistantId] ?? ''));
        return $step !== '' ? $step : null;
    }

    public function clear(string $projectId): void
    {
        if ($projectId === '') {
            return;
        }
        $this->store->clearForProject(self::ASSISTANT_ID, $projectId, self::PROJECT_SCOPE);
        $pointer = $this->readPointer();
        if (($pointer['projectId'] ?? '') === $projectId) {
            @unlink($this->pointerPath());
        }
    }

    /** @return array<string,mixed> */
    public function info(string $projectId): array
    {
        $pointer = $this->readPointer();
        return [
            'enabled' => true,
            'projectScoped' => true,
            'resumableAcrossSessions' => true,
            'projectId' => $projectId,
            'activePointerMatches' => $projectId !== '' && ($pointer['projectId'] ?? '') === $projectId,
            'storage' => $projectId !== '' ? 'projects/' . $projectId . '/config/assistant/state/' : null,
        ];
    }

    /** @return array<string,mixed> */
    private function readPointer(): array
    {
        $path = $this->pointerPath();
        if (!is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'easyit.assistant.workflow-pointer.v1') {
            return [];
        }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function writePointer(array $payload): void
    {
        $path = $this->pointerPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    private function pointerPath(): string
    {
        return $this->projectRoot . '/storage/assistant/active-standard-workflow.json';
    }

    private function validProjectId(string $projectId): bool
    {
        return $projectId !== '' && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $projectId);
    }
}
