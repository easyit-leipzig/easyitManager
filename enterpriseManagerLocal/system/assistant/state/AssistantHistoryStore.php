<?php
declare(strict_types=1);

namespace EasyIT\Assistant\State;

final class AssistantHistoryStore
{
    private AssistantStateSanitizer $sanitizer;

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->sanitizer = new AssistantStateSanitizer();
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function recordTransition(string $assistantId, string $projectId, string $scope, array $before, array $after, string $action = 'update'): void
    {
        if (!$this->valid($assistantId, $projectId, $scope)) { return; }
        $before = $this->sanitizer->sanitize($before);
        $after = $this->sanitizer->sanitize($after);
        if ($before === $after) { return; }

        $dir = $this->historyDir($assistantId, $projectId, $scope, true);
        if ($dir === null) { return; }
        $this->withLock($dir, function () use ($dir, $assistantId, $projectId, $scope, $before, $after, $action): void {
            $index = $this->readIndex($dir, $assistantId, $projectId, $scope);
            if (($index['entries'] ?? []) === []) {
                $baseline = $this->createVersion($dir, $assistantId, $projectId, $scope, $before, 'baseline', null, null);
                $index['entries'][] = $baseline['meta'];
                $index['activePath'][] = $baseline['meta']['id'];
                $index['cursor'] = 0;
            }

            $currentId = $this->currentId($index);
            $currentState = $currentId !== null ? $this->readVersionState($dir, $currentId) : [];
            if ($currentState !== $before) {
                $sync = $this->createVersion($dir, $assistantId, $projectId, $scope, $before, 'external-sync', $currentId, null);
                $index['entries'][] = $sync['meta'];
                $index['activePath'] = array_slice((array) $index['activePath'], 0, ((int) $index['cursor']) + 1);
                $index['activePath'][] = $sync['meta']['id'];
                $index['cursor'] = count($index['activePath']) - 1;
                $currentId = $sync['meta']['id'];
            }

            $index['activePath'] = array_slice((array) $index['activePath'], 0, ((int) $index['cursor']) + 1);
            $created = $this->createVersion($dir, $assistantId, $projectId, $scope, $after, $action, $currentId, null);
            $index['entries'][] = $created['meta'];
            $index['activePath'][] = $created['meta']['id'];
            $index['cursor'] = count($index['activePath']) - 1;
            $index['updatedAt'] = gmdate('c');
            $this->writeIndex($dir, $index);
        });
    }

