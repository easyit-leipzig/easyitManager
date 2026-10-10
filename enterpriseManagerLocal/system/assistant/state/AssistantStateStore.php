<?php
declare(strict_types=1);

namespace EasyIT\Assistant\State;

if (!class_exists(AssistantStateSanitizer::class, false)) {
    require_once __DIR__ . '/AssistantStateSanitizer.php';
}
if (!class_exists(AssistantHistoryStore::class, false)) {
    require_once __DIR__ . '/AssistantHistoryStore.php';
}

final class AssistantStateStore
{
    private array $fallback = [];
    private ?string $lastReadSource = null;
    private AssistantStateSanitizer $sanitizer;
    private ?AssistantHistoryStore $historyStore = null;

    /** @var list<string> */
    private array $persistentAssistantIds;

    /**
     * @param list<string>|null $persistentAssistantIds
     */
    public function __construct(
        private string $namespace = 'easyit_assistant',
        private ?string $projectRoot = null,
        ?array $persistentAssistantIds = null
    ) {
        $this->projectRoot = $projectRoot !== null ? rtrim($projectRoot, '/\\') : null;
        $this->sanitizer = new AssistantStateSanitizer();
        $this->historyStore = $this->projectRoot !== null ? new AssistantHistoryStore($this->projectRoot) : null;
        $this->persistentAssistantIds = $persistentAssistantIds ?? [
            'datasource.configure',
            'dataform.create',
            'dataform.fields',
            'dataform.relations',
            'dataform.events',
            'dataform.actions',
            'dataform.diagnostics',
            'dataform.templates',
            'dataform.template-library',
            'dataform.transport',
            'dataform.module-transport',
            'dataform.module-migrations',
            'dataform.module-migration-history',
            'workflow.standard',
        ];
    }

    public function get(string $assistantId, string $scope = 'default'): array
    {
        $key = $this->key($assistantId, $scope);
        if ($this->sessionAvailable() && isset($_SESSION[$this->namespace][$key]) && is_array($_SESSION[$this->namespace][$key])) {
            $this->lastReadSource = 'session';
            return $_SESSION[$this->namespace][$key];
        }
        if (isset($this->fallback[$key])) {
            $this->lastReadSource = 'memory';
            return $this->fallback[$key];
        }

        $projectId = $this->projectFromScope($scope);
        if ($projectId !== null) {
            $persisted = $this->getForProject($assistantId, $projectId, $scope);
            if ($persisted !== []) {
                $this->hydrateRuntime($key, $persisted);
                $this->lastReadSource = 'project';
                return $persisted;
            }
        }

        $this->lastReadSource = null;
        return [];
    }

    public function put(string $assistantId, array $state, string $scope = 'default'): void
    {
        $key = $this->key($assistantId, $scope);
        $this->hydrateRuntime($key, $state);

        $projectId = $this->projectFromScope($scope);
        if ($projectId !== null) {
            $this->putForProject($assistantId, $projectId, $state, $scope);
        }
    }

    public function clear(string $assistantId, string $scope = 'default'): void
    {
        $key = $this->key($assistantId, $scope);
        if ($this->sessionAvailable()) {
            unset($_SESSION[$this->namespace][$key]);
        }
        unset($this->fallback[$key]);

        $projectId = $this->projectFromScope($scope);
        if ($projectId !== null) {
            $this->clearForProject($assistantId, $projectId, $scope);
        }
    }

