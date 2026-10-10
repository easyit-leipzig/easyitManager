<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Template;

use EasyIT\Assistant\State\AssistantStateSanitizer;

final class DataFormTemplateStore
{
    private AssistantStateSanitizer $sanitizer;

    public function __construct(private string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->sanitizer = new AssistantStateSanitizer();
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $projectId = null): array
    {
        $items = [];
        foreach ($this->payloadsInDirectory($this->systemDirectory(false)) as $payload) {
            $items[] = $this->summary($this->withLibraryDefaults($payload, 'system', null));
        }
        if ($projectId !== null && $this->validProjectId($projectId)) {
            foreach ($this->payloadsInDirectory($this->projectDirectory($projectId, false)) as $payload) {
                $items[] = $this->summary($this->withLibraryDefaults($payload, 'project', $projectId));
            }
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? '')));
        return $items;
    }

    /** @param array<string,mixed> $payload */
    public function save(array $payload, string $visibility = 'system', ?string $projectId = null): array
    {
        $this->assertVisibility($visibility, $projectId);
        $dir = $visibility === 'project' ? $this->projectDirectory((string)$projectId, true) : $this->systemDirectory(true);
        if ($dir === null) { throw new \RuntimeException('Vorlagenverzeichnis kann nicht erstellt werden.'); }
        $id = trim((string)($payload['id'] ?? ''));
        $this->assertTemplateId($id);
        $existing = $this->getInScope($id, $visibility, $projectId);
        $now = gmdate('c');
        $clean = $this->sanitizer->sanitize($payload);
        $clean['schema'] = 'easyit.assistant.dataform-template.v1';
        $clean['id'] = $id;
        $clean['createdAt'] = (string)($existing['createdAt'] ?? $clean['createdAt'] ?? $now);
        $clean['updatedAt'] = $now;
        $clean['library'] = array_merge(is_array($clean['library'] ?? null) ? $clean['library'] : [], [
            'visibility' => $visibility,
            'ownerProject' => $visibility === 'project' ? $projectId : null,
        ]);
        $this->atomicWrite($dir . '/' . $id . '.json', $clean);
        return $clean;
    }

    /** @return array<string,mixed> */
    public function get(string $id, ?string $projectId = null): array
    {
        if (!$this->validTemplateId($id)) { return []; }
        if ($projectId !== null && $this->validProjectId($projectId)) {
            $project = $this->getInScope($id, 'project', $projectId);
            if ($project !== []) { return $this->withLibraryDefaults($project, 'project', $projectId); }
        }
        $system = $this->getInScope($id, 'system', null);
        if ($system !== []) { return $this->withLibraryDefaults($system, 'system', null); }
        return [];
    }

    /** @return array<string,mixed> */
    public function getInScope(string $id, string $visibility, ?string $projectId = null): array
    {
        if (!$this->validTemplateId($id)) { return []; }
        $this->assertVisibility($visibility, $projectId);
        $dir = $visibility === 'project' ? $this->projectDirectory((string)$projectId, false) : $this->systemDirectory(false);
        if ($dir === null) { return []; }
        return $this->readPath($dir . '/' . $id . '.json');
    }

    public function delete(string $id, string $visibility = 'system', ?string $projectId = null): bool
    {
        if (!$this->validTemplateId($id)) { return false; }
        $this->assertVisibility($visibility, $projectId);
        $dir = $visibility === 'project' ? $this->projectDirectory((string)$projectId, false) : $this->systemDirectory(false);
        if ($dir === null) { return false; }
        $path = $dir . '/' . $id . '.json';
        return !is_file($path) || @unlink($path);
    }

    public function makeId(string $name, ?string $projectId = null, string $visibility = 'system'): string
    {
        $this->assertVisibility($visibility, $projectId);
        $slug = strtolower(trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-.'));
        if ($slug === '') { $slug = 'dataform-template'; }
        $slug = substr($slug, 0, 80);
        $id = $slug;
        $n = 2;
        while ($this->getInScope($id, $visibility, $projectId) !== []) { $id = $slug . '-' . $n++; }
        return $id;
    }

    public function pathFor(string $id, string $visibility = 'system', ?string $projectId = null): string
    {
        $this->assertTemplateId($id);
        $this->assertVisibility($visibility, $projectId);
        $dir = $visibility === 'project' ? $this->projectDirectory((string)$projectId, true) : $this->systemDirectory(true);
        if ($dir === null) { throw new \RuntimeException('Vorlagenverzeichnis kann nicht erstellt werden.'); }
        return $dir . '/' . $id . '.json';
    }

    public function baseDirectory(string $visibility = 'system', ?string $projectId = null, bool $create = false): ?string
    {
        $this->assertVisibility($visibility, $projectId);
        return $visibility === 'project' ? $this->projectDirectory((string)$projectId, $create) : $this->systemDirectory($create);
    }

    private function systemDirectory(bool $create): ?string
    {
        $dir = $this->projectRoot . '/storage/assistant/templates';
        if ($create && !is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return null; }
        return $dir;
    }

    private function projectDirectory(string $projectId, bool $create): ?string
    {
        if (!$this->validProjectId($projectId)) { return null; }
        $project = $this->projectRoot . '/projects/' . $projectId;
        if (!is_dir($project)) { return null; }
        $dir = $project . '/config/assistant/templates';
        if ($create && !is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return null; }
        return $dir;
    }

    /** @return list<array<string,mixed>> */
    private function payloadsInDirectory(?string $dir): array
    {
        if ($dir === null || !is_dir($dir)) { return []; }
        $items = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $payload = $this->readPath($path);
            if ($payload !== []) { $items[] = $payload; }
        }
        return $items;
    }

    /** @return array<string,mixed> */
    private function readPath(string $path): array
    {
        if (!is_file($path)) { return []; }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') { return []; }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'easyit.assistant.dataform-template.v1') { return []; }
        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function summary(array $payload): array
    {
        return [
            'id' => (string)($payload['id'] ?? ''),
            'name' => (string)($payload['name'] ?? ''),
            'description' => (string)($payload['description'] ?? ''),
            'sourceProject' => (string)($payload['source']['projectId'] ?? ''),
            'sourceDataForm' => (string)($payload['source']['dataFormId'] ?? ''),
            'createdAt' => (string)($payload['createdAt'] ?? ''),
            'updatedAt' => (string)($payload['updatedAt'] ?? ''),
            'parts' => array_keys(is_array($payload['bundle'] ?? null) ? $payload['bundle'] : []),
            'visibility' => (string)($payload['library']['visibility'] ?? 'system'),
            'ownerProject' => $payload['library']['ownerProject'] ?? null,
            'version' => (int)($payload['library']['version'] ?? 1),
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function withLibraryDefaults(array $payload, string $visibility, ?string $projectId): array
    {
        $library = is_array($payload['library'] ?? null) ? $payload['library'] : [];
        $payload['library'] = array_merge([
            'visibility' => $visibility,
            'ownerProject' => $visibility === 'project' ? $projectId : null,
            'version' => 1,
        ], $library);
        return $payload;
    }

    private function assertVisibility(string $visibility, ?string $projectId): void
    {
        if (!in_array($visibility, ['system', 'project'], true)) { throw new \InvalidArgumentException('Ungültige Vorlagensichtbarkeit.'); }
        if ($visibility === 'project') {
            if ($projectId === null || !$this->validProjectId($projectId) || !is_dir($this->projectRoot . '/projects/' . $projectId)) {
                throw new \InvalidArgumentException('Für eine projektbezogene Vorlage ist ein gültiges Projekt erforderlich.');
            }
        }
    }

    private function assertTemplateId(string $id): void
    {
        if (!$this->validTemplateId($id)) { throw new \InvalidArgumentException('Ungültige Vorlagen-ID.'); }
    }
    private function validTemplateId(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $id); }
    private function validProjectId(string $id): bool { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/', $id); }

    /** @param array<string,mixed> $payload */
    private function atomicWrite(string $path, array $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Vorlage kann nicht serialisiert werden.'); }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Vorlage kann nicht atomar gespeichert werden.');
        }
    }
}
