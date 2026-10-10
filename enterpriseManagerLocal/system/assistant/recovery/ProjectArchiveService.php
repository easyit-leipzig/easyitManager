<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Recovery;

final class ProjectArchiveService
{
    private string $enterpriseRoot;
    private string $projectsRoot;
    private string $backupRoot;

    public function __construct(string $enterpriseRoot)
    {
        $this->enterpriseRoot = rtrim($enterpriseRoot, DIRECTORY_SEPARATOR);
        $this->projectsRoot = $this->enterpriseRoot . DIRECTORY_SEPARATOR . 'projects';
        $this->backupRoot = $this->enterpriseRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'projects';
    }

    public function backup(string $projectId, bool $includeDatabase = true, bool $includeBackups = false): ArchiveOperationResult
    {
        try {
            $projectId = $this->validateProjectId($projectId);
            $project = $this->projectPath($projectId);
            if (!is_dir($project)) {
                return new ArchiveOperationResult(false, 'Projekt wurde nicht gefunden: ' . $projectId);
            }
            if (!is_dir($this->backupRoot) && !mkdir($this->backupRoot, 0775, true) && !is_dir($this->backupRoot)) {
                throw new \RuntimeException('Zentrales Backup-Verzeichnis konnte nicht angelegt werden.');
            }

            $warnings = [];
            $database = $this->databaseDescriptor($projectId, $includeDatabase);
            $warnings = array_merge($warnings, $database['warnings']);
            $stamp = gmdate('Ymd-His');
            $token = bin2hex(random_bytes(5));
            $archive = $this->backupRoot . DIRECTORY_SEPARATOR . $projectId . '-' . $stamp . '-' . $token . '.zip';
            if (file_exists($archive)) { throw new \RuntimeException('Backup-Zieldatei existiert bereits.'); }

            $files = [];
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($project, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) { continue; }
                if ($fileInfo->isLink()) {
                    $warnings[] = 'Symbolischer Link wurde aus Sicherheitsgründen ausgelassen: ' . $fileInfo->getPathname();
                    continue;
                }
                $absolute = $fileInfo->getPathname();
                $relative = $this->relativeTo($project, $absolute);
                if (!$includeBackups && ($relative === 'backups' || str_starts_with($relative, 'backups/'))) { continue; }
                if (!$includeDatabase && $this->matchesDatabasePath($projectId, $relative, $database)) { continue; }
                $entry = 'project/' . $relative;
                $files[$entry] = ['absolute' => $absolute, 'sha256' => hash_file('sha256', $absolute), 'size' => filesize($absolute) ?: 0];
            }

            if ($includeDatabase && !empty($database['externalDumpAbsolute']) && is_file((string) $database['externalDumpAbsolute'])) {
                $dump = (string) $database['externalDumpAbsolute'];
                $entry = 'database/' . basename($dump);
                $files[$entry] = ['absolute' => $dump, 'sha256' => hash_file('sha256', $dump), 'size' => filesize($dump) ?: 0];
                $database['included'] = true;
                $database['entry'] = $entry;
            }

            if ($files === []) { throw new \RuntimeException('Projekt enthält keine sicherbaren Dateien.'); }

            $manifest = [
                'schema' => 'easyit.project.backup.v1',
                'createdAt' => gmdate('c'),
                'projectId' => $projectId,
                'projectPath' => 'projects/' . $projectId,
                'includeBackups' => $includeBackups,
                'database' => [
                    'requested' => $includeDatabase,
                    'included' => (bool) ($database['included'] ?? false),
                    'driver' => (string) ($database['driver'] ?? ''),
                    'mode' => (string) ($database['mode'] ?? 'unknown'),
                    'entry' => $database['entry'] ?? null,
                ],
                'files' => array_map(static fn (array $f): array => ['sha256' => $f['sha256'], 'size' => $f['size']], $files),
            ];

            $phar = new \PharData($archive, 0, null, \Phar::ZIP);
            foreach ($files as $entry => $meta) {
                $phar->addFile((string) $meta['absolute'], $entry);
            }
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) { throw new \RuntimeException('Backup-Manifest konnte nicht erzeugt werden.'); }
            $phar->addFromString('easyit-backup-manifest.json', $json . "\n");
            unset($phar);