    public function getForProject(string $assistantId, string $projectId, string $scope = 'default'): array
    {
        if (!$this->canPersist($assistantId, $projectId)) {
            return [];
        }
        $path = $this->statePath($assistantId, $projectId, $scope, false);
        if ($path === null || !is_file($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'easyit.assistant.state.v1') {
            return [];
        }
        if (($payload['assistantId'] ?? '') !== $assistantId || ($payload['projectId'] ?? '') !== $projectId || ($payload['scope'] ?? '') !== $scope) {
            return [];
        }
        $state = $payload['state'] ?? [];
        return is_array($state) ? $state : [];
    }

    public function putForProject(string $assistantId, string $projectId, array $state, string $scope = 'default'): void
    {
        $this->writeForProject($assistantId, $projectId, $state, $scope, true, 'update');
    }

    public function clearForProject(string $assistantId, string $projectId, string $scope = 'default'): void
    {
        if (!$this->canPersist($assistantId, $projectId)) {
            return;
        }
        $before = $this->getForProject($assistantId, $projectId, $scope);
        if ($before !== [] && $this->historyStore !== null) {
            $this->historyStore->recordTransition($assistantId, $projectId, $scope, $before, [], 'clear');
        }
        $path = $this->statePath($assistantId, $projectId, $scope, false);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array<string,mixed> */
    public function history(string $assistantId, string $projectId, string $scope = 'default'): array
    {
        return $this->historyStore?->list($assistantId, $projectId, $scope) ?? [];
    }

    /** @return array<string,mixed> */
    public function compareHistory(string $assistantId, string $projectId, string $scope, string $fromVersionId, string $toVersionId): array
    {
        return $this->historyStore?->compare($assistantId, $projectId, $scope, $fromVersionId, $toVersionId) ?? ['ok' => false, 'changes' => [], 'count' => 0];
    }

    /** @return array<string,mixed> */
    public function historyVersion(string $assistantId, string $projectId, string $scope, string $versionId): array
    {
        return $this->historyStore?->version($assistantId, $projectId, $scope, $versionId) ?? [];
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    public function undo(string $assistantId, string $projectId, string $scope): array
    {
        $result = $this->historyStore?->undo($assistantId, $projectId, $scope) ?? ['ok' => false, 'state' => [], 'versionId' => null, 'message' => 'Historie ist nicht verfügbar.'];
        if ($result['ok']) { $this->applyHistoricalState($assistantId, $projectId, $scope, $result['state']); }
        return $result;
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    public function redo(string $assistantId, string $projectId, string $scope): array
    {
        $result = $this->historyStore?->redo($assistantId, $projectId, $scope) ?? ['ok' => false, 'state' => [], 'versionId' => null, 'message' => 'Historie ist nicht verfügbar.'];
        if ($result['ok']) { $this->applyHistoricalState($assistantId, $projectId, $scope, $result['state']); }
        return $result;
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    public function restoreHistoryVersion(string $assistantId, string $projectId, string $scope, string $versionId): array
    {
        $result = $this->historyStore?->restore($assistantId, $projectId, $scope, $versionId) ?? ['ok' => false, 'state' => [], 'versionId' => null, 'message' => 'Historie ist nicht verfügbar.'];
        if ($result['ok']) { $this->applyHistoricalState($assistantId, $projectId, $scope, $result['state']); }
        return $result;
    }

    /** @param array<string,mixed> $state */
    private function applyHistoricalState(string $assistantId, string $projectId, string $scope, array $state): void
    {
        $key = $this->key($assistantId, $scope);
        $this->hydrateRuntime($key, $state);
        if ($state === []) {
            $path = $this->statePath($assistantId, $projectId, $scope, false);
            if ($path !== null && is_file($path)) { @unlink($path); }
            return;
        }
        $this->writeForProject($assistantId, $projectId, $state, $scope, false, 'history');
    }

    /** @param array<string,mixed> $state */
    private function writeForProject(string $assistantId, string $projectId, array $state, string $scope, bool $recordHistory, string $historyAction): void
    {
        if (!$this->canPersist($assistantId, $projectId)) { return; }
        $path = $this->statePath($assistantId, $projectId, $scope, true);
        if ($path === null) { return; }
        $before = $this->getForProject($assistantId, $projectId, $scope);
        $clean = $this->sanitizer->sanitize($state);
        if ($recordHistory && $this->historyStore !== null && $before !== $clean) {
            $this->historyStore->recordTransition($assistantId, $projectId, $scope, $before, $clean, $historyAction);
        }
        $payload = [
            'schema' => 'easyit.assistant.state.v1',
            'assistantId' => $assistantId,
            'scope' => $scope,
            'projectId' => $projectId,
            'updatedAt' => gmdate('c'),
            'state' => $clean,
        ];
        $this->atomicJsonWrite($path, $payload);
    }

    /** @return array<string,mixed> */
    public function persistenceInfo(string $assistantId, string $scope = 'default'): array
    {
        $projectId = $this->projectFromScope($scope);
        $path = $projectId !== null ? $this->statePath($assistantId, $projectId, $scope, false) : null;
        return [
            'enabled' => $this->projectRoot !== null,
            'assistantPersistent' => in_array($assistantId, $this->persistentAssistantIds, true),
            'projectId' => $projectId,
            'exists' => $path !== null && is_file($path),
            'relativePath' => $path !== null && $this->projectRoot !== null ? ltrim(substr($path, strlen($this->projectRoot)), '/\\') : null,
            'lastReadSource' => $this->lastReadSource,
            'historyEnabled' => $this->historyStore !== null && $projectId !== null,
        ];
    }

    public function getProjectRoot(): ?string
    {
        return $this->projectRoot;
    }

    private function hydrateRuntime(string $key, array $state): void
    {
        if ($this->sessionAvailable()) {
            $_SESSION[$this->namespace][$key] = $state;
            return;
        }
        $this->fallback[$key] = $state;
    }

    private function sessionAvailable(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    private function key(string $assistantId, string $scope): string
    {
        return hash('sha256', $assistantId . '|' . $scope);
    }

    private function projectFromScope(string $scope): ?string
    {
        if ($this->projectRoot === null || $scope === '' || in_array($scope, ['default', 'global', 'project-create', 'active-standard-workflow'], true)) {
            return null;
        }
        $projectId = explode('|', $scope, 2)[0];
        if (!$this->validProjectId($projectId)) {
            return null;
        }
        return is_dir($this->projectRoot . '/projects/' . $projectId) ? $projectId : null;
    }

    private function canPersist(string $assistantId, string $projectId): bool
    {
        if ($this->projectRoot === null || !in_array($assistantId, $this->persistentAssistantIds, true) || !$this->validProjectId($projectId)) {
            return false;
        }
        return is_dir($this->projectRoot . '/projects/' . $projectId);
    }

    private function validProjectId(string $projectId): bool
    {
        return $projectId !== '' && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $projectId);
    }

    private function statePath(string $assistantId, string $projectId, string $scope, bool $createDirectory): ?string
    {
        if ($this->projectRoot === null || !$this->validProjectId($projectId)) {
            return null;
        }
        $projectDir = $this->projectRoot . '/projects/' . $projectId;
        if (!is_dir($projectDir)) {
            return null;
        }
        $dir = $projectDir . '/config/assistant/state';
        if ($createDirectory && !is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return null;
        }
        $assistantSafe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $assistantId) ?: 'assistant';
        $scopeHash = substr(hash('sha256', $scope), 0, 20);
        return $dir . '/' . $assistantSafe . '--' . $scopeHash . '.json';
    }

    /** @param array<string,mixed> $payload */
    private function atomicJsonWrite(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return;
        }
        $lockPath = $dir . '/.state.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return;
        }
        try {
            if (!flock($lock, LOCK_EX)) {
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
                return;
            }
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }
}
