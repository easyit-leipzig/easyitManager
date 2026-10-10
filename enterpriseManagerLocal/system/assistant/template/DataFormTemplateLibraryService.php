<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Template;

use EasyIT\Assistant\State\AssistantStateSanitizer;

final class DataFormTemplateLibraryService
{
    private AssistantStateSanitizer $sanitizer;

    public function __construct(private DataFormTemplateStore $store, private string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/\\');
        $this->sanitizer = new AssistantStateSanitizer();
    }

    /** @return list<array<string,mixed>> */
    public function list(?string $projectId = null): array
    {
        return $this->store->all($projectId);
    }

    /** @return array<string,mixed> */
    public function get(string $id, ?string $projectId = null): array
    {
        return $this->store->get($id, $projectId);
    }

    /** @return list<array<string,mixed>> */
    public function history(string $id, string $visibility, ?string $projectId = null): array
    {
        $dir = $this->versionDirectory($id, $visibility, $projectId, false);
        if ($dir === null || !is_dir($dir) || (glob($dir . '/v*.json') ?: []) === []) {
            $template = $this->store->getInScope($id, $visibility, $projectId);
            if ($template !== []) { $this->snapshot($template, $visibility, $projectId, 'baseline'); }
            $dir = $this->versionDirectory($id, $visibility, $projectId, false);
        }
        if ($dir === null || !is_dir($dir)) { return []; }
        $items = [];
        foreach (glob($dir . '/v*.json') ?: [] as $path) {
            $raw = @file_get_contents($path);
            $payload = $raw === false ? null : json_decode($raw, true);
            if (!is_array($payload)) { continue; }
            $items[] = [
                'version' => (int)($payload['_version']['number'] ?? 0),
                'reason' => (string)($payload['_version']['reason'] ?? ''),
                'createdAt' => (string)($payload['_version']['createdAt'] ?? ''),
                'name' => (string)($payload['name'] ?? ''),
                'visibility' => (string)($payload['library']['visibility'] ?? $visibility),
            ];
        }
        usort($items, static fn(array $a, array $b): int => $b['version'] <=> $a['version']);
        return $items;
    }

    /** @return array<string,mixed> */
    public function rename(string $id, string $name, string $description, string $visibility, ?string $projectId = null): array
    {
        $template = $this->mustGetInScope($id, $visibility, $projectId);
        $this->snapshot($template, $visibility, $projectId, 'before-rename');
        $template['name'] = trim($name);
        $template['description'] = trim($description);
        if ($template['name'] === '') { throw new \InvalidArgumentException('Vorlagenname darf nicht leer sein.'); }
        $template['library']['version'] = $this->nextVersionNumber($id, $visibility, $projectId);
        $saved = $this->store->save($template, $visibility, $projectId);
        $this->snapshot($saved, $visibility, $projectId, 'rename');
        return $saved;
    }

    /** @return array<string,mixed> */
    public function copy(string $id, string $newName, string $visibility, ?string $sourceProject, string $targetVisibility, ?string $targetProject): array
    {
        $template = $this->mustGetInScope($id, $visibility, $sourceProject);
        $newId = $this->store->makeId($newName, $targetProject, $targetVisibility);
        unset($template['createdAt'], $template['updatedAt']);
        $template['id'] = $newId;
        $template['name'] = trim($newName) !== '' ? trim($newName) : ((string)$template['name'] . ' Kopie');
        $template['library'] = ['visibility'=>$targetVisibility,'ownerProject'=>$targetVisibility === 'project' ? $targetProject : null,'version'=>1,'copiedFrom'=>$id];
        $saved = $this->store->save($template, $targetVisibility, $targetProject);
        $this->snapshot($saved, $targetVisibility, $targetProject, 'copy');
        return $saved;
    }