            $sha = hash_file('sha256', $archive);
            if (!is_string($sha)) { throw new \RuntimeException('SHA-256 des Backups konnte nicht erzeugt werden.'); }
            file_put_contents($archive . '.sha256', $sha . '  ' . basename($archive) . "\n", LOCK_EX);

            return new ArchiveOperationResult(true, 'Projekt-Backup erfolgreich erzeugt.', $archive, $sha, array_values(array_unique($warnings)), [
                'projectId' => $projectId,
                'fileCount' => count($files),
                'manifest' => $manifest,
                'shaFile' => $archive . '.sha256',
            ]);
        } catch (\Throwable $e) {
            return new ArchiveOperationResult(false, 'Backup fehlgeschlagen: ' . $e->getMessage());
        }
    }

    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int,type?:string} $upload
     */
    public function restoreUploaded(array $upload, string $targetProjectId = ''): ArchiveOperationResult
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return new ArchiveOperationResult(false, 'Restore-ZIP wurde nicht korrekt hochgeladen (Upload-Code ' . $error . ').');
        }
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            return new ArchiveOperationResult(false, 'Temporäre Restore-Datei fehlt.');
        }
        $name = (string) ($upload['name'] ?? 'restore.zip');
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            return new ArchiveOperationResult(false, 'Restore-Datei muss ein ZIP-Archiv sein.');
        }
        $copy = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'easyit-restore-' . bin2hex(random_bytes(8)) . '.zip';
        if (!copy($tmp, $copy)) {
            return new ArchiveOperationResult(false, 'Restore-ZIP konnte nicht in den Prüfbereich kopiert werden.');
        }
        try {
            return $this->restore($copy, $targetProjectId);
        } finally {
            @unlink($copy);
        }
    }

    public function restore(string $archive, string $targetProjectId = ''): ArchiveOperationResult
    {
        $staging = null;
        try {
            if (!is_file($archive)) { throw new \RuntimeException('Restore-Archiv wurde nicht gefunden.'); }
            $inspection = $this->inspect($archive);
            if (!$inspection['ok']) {
                return new ArchiveOperationResult(false, 'Restore-Prüfung fehlgeschlagen: ' . implode(' ', $inspection['errors']), null, null, $inspection['warnings'], ['inspection' => $inspection]);
            }
            $manifest = $inspection['manifest'];
            $sourceId = $this->validateProjectId((string) ($manifest['projectId'] ?? ''));
            $targetId = trim($targetProjectId) !== '' ? $this->validateProjectId($targetProjectId) : $sourceId;
            $target = $this->projectPath($targetId);
            if (file_exists($target) || is_dir($target)) {
                return new ArchiveOperationResult(false, 'Restore-Konflikt: Projekt existiert bereits: ' . $targetId, null, null, [], ['conflictProjectId' => $targetId]);
            }

            $recoveryRoot = $this->enterpriseRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'recovery';
            if (!is_dir($recoveryRoot) && !mkdir($recoveryRoot, 0775, true) && !is_dir($recoveryRoot)) {
                throw new \RuntimeException('Recovery-Staging-Verzeichnis konnte nicht angelegt werden.');
            }
            $staging = $recoveryRoot . DIRECTORY_SEPARATOR . $targetId . '-' . bin2hex(random_bytes(6));
            if (!mkdir($staging, 0775, true) && !is_dir($staging)) { throw new \RuntimeException('Recovery-Staging konnte nicht angelegt werden.'); }

            $phar = new \PharData($archive);
            foreach (($manifest['files'] ?? []) as $entry => $meta) {
                $entry = (string) $entry;
                $this->assertSafeEntry($entry);
                if (!isset($phar[$entry])) { throw new \RuntimeException('Manifest-Datei fehlt im ZIP: ' . $entry); }
                $content = $phar[$entry]->getContent();
                if (!is_string($content)) { throw new \RuntimeException('ZIP-Eintrag konnte nicht gelesen werden: ' . $entry); }
                $expected = strtolower((string) ($meta['sha256'] ?? ''));
                if ($expected === '' || !hash_equals($expected, hash('sha256', $content))) {
                    throw new \RuntimeException('Prüfsummenfehler im ZIP: ' . $entry);
                }

                if (str_starts_with($entry, 'project/')) {
                    $relative = substr($entry, strlen('project/'));
                } elseif (str_starts_with($entry, 'database/')) {
                    $relative = 'backups/database/' . basename($entry);
                } else {
                    throw new \RuntimeException('Nicht erlaubter Backup-Eintrag: ' . $entry);
                }
                $this->assertSafeRelative($relative);
                $destination = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $dir = dirname($destination);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Restore-Verzeichnis konnte nicht angelegt werden: ' . $relative); }
                if (file_put_contents($destination, $content, LOCK_EX) === false) { throw new \RuntimeException('Restore-Datei konnte nicht geschrieben werden: ' . $relative); }
                if (!hash_equals($expected, (string) hash_file('sha256', $destination))) { throw new \RuntimeException('Prüfsummenfehler nach Restore: ' . $relative); }
            }
            unset($phar);

            $this->ensureRuntimeDirectories($staging);
            if ($targetId !== $sourceId) { $this->retargetConfiguration($staging, $sourceId, $targetId); }
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) { throw new \RuntimeException('projects/-Verzeichnis konnte nicht angelegt werden.'); }
            if (!rename($staging, $target)) { throw new \RuntimeException('Staging-Projekt konnte nicht nach projects/ verschoben werden.'); }
            $staging = null;

            $diag = (new ProjectRecoveryDiagnosticService($this->enterpriseRoot))->diagnose($targetId, $manifest);
            return new ArchiveOperationResult(!$diag->hasFailures(), $diag->hasFailures() ? 'Projekt wurde wiederhergestellt, Diagnose meldet jedoch Fehler.' : 'Projekt erfolgreich wiederhergestellt und geprüft.', $target, null, $inspection['warnings'], [
                'sourceProjectId' => $sourceId,
                'targetProjectId' => $targetId,
                'manifest' => $manifest,
                'diagnosticReport' => $diag->jsonSerialize(),
            ]);
        } catch (\Throwable $e) {
            if ($staging !== null && is_dir($staging)) { $this->removeTree($staging); }
            return new ArchiveOperationResult(false, 'Restore fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function restoreInPlace(string $archive, string $projectId): ArchiveOperationResult
    {
        $staging = null;
        $replaced = null;
        try {
            $projectId = $this->validateProjectId($projectId);
            $target = $this->projectPath($projectId);
            if (!is_dir($target)) { throw new \RuntimeException('Rollback-Zielprojekt wurde nicht gefunden: ' . $projectId); }
            $inspection = $this->inspect($archive);
            if (!$inspection['ok']) {
                return new ArchiveOperationResult(false, 'Rollback-Prüfung fehlgeschlagen: ' . implode(' ', $inspection['errors']), null, null, $inspection['warnings'], ['inspection' => $inspection]);
            }
            $manifest = $inspection['manifest'];
            $sourceId = $this->validateProjectId((string)($manifest['projectId'] ?? ''));
            if ($sourceId !== $projectId) { throw new \RuntimeException('Rollback-Checkpoint gehört zu einem anderen Projekt: ' . $sourceId); }

            $recoveryRoot = $this->enterpriseRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'recovery';
            if (!is_dir($recoveryRoot) && !mkdir($recoveryRoot, 0775, true) && !is_dir($recoveryRoot)) { throw new \RuntimeException('Recovery-Staging-Verzeichnis konnte nicht angelegt werden.'); }
            $token = bin2hex(random_bytes(6));
            $staging = $recoveryRoot . DIRECTORY_SEPARATOR . $projectId . '-rollback-staging-' . $token;
            $replaced = $recoveryRoot . DIRECTORY_SEPARATOR . $projectId . '-rollback-replaced-' . $token;
            if (!mkdir($staging, 0775, true) && !is_dir($staging)) { throw new \RuntimeException('Rollback-Staging konnte nicht angelegt werden.'); }

            $phar = new \PharData($archive);
            foreach (($manifest['files'] ?? []) as $entry => $meta) {
                $entry = (string)$entry;
                $this->assertSafeEntry($entry);
                if (!isset($phar[$entry])) { throw new \RuntimeException('Manifest-Datei fehlt im Rollback-ZIP: ' . $entry); }
                $content = $phar[$entry]->getContent();
                if (!is_string($content)) { throw new \RuntimeException('ZIP-Eintrag konnte nicht gelesen werden: ' . $entry); }
                $expected = strtolower((string)($meta['sha256'] ?? ''));
                if ($expected === '' || !hash_equals($expected, hash('sha256', $content))) { throw new \RuntimeException('Prüfsummenfehler im Rollback-ZIP: ' . $entry); }
                if (str_starts_with($entry, 'project/')) {
                    $relative = substr($entry, strlen('project/'));
                } elseif (str_starts_with($entry, 'database/')) {
                    // Externe Dumps werden nur als Recovery-Artefakt zurückgelegt; ein DB-Import erfolgt bewusst nicht automatisch.
                    $relative = 'backups/database/' . basename($entry);
                } else { throw new \RuntimeException('Nicht erlaubter Rollback-Eintrag: ' . $entry); }
                $this->assertSafeRelative($relative);
                $destination = $staging . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $dir = dirname($destination);
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Rollback-Verzeichnis konnte nicht angelegt werden: ' . $relative); }
                if (file_put_contents($destination, $content, LOCK_EX) === false) { throw new \RuntimeException('Rollback-Datei konnte nicht geschrieben werden: ' . $relative); }
                if (!hash_equals($expected, (string)hash_file('sha256', $destination))) { throw new \RuntimeException('Prüfsummenfehler nach Rollback-Extraktion: ' . $relative); }
            }
            unset($phar);
            $this->ensureRuntimeDirectories($staging);

            if (!rename($target, $replaced)) { throw new \RuntimeException('Aktueller Projektstand konnte nicht in den Recovery-Bereich verschoben werden.'); }
            if (!rename($staging, $target)) {
                @rename($replaced, $target);
                throw new \RuntimeException('Rollback-Staging konnte nicht als aktives Projekt eingesetzt werden. Der bisherige Stand wurde wiederhergestellt.');
            }
            $staging = null;

            $diag = (new ProjectRecoveryDiagnosticService($this->enterpriseRoot))->diagnose($projectId, $manifest);
            return new ArchiveOperationResult(true, $diag->hasFailures() ? 'Rollback wurde vollständig eingespielt; die nachgelagerte Recovery-Diagnose meldet zusätzliche Projektkonfigurationsfehler.' : 'Projekt erfolgreich auf den Recovery-Checkpoint zurückgesetzt und geprüft.', $target, null, $inspection['warnings'], [
                'projectId' => $projectId,
                'manifest' => $manifest,
                'replacedProjectPath' => $replaced,
                'diagnosticReport' => $diag->jsonSerialize(),
            ]);
        } catch (\Throwable $e) {
            if ($staging !== null && is_dir($staging)) { $this->removeTree($staging); }
            return new ArchiveOperationResult(false, 'Rollback fehlgeschlagen: ' . $e->getMessage(), null, null, [], ['replacedProjectPath' => $replaced]);
        }
    }

    /** @return array{ok:bool,manifest:array,errors:list<string>,warnings:list<string>} */
    public function inspect(string $archive): array
    {
        $errors = [];
        $warnings = [];
        $manifest = [];
        try {
            $phar = new \PharData($archive);
            if (!isset($phar['easyit-backup-manifest.json'])) {
                throw new \RuntimeException('easyit-backup-manifest.json fehlt.');
            }
            $decoded = json_decode((string) $phar['easyit-backup-manifest.json']->getContent(), true);
            if (!is_array($decoded)) { throw new \RuntimeException('Backup-Manifest ist ungültiges JSON.'); }
            $manifest = $decoded;
            if (($manifest['schema'] ?? '') !== 'easyit.project.backup.v1') { $errors[] = 'Unbekanntes Backup-Schema.'; }
            try { $this->validateProjectId((string) ($manifest['projectId'] ?? '')); } catch (\Throwable $e) { $errors[] = $e->getMessage(); }
            $files = $manifest['files'] ?? null;
            if (!is_array($files) || $files === []) { $errors[] = 'Backup-Manifest enthält keine Dateien.'; }
            if (is_array($files)) {
                foreach ($files as $entry => $meta) {
                    try { $this->assertSafeEntry((string) $entry); } catch (\Throwable $e) { $errors[] = $e->getMessage(); continue; }
                    if (!isset($phar[(string) $entry])) { $errors[] = 'Manifest-Eintrag fehlt: ' . $entry; continue; }
                    $content = $phar[(string) $entry]->getContent();
                    $sha = strtolower((string) ($meta['sha256'] ?? ''));
                    if ($sha === '' || !is_string($content) || !hash_equals($sha, hash('sha256', $content))) { $errors[] = 'Prüfsummenfehler: ' . $entry; }
                }
            }
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
        return ['ok' => $errors === [], 'manifest' => $manifest, 'errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }

    public function getBackupRoot(): string { return $this->backupRoot; }

    private function databaseDescriptor(string $projectId, bool $requested): array
    {
        $project = $this->projectPath($projectId);
        $projectConfig = $this->readJson($project . '/config/project.json');
        $datasource = $this->readJson($project . '/config/datasource.json');
        $driver = strtolower((string) ($datasource['profile']['driver'] ?? ($projectConfig['storage']['driver'] ?? '')));
        $path = (string) ($datasource['connection']['path'] ?? ($projectConfig['storage']['localPath'] ?? ''));
        $descriptor = [
            'requested' => $requested,
            'included' => false,
            'driver' => $driver,
            'mode' => in_array($driver, ['sqlite', 'csv'], true) ? 'local' : 'external',
            'relativePath' => '',
            'warnings' => [],
            'entry' => null,
            'externalDumpAbsolute' => null,
        ];
        if (in_array($driver, ['sqlite', 'csv'], true) && $path !== '') {
            $absolute = $this->absoluteEnterprisePath($path);
            if ($this->isInside($absolute, $project)) {
                $descriptor['relativePath'] = $this->relativeTo($project, $absolute);
                $exists = $driver === 'sqlite' ? is_file($absolute) : is_dir($absolute);
                $descriptor['included'] = $requested && $exists;
                if ($requested && !$exists) { $descriptor['warnings'][] = 'Lokale Projektdatenbank wurde angefordert, aber nicht gefunden: ' . $path; }
            } else {
                $descriptor['warnings'][] = 'Lokaler Datenbankpfad liegt außerhalb des Projektverzeichnisses und wird nicht automatisch eingebettet.';
            }
        } elseif (in_array($driver, ['mysql', 'pgsql', 'oracle', 'mssql'], true)) {
            $dumpPath = (string) ($datasource['backup']['dumpPath'] ?? '');
            if ($requested && $dumpPath !== '') {
                $absolute = $this->absoluteEnterprisePath($dumpPath);
                if ($this->isInside($absolute, $this->enterpriseRoot) && is_file($absolute)) {
                    $descriptor['externalDumpAbsolute'] = $absolute;
                } else {
                    $descriptor['warnings'][] = 'Konfigurierter externer Datenbank-Dump wurde nicht gefunden oder liegt außerhalb des Enterprise-Verzeichnisses.';
                }
            } elseif ($requested) {
                $descriptor['warnings'][] = 'Externe ' . strtoupper($driver) . '-Datenbank kann ohne konfigurierten backup.dumpPath nicht physisch in das Projekt-ZIP aufgenommen werden.';
            }
        }
        return $descriptor;
    }

    private function matchesDatabasePath(string $projectId, string $relativeFile, array $database): bool
    {
        $dbRel = trim(str_replace('\\', '/', (string) ($database['relativePath'] ?? '')), '/');
        if ($dbRel === '') { return false; }
        $relativeFile = trim(str_replace('\\', '/', $relativeFile), '/');
        return $relativeFile === $dbRel || str_starts_with($relativeFile, $dbRel . '/');
    }

    private function retargetConfiguration(string $staging, string $sourceId, string $targetId): void
    {
        $projectFile = $staging . '/config/project.json';
        $project = $this->readJson($projectFile);
        if ($project !== []) {
            $project['project']['id'] = $targetId;
            $project['project']['path'] = 'projects/' . $targetId;
            if (isset($project['storage']['localPath']) && is_string($project['storage']['localPath'])) {
                $project['storage']['localPath'] = $this->replaceProjectPrefix($project['storage']['localPath'], $sourceId, $targetId);
            }
            $this->writeJson($projectFile, $project);
        }
        $datasourceFile = $staging . '/config/datasource.json';
        $datasource = $this->readJson($datasourceFile);
        if ($datasource !== []) {
            if (isset($datasource['connection']['path']) && is_string($datasource['connection']['path'])) {
                $datasource['connection']['path'] = $this->replaceProjectPrefix($datasource['connection']['path'], $sourceId, $targetId);
            }
            $this->writeJson($datasourceFile, $datasource);
        }
        $this->retargetAssistantStates($staging, $sourceId, $targetId);
        $this->retargetAssistantHistories($staging, $sourceId, $targetId);
        $this->retargetModuleInstallations($staging, $sourceId, $targetId);
        $this->retargetModuleMigrationRecords($staging, $sourceId, $targetId);
    }

    private function retargetAssistantStates(string $staging, string $sourceId, string $targetId): void
    {
        $stateDir = $staging . '/config/assistant/state';
        if (!is_dir($stateDir)) { return; }
        $files = glob($stateDir . '/*.json') ?: [];
        foreach ($files as $file) {
            $payload = $this->readJson($file);
            if (($payload['schema'] ?? '') !== 'easyit.assistant.state.v1') { continue; }
            $assistantId = (string) ($payload['assistantId'] ?? '');
            $scope = (string) ($payload['scope'] ?? 'default');
            if ($assistantId === '') { continue; }
            if (($payload['projectId'] ?? '') === $sourceId) { $payload['projectId'] = $targetId; }
            if ($scope === $sourceId) {
                $scope = $targetId;
            } elseif (str_starts_with($scope, $sourceId . '|')) {
                $scope = $targetId . substr($scope, strlen($sourceId));
            }
            $payload['scope'] = $scope;
            if (is_array($payload['state'] ?? null)) {
                $payload['state'] = $this->retargetAssistantStateValue($payload['state'], $sourceId, $targetId);
            }
            $assistantSafe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $assistantId) ?: 'assistant';
            $newFile = $stateDir . '/' . $assistantSafe . '--' . substr(hash('sha256', $scope), 0, 20) . '.json';
            $this->writeJson($newFile, $payload);
            if ($newFile !== $file) { @unlink($file); }
        }
    }

    private function retargetAssistantHistories(string $staging, string $sourceId, string $targetId): void
    {
        $historyRoot = $staging . '/config/assistant/history';
        if (!is_dir($historyRoot)) { return; }
        $dirs = glob($historyRoot . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $indexFile = $dir . '/history.json';
            $index = $this->readJson($indexFile);
            if (($index['schema'] ?? '') !== 'easyit.assistant.history.v1') { continue; }
            $assistantId = (string) ($index['assistantId'] ?? '');
            $scope = (string) ($index['scope'] ?? 'default');
            if ($assistantId === '') { continue; }
            if (($index['projectId'] ?? '') === $sourceId) { $index['projectId'] = $targetId; }
            if ($scope === $sourceId) {
                $scope = $targetId;
            } elseif (str_starts_with($scope, $sourceId . '|')) {
                $scope = $targetId . substr($scope, strlen($sourceId));
            }
            $index['scope'] = $scope;

            $versionsDir = $dir . '/versions';
            $checksums = [];
            foreach (glob($versionsDir . '/*.json') ?: [] as $versionFile) {
                $version = $this->readJson($versionFile);
                if (($version['schema'] ?? '') !== 'easyit.assistant.history-version.v1') { continue; }
                if (($version['projectId'] ?? '') === $sourceId) { $version['projectId'] = $targetId; }
                $version['scope'] = $scope;
                if (is_array($version['state'] ?? null)) {
                    $version['state'] = $this->retargetAssistantStateValue($version['state'], $sourceId, $targetId);
                    $checksum = hash('sha256', (string) json_encode($version['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    if (is_array($version['meta'] ?? null)) { $version['meta']['checksum'] = $checksum; }
                    $versionId = (string) ($version['meta']['id'] ?? basename($versionFile, '.json'));
                    if ($versionId !== '') { $checksums[$versionId] = $checksum; }
                }
                $this->writeJson($versionFile, $version);
            }
            if (is_array($index['entries'] ?? null)) {
                foreach ($index['entries'] as &$entry) {
                    if (is_array($entry) && isset($checksums[(string) ($entry['id'] ?? '')])) {
                        $entry['checksum'] = $checksums[(string) $entry['id']];
                    }
                }
                unset($entry);
            }

            $this->writeJson($indexFile, $index);
            $assistantSafe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $assistantId) ?: 'assistant';
            $newDir = $historyRoot . '/' . $assistantSafe . '--' . substr(hash('sha256', $scope), 0, 20);
            if ($newDir !== $dir) {
                if (is_dir($newDir)) { throw new \RuntimeException('Historienziel existiert bereits: ' . basename($newDir)); }
                if (!@rename($dir, $newDir)) { throw new \RuntimeException('Assistentenhistorie konnte nicht retargetet werden.'); }
            }
        }
    }

    private function retargetModuleInstallations(string $staging,string $sourceId,string $targetId):void
    {
        $file=$staging.'/config/assistant/module-installations.json';$doc=$this->readJson($file);if(($doc['schema']??'')!=='easyit.assistant.module-installations.v1')return;$doc['projectId']=$targetId;
        if(is_array($doc['modules']??null))foreach($doc['modules'] as &$module){if(!is_array($module))continue;if(isset($module['sourceLocator'])&&is_string($module['sourceLocator']))$module['sourceLocator']=str_replace('project::'.$sourceId.'::','project::'.$targetId.'::',$module['sourceLocator']);}unset($module);$doc['updatedAt']=gmdate('c');$this->writeJson($file,$doc);
    }

    private function retargetModuleMigrationRecords(string $staging,string $sourceId,string $targetId):void
    {
        $legacy=$staging.'/config/assistant/module-migrations.json';$doc=$this->readJson($legacy);if(($doc['schema']??'')==='easyit.assistant.module-migrations.v1'){$doc['projectId']=$targetId;$doc['updatedAt']=gmdate('c');$this->writeJson($legacy,$doc);}
        $index=$staging.'/config/assistant/module-migration-runs.json';$idx=$this->readJson($index);if(($idx['schema']??'')==='easyit.assistant.module-migration-runs.v1'){$idx['projectId']=$targetId;$idx['updatedAt']=gmdate('c');$this->writeJson($index,$idx);}
        $dir=$staging.'/config/assistant/module-migration-runs';if(!is_dir($dir))return;
        foreach(glob($dir.'/*.json')?:[] as $file){$run=$this->readJson($file);if(($run['schema']??'')!=='easyit.assistant.module-migration-run.v1')continue;$run['projectId']=$targetId;foreach(['beforeSnapshot','afterSnapshot'] as $k){if(is_array($run[$k]??null)){$run[$k]['projectId']=$targetId;if(is_array($run[$k]['moduleInstallations']??null))$run[$k]['moduleInstallations']['projectId']=$targetId;}}if(is_array($run['checkpoint']??null)){$run['checkpoint']['retargetedFromProject']=$sourceId;$run['checkpoint']['rollbackAvailable']=false;$run['checkpoint']['rollbackNote']='Checkpoint stammt aus dem ursprünglichen Projekt und wird nach Restore unter neuer Projekt-ID nicht automatisch als In-Place-Rollback angeboten.';}$this->writeJson($file,$run);}
    }

    private function retargetAssistantStateValue(mixed $value, string $sourceId, string $targetId, ?string $key = null): mixed
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->retargetAssistantStateValue($v, $sourceId, $targetId, is_string($k) ? $k : null);
            }
            return $out;
        }
        if (!is_string($value)) { return $value; }
        if (in_array($key, ['projectId', 'project_id'], true) && $value === $sourceId) { return $targetId; }
        return $this->replaceProjectPrefix($value, $sourceId, $targetId);
    }

    private function replaceProjectPrefix(string $path, string $sourceId, string $targetId): string
    {
        $normalized = str_replace('\\', '/', $path);
        $from = 'projects/' . $sourceId . '/';
        if (str_starts_with($normalized, $from)) { return 'projects/' . $targetId . '/' . substr($normalized, strlen($from)); }
        return $path;
    }

    private function ensureRuntimeDirectories(string $project): void
    {
        foreach (['config', 'data', 'storage', 'logs', 'backups'] as $dir) {
            $path = $project . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) { throw new \RuntimeException('Standardverzeichnis konnte nicht hergestellt werden: ' . $dir); }
        }
    }

    private function validateProjectId(string $projectId): string
    {
        $projectId = trim($projectId);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,62}$/', $projectId)) { throw new \InvalidArgumentException('Ungültige Projekt-ID. Erlaubt sind Kleinbuchstaben, Ziffern, "_" und "-" (2 bis 63 Zeichen).'); }
        return $projectId;
    }

    private function projectPath(string $projectId): string { return $this->projectsRoot . DIRECTORY_SEPARATOR . $projectId; }
    private function absoluteEnterprisePath(string $path): string
    {
        if ($this->isAbsolute($path)) { return $path; }
        return $this->enterpriseRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
    }
    private function isAbsolute(string $path): bool { return str_starts_with($path, '/') || (bool) preg_match('/^[A-Za-z]:[\\\\\/]/', $path); }
    private function isInside(string $path, string $base): bool
    {
        $nPath = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $nBase = rtrim(str_replace('\\', '/', $base), '/') . '/';
        return str_starts_with($nPath, $nBase) && !str_contains($nPath, '/../');
    }
    private function relativeTo(string $base, string $path): string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $base)) { throw new \RuntimeException('Pfad liegt außerhalb des Basisverzeichnisses.'); }
        return ltrim(substr($path, strlen($base)), '/');
    }
    private function assertSafeEntry(string $entry): void
    {
        $this->assertSafeRelative($entry);
        if (!str_starts_with($entry, 'project/') && !str_starts_with($entry, 'database/')) { throw new \RuntimeException('ZIP-Eintrag außerhalb erlaubter Bereiche: ' . $entry); }
    }
    private function assertSafeRelative(string $relative): void
    {
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || str_starts_with($relative, '/') || preg_match('/^[A-Za-z]:\//', $relative) || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
            throw new \RuntimeException('Unsicherer Archivpfad erkannt: ' . $relative);
        }
    }
    private function readJson(string $file): array
    {
        if (!is_file($file)) { return []; }
        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : [];
    }
    private function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($file, $json . "\n", LOCK_EX) === false) { throw new \RuntimeException('Konfiguration konnte nicht aktualisiert werden: ' . $file); }
    }
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) { return; }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $item) {
            if ($item->isDir()) { @rmdir($item->getPathname()); } else { @unlink($item->getPathname()); }
        }
        @rmdir($dir);
    }
}