    /** @return array<string,mixed> */
    public function list(string $assistantId, string $projectId, string $scope): array
    {
        $dir = $this->historyDir($assistantId, $projectId, $scope, false);
        if ($dir === null) { return $this->emptyIndex($assistantId, $projectId, $scope); }
        $index = $this->readIndex($dir, $assistantId, $projectId, $scope);
        $active = array_flip((array) ($index['activePath'] ?? []));
        $current = $this->currentId($index);
        $entries = [];
        foreach (array_reverse((array) ($index['entries'] ?? [])) as $entry) {
            if (!is_array($entry)) { continue; }
            $id = (string) ($entry['id'] ?? '');
            $entry['active'] = isset($active[$id]);
            $entry['current'] = $id !== '' && $id === $current;
            $entry['detached'] = !$entry['active'];
            $entries[] = $entry;
        }
        $index['entries'] = $entries;
        $index['currentVersionId'] = $current;
        $index['canUndo'] = ((int) ($index['cursor'] ?? -1)) > 0;
        $index['canRedo'] = ((int) ($index['cursor'] ?? -1)) >= 0 && ((int) ($index['cursor'] ?? -1)) < count((array) ($index['activePath'] ?? [])) - 1;
        return $index;
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    public function undo(string $assistantId, string $projectId, string $scope): array
    {
        return $this->moveCursor($assistantId, $projectId, $scope, -1);
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    public function redo(string $assistantId, string $projectId, string $scope): array
    {
        return $this->moveCursor($assistantId, $projectId, $scope, 1);
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    public function restore(string $assistantId, string $projectId, string $scope, string $versionId): array
    {
        $dir = $this->historyDir($assistantId, $projectId, $scope, false);
        if ($dir === null || !$this->validVersionId($versionId)) {
            return ['ok' => false, 'state' => [], 'versionId' => null, 'message' => 'Historienversion nicht gefunden.'];
        }
        $result = ['ok' => false, 'state' => [], 'versionId' => null, 'message' => 'Historienversion nicht gefunden.'];
        $this->withLock($dir, function () use ($dir, $assistantId, $projectId, $scope, $versionId, &$result): void {
            $index = $this->readIndex($dir, $assistantId, $projectId, $scope);
            $known = false;
            foreach ((array) ($index['entries'] ?? []) as $entry) {
                if (is_array($entry) && ($entry['id'] ?? '') === $versionId) { $known = true; break; }
            }
            if (!$known) { return; }
            $state = $this->readVersionState($dir, $versionId);
            $parentId = $this->currentId($index);
            $index['activePath'] = array_slice((array) ($index['activePath'] ?? []), 0, ((int) ($index['cursor'] ?? -1)) + 1);
            $created = $this->createVersion($dir, $assistantId, $projectId, $scope, $state, 'restore', $parentId, $versionId);
            $index['entries'][] = $created['meta'];
            $index['activePath'][] = $created['meta']['id'];
            $index['cursor'] = count($index['activePath']) - 1;
            $index['updatedAt'] = gmdate('c');
            $this->writeIndex($dir, $index);
            $result = ['ok' => true, 'state' => $state, 'versionId' => $created['meta']['id'], 'message' => 'Historienversion wurde als neuer aktueller Stand wiederhergestellt.'];
        });
        return $result;
    }

    /** @return array<string,mixed> */
    public function compare(string $assistantId, string $projectId, string $scope, string $fromVersionId, string $toVersionId): array
    {
        $dir = $this->historyDir($assistantId, $projectId, $scope, false);
        if ($dir === null || !$this->validVersionId($fromVersionId) || !$this->validVersionId($toVersionId)) {
            return ['ok' => false, 'changes' => [], 'count' => 0];
        }
        $from = $this->readVersionState($dir, $fromVersionId);
        $to = $this->readVersionState($dir, $toVersionId);
        $changes = [];
        $this->diff($from, $to, '$', $changes, 500);
        return [
            'ok' => true,
            'fromVersionId' => $fromVersionId,
            'toVersionId' => $toVersionId,
            'count' => count($changes),
            'changes' => $changes,
        ];
    }

    /** @return array<string,mixed> */
    public function version(string $assistantId, string $projectId, string $scope, string $versionId): array
    {
        $dir = $this->historyDir($assistantId, $projectId, $scope, false);
        if ($dir === null || !$this->validVersionId($versionId)) { return []; }
        return $this->readVersionState($dir, $versionId);
    }

    /** @return array{ok:bool,state:array<string,mixed>,versionId:?string,message:string} */
    private function moveCursor(string $assistantId, string $projectId, string $scope, int $delta): array
    {
        $dir = $this->historyDir($assistantId, $projectId, $scope, false);
        if ($dir === null) { return ['ok' => false, 'state' => [], 'versionId' => null, 'message' => 'Keine Historie vorhanden.']; }
        $result = ['ok' => false, 'state' => [], 'versionId' => null, 'message' => $delta < 0 ? 'Undo ist nicht möglich.' : 'Redo ist nicht möglich.'];
        $this->withLock($dir, function () use ($dir, $assistantId, $projectId, $scope, $delta, &$result): void {
            $index = $this->readIndex($dir, $assistantId, $projectId, $scope);
            $path = array_values((array) ($index['activePath'] ?? []));
            $cursor = (int) ($index['cursor'] ?? -1);
            $target = $cursor + $delta;
            if ($target < 0 || $target >= count($path)) { return; }
            $versionId = (string) $path[$target];
            $state = $this->readVersionState($dir, $versionId);
            $index['cursor'] = $target;
            $index['updatedAt'] = gmdate('c');
            $this->writeIndex($dir, $index);
            $result = [
                'ok' => true,
                'state' => $state,
                'versionId' => $versionId,
                'message' => $delta < 0 ? 'Vorheriger Assistentenzustand wurde wiederhergestellt.' : 'Nächster Assistentenzustand wurde wiederhergestellt.',
            ];
        });
        return $result;
    }

    /** @return array{meta:array<string,mixed>,state:array<string,mixed>} */
    private function createVersion(string $dir, string $assistantId, string $projectId, string $scope, array $state, string $action, ?string $parentId, ?string $sourceVersionId): array
    {
        $state = $this->sanitizer->sanitize($state);
        $now = gmdate('c');
        $id = gmdate('Ymd\THis') . 'Z-' . bin2hex(random_bytes(5));
        $checksum = hash('sha256', (string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $meta = [
            'id' => $id,
            'createdAt' => $now,
            'action' => $action,
            'parentId' => $parentId,
            'sourceVersionId' => $sourceVersionId,
            'checksum' => $checksum,
            'empty' => $state === [],
        ];
        $payload = [
            'schema' => 'easyit.assistant.history-version.v1',
            'assistantId' => $assistantId,
            'projectId' => $projectId,
            'scope' => $scope,
            'meta' => $meta,
            'state' => $state,
        ];
        $this->atomicJsonWrite($dir . '/versions/' . $id . '.json', $payload);
        return ['meta' => $meta, 'state' => $state];
    }

    /** @return array<string,mixed> */
    private function readVersionState(string $dir, string $versionId): array
    {
        if (!$this->validVersionId($versionId)) { return []; }
        $file = $dir . '/versions/' . $versionId . '.json';
        if (!is_file($file)) { return []; }
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'easyit.assistant.history-version.v1') { return []; }
        return is_array($payload['state'] ?? null) ? $payload['state'] : [];
    }

    /** @return array<string,mixed> */
    private function readIndex(string $dir, string $assistantId, string $projectId, string $scope): array
    {
        $file = $dir . '/history.json';
        if (!is_file($file)) { return $this->emptyIndex($assistantId, $projectId, $scope); }
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'easyit.assistant.history.v1') { return $this->emptyIndex($assistantId, $projectId, $scope); }
        if (($payload['assistantId'] ?? '') !== $assistantId || ($payload['projectId'] ?? '') !== $projectId || ($payload['scope'] ?? '') !== $scope) {
            return $this->emptyIndex($assistantId, $projectId, $scope);
        }
        return $payload;
    }

    /** @return array<string,mixed> */
    private function emptyIndex(string $assistantId, string $projectId, string $scope): array
    {
        return [
            'schema' => 'easyit.assistant.history.v1',
            'assistantId' => $assistantId,
            'projectId' => $projectId,
            'scope' => $scope,
            'createdAt' => gmdate('c'),
            'updatedAt' => gmdate('c'),
            'entries' => [],
            'activePath' => [],
            'cursor' => -1,
        ];
    }

    private function writeIndex(string $dir, array $index): void
    {
        $this->atomicJsonWrite($dir . '/history.json', $index);
    }

    private function currentId(array $index): ?string
    {
        $path = array_values((array) ($index['activePath'] ?? []));
        $cursor = (int) ($index['cursor'] ?? -1);
        return isset($path[$cursor]) ? (string) $path[$cursor] : null;
    }

    private function historyDir(string $assistantId, string $projectId, string $scope, bool $create): ?string
    {
        if (!$this->valid($assistantId, $projectId, $scope)) { return null; }
        $project = $this->projectRoot . '/projects/' . $projectId;
        if (!is_dir($project)) { return null; }
        $assistantSafe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $assistantId) ?: 'assistant';
        $scopeHash = substr(hash('sha256', $scope), 0, 20);
        $dir = $project . '/config/assistant/history/' . $assistantSafe . '--' . $scopeHash;
        if ($create) {
            foreach ([$dir, $dir . '/versions'] as $path) {
                if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) { return null; }
            }
        }
        return is_dir($dir) ? $dir : null;
    }

    private function valid(string $assistantId, string $projectId, string $scope): bool
    {
        return $assistantId !== '' && $scope !== '' && $projectId !== '' && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $projectId);
    }