    /** @return array<string,mixed> */
    public function changeVisibility(string $id, string $sourceVisibility, ?string $sourceProject, string $targetVisibility, ?string $targetProject): array
    {
        $template = $this->mustGetInScope($id, $sourceVisibility, $sourceProject);
        if ($sourceVisibility === $targetVisibility && ($sourceVisibility !== 'project' || $sourceProject === $targetProject)) { return $template; }
        if ($this->store->getInScope($id, $targetVisibility, $targetProject) !== []) {
            throw new \RuntimeException('Im Zielbereich existiert bereits eine Vorlage mit derselben ID.');
        }
        $this->snapshot($template, $sourceVisibility, $sourceProject, 'before-visibility-change');
        $this->copyVersionHistory($id, $sourceVisibility, $sourceProject, $targetVisibility, $targetProject);
        $template['library']['visibility'] = $targetVisibility;
        $template['library']['ownerProject'] = $targetVisibility === 'project' ? $targetProject : null;
        $template['library']['version'] = $this->nextVersionNumber($id, $targetVisibility, $targetProject);
        $saved = $this->store->save($template, $targetVisibility, $targetProject);
        $this->store->delete($id, $sourceVisibility, $sourceProject);
        $this->snapshot($saved, $targetVisibility, $targetProject, 'visibility-change');
        return $saved;
    }

    /** @return array<string,mixed> */
    public function softDelete(string $id, string $visibility, ?string $projectId, string $confirmedName): array
    {
        $template = $this->mustGetInScope($id, $visibility, $projectId);
        if (trim($confirmedName) !== trim((string)($template['name'] ?? ''))) {
            throw new \RuntimeException('Der eingegebene Vorlagenname stimmt nicht überein. Löschen abgebrochen.');
        }
        $this->snapshot($template, $visibility, $projectId, 'before-delete');
        $trash = $this->trashDirectory($visibility, $projectId, true);
        if ($trash === null) { throw new \RuntimeException('Vorlagen-Papierkorb kann nicht erstellt werden.'); }
        $template['library']['deletedAt'] = gmdate('c');
        $template['library']['deletedFrom'] = ['visibility'=>$visibility,'projectId'=>$projectId];
        $template['library']['version'] = $this->nextVersionNumber($id, $visibility, $projectId);
        $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($trash . '/' . $id . '-' . gmdate('Ymd-His') . '.json', $json, LOCK_EX) === false) {
            throw new \RuntimeException('Vorlage kann nicht in den Papierkorb verschoben werden.');
        }
        if (!$this->store->delete($id, $visibility, $projectId)) { throw new \RuntimeException('Vorlage konnte nach der Sicherung nicht aus der Bibliothek entfernt werden.'); }
        return ['deleted'=>true,'id'=>$id,'name'=>$template['name'],'trash'=>$trash];
    }

    /** @return array<string,mixed> */
    public function importJson(string $json, string $visibility, ?string $projectId, ?string $nameOverride = null): array
    {
        if (strlen($json) > 2_000_000) { throw new \RuntimeException('Importdatei ist zu groß.'); }
        $payload = json_decode($json, true);
        if (!is_array($payload) || ($payload['schema'] ?? '') !== 'easyit.assistant.dataform-template.v1') {
            throw new \RuntimeException('Import ist keine gültige easyIT-DataForm-Vorlage.');
        }
        if (!is_array($payload['bundle'] ?? null) || empty($payload['bundle']['dataform.create'])) {
            throw new \RuntimeException('Importvorlage enthält keine DataForm-Konfiguration.');
        }
        $payload = $this->sanitizer->sanitize($payload);
        $baseName = trim((string)($nameOverride ?? '')) !== '' ? trim((string)$nameOverride) : trim((string)($payload['name'] ?? 'Importierte Vorlage'));
        $payload['name'] = $baseName;
        $payload['id'] = $this->store->makeId($baseName, $projectId, $visibility);
        unset($payload['createdAt'], $payload['updatedAt']);
        $payload['library'] = ['visibility'=>$visibility,'ownerProject'=>$visibility === 'project' ? $projectId : null,'version'=>1,'importedAt'=>gmdate('c')];
        $saved = $this->store->save($payload, $visibility, $projectId);
        $this->snapshot($saved, $visibility, $projectId, 'import');
        return $saved;
    }

    public function exportJson(string $id, ?string $projectId = null): string
    {
        $template = $this->store->get($id, $projectId);
        if ($template === []) { throw new \RuntimeException('Vorlage wurde nicht gefunden.'); }
        $json = json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('Vorlage kann nicht exportiert werden.'); }
        return $json . "\n";
    }

    /** @return array<string,mixed> */
    private function mustGetInScope(string $id, string $visibility, ?string $projectId): array
    {
        $template = $this->store->getInScope($id, $visibility, $projectId);
        if ($template === []) { throw new \RuntimeException('Vorlage wurde im angegebenen Bereich nicht gefunden.'); }
        if (!isset($template['library'])) {
            $template['library'] = ['visibility'=>$visibility,'ownerProject'=>$visibility === 'project' ? $projectId : null,'version'=>1];
        }
        return $template;
    }

    /** @param array<string,mixed> $template */
    private function snapshot(array $template, string $visibility, ?string $projectId, string $reason): void
    {
        $id = (string)($template['id'] ?? '');
        if ($id === '') { return; }
        $dir = $this->versionDirectory($id, $visibility, $projectId, true);
        if ($dir === null) { throw new \RuntimeException('Vorlagenversionsverzeichnis kann nicht erstellt werden.'); }
        $number = $this->nextVersionNumber($id, $visibility, $projectId);
        $template['_version'] = ['number'=>$number,'reason'=>$reason,'createdAt'=>gmdate('c')];
        if (isset($template['library']) && is_array($template['library'])) { $template['library']['version'] = $number; }
        $json = json_encode($this->sanitizer->sanitize($template), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $path = $dir . '/v' . str_pad((string)$number, 6, '0', STR_PAD_LEFT) . '.json';
        if ($json === false || @file_put_contents($path, $json, LOCK_EX) === false) { throw new \RuntimeException('Vorlagenversion kann nicht gespeichert werden.'); }
    }

    private function nextVersionNumber(string $id, string $visibility, ?string $projectId): int
    {
        $dir = $this->versionDirectory($id, $visibility, $projectId, false);
        $max = 0;
        if ($dir !== null && is_dir($dir)) {
            foreach (glob($dir . '/v*.json') ?: [] as $path) {
                if (preg_match('/v(\d+)\.json$/', basename($path), $m)) { $max = max($max, (int)$m[1]); }
            }
        }
        return $max + 1;
    }

    private function copyVersionHistory(string $id, string $sourceVisibility, ?string $sourceProject, string $targetVisibility, ?string $targetProject): void
    {
        $source = $this->versionDirectory($id, $sourceVisibility, $sourceProject, false);
        if ($source === null || !is_dir($source)) { return; }
        $target = $this->versionDirectory($id, $targetVisibility, $targetProject, true);
        if ($target === null) { throw new \RuntimeException('Ziel-Versionsverzeichnis kann nicht erstellt werden.'); }
        foreach (glob($source . '/v*.json') ?: [] as $path) {
            $dest = $target . '/' . basename($path);
            if (!is_file($dest) && !@copy($path, $dest)) { throw new \RuntimeException('Vorlagenhistorie kann nicht in den neuen Freigabebereich übernommen werden.'); }
        }
    }

    private function versionDirectory(string $id, string $visibility, ?string $projectId, bool $create): ?string
    {
        $base = $this->store->baseDirectory($visibility, $projectId, $create);
        if ($base === null) { return null; }
        $dir = $base . '/.versions/' . $id;
        if ($create && !is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return null; }
        return $dir;
    }

    private function trashDirectory(string $visibility, ?string $projectId, bool $create): ?string
    {
        $base = $this->store->baseDirectory($visibility, $projectId, $create);
        if ($base === null) { return null; }
        $dir = $base . '/.trash';
        if ($create && !is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) { return null; }
        return $dir;
    }
}