    private function validVersionId(string $versionId): bool
    {
        return (bool) preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{10}$/', $versionId);
    }

    private function withLock(string $dir, callable $callback): void
    {
        $lock = @fopen($dir . '/.history.lock', 'c');
        if ($lock === false) { return; }
        try {
            if (!flock($lock, LOCK_EX)) { return; }
            $callback();
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    /** @param array<int,array<string,mixed>> $changes */
    private function diff(mixed $before, mixed $after, string $path, array &$changes, int $limit): void
    {
        if (count($changes) >= $limit || $before === $after) { return; }
        if (is_array($before) && is_array($after)) {
            $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
            foreach ($keys as $key) {
                if (count($changes) >= $limit) { return; }
                $existsBefore = array_key_exists($key, $before);
                $existsAfter = array_key_exists($key, $after);
                $childPath = $path . (is_int($key) ? '[' . $key . ']' : '.' . $key);
                if (!$existsBefore) {
                    $changes[] = ['path' => $childPath, 'type' => 'added', 'before' => null, 'after' => $after[$key]];
                } elseif (!$existsAfter) {
                    $changes[] = ['path' => $childPath, 'type' => 'removed', 'before' => $before[$key], 'after' => null];
                } else {
                    $this->diff($before[$key], $after[$key], $childPath, $changes, $limit);
                }
            }
            return;
        }
        $changes[] = ['path' => $path, 'type' => 'changed', 'before' => $before, 'after' => $after];
    }

    private function atomicJsonWrite(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return; }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) { return; }
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) { return; }
        @chmod($tmp, 0660);
        if (!@rename($tmp, $path)) { @unlink($tmp); }
    }
}
