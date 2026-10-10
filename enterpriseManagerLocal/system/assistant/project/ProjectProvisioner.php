<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Project;

final class ProjectProvisioner
{
    public function __construct(private string $enterpriseRoot)
    {
        $this->enterpriseRoot = rtrim($enterpriseRoot, DIRECTORY_SEPARATOR);
    }

    public function plan(ProjectDraft $draft): array
    {
        $d = $draft->toArray();
        $relativeProject = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $d['storage']['projectPath']);
        $project = $this->enterpriseRoot . DIRECTORY_SEPARATOR . $relativeProject;
        $structure = $d['structure'];
        $dirs = [
            $project,
            $project . DIRECTORY_SEPARATOR . $structure['configDir'],
            $project . DIRECTORY_SEPARATOR . $structure['dataDir'],
            $project . DIRECTORY_SEPARATOR . $structure['storageDir'],
            $project . DIRECTORY_SEPARATOR . $structure['logsDir'],
            $project . DIRECTORY_SEPARATOR . $structure['backupsDir'],
        ];
        $driver = (string) $d['storage']['driver'];
        if ($driver === 'csv') {
            $dirs[] = $this->absoluteFromProjectRelative((string) $d['dataSource']['localPath']);
        } elseif ($driver === 'sqlite') {
            $dirs[] = dirname($this->absoluteFromProjectRelative((string) $d['dataSource']['localPath']));
        }
        $files = [
            $project . DIRECTORY_SEPARATOR . $structure['configDir'] . DIRECTORY_SEPARATOR . 'project.json',
            $project . DIRECTORY_SEPARATOR . $structure['configDir'] . DIRECTORY_SEPARATOR . 'datasource.json',
            $project . DIRECTORY_SEPARATOR . 'README.md',
        ];
        if ($driver === 'sqlite') {
            $files[] = $this->absoluteFromProjectRelative((string) $d['dataSource']['localPath']);
        }
        return [
            'projectAbsolutePath' => $project,
            'directories' => array_values(array_unique($dirs)),
            'files' => $files,
        ];
    }

    public function provision(ProjectDraft $draft, ProjectConfigCompiler $compiler): ProjectProvisioningResult
    {
        $plan = $this->plan($draft);
        $projectPath = (string) $plan['projectAbsolutePath'];
        if (!$this->isInsideProjectsRoot($projectPath)) {
            return new ProjectProvisioningResult(false, 'Sicherheitsprüfung fehlgeschlagen: Ziel liegt außerhalb von projects/.');
        }
        if (is_dir($projectPath) || file_exists($projectPath)) {
            return new ProjectProvisioningResult(false, 'Projektverzeichnis existiert bereits: ' . $projectPath);
        }

        $createdDirs = [];
        $createdFiles = [];
        try {
            foreach ($plan['directories'] as $dir) {
                if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Verzeichnis konnte nicht angelegt werden: ' . $dir);
                }
                $createdDirs[] = $dir;
            }

            $config = $compiler->compile($draft);
            $config['provisioning'] = ['created' => true, 'createdAt' => gmdate('c')];
            $projectConfigFile = $plan['files'][0];
            $this->writeJson($projectConfigFile, $config);
            $createdFiles[] = $projectConfigFile;

            $datasourceConfig = $this->buildDataSourceSeed($draft);
            $dataSourceConfigFile = $plan['files'][1];
            $this->writeJson($dataSourceConfigFile, $datasourceConfig);
            $createdFiles[] = $dataSourceConfigFile;

            $readmeFile = $plan['files'][2];
            $readme = "# " . $config['project']['name'] . "\n\n"
                . "Projekt-ID: `" . $config['project']['id'] . "`\n\n"
                . "Dieses Projekt wurde durch den easyIT Enterprise Projekt-Assistenten erzeugt.\n"
                . "Als nächster Schritt muss das Datenquellenprofil im Datenquellen-Assistenten getestet und eine Hauptquelle ausgewählt werden.\n";
            if (file_put_contents($readmeFile, $readme, LOCK_EX) === false) {
                throw new \RuntimeException('README konnte nicht geschrieben werden.');
            }
            $createdFiles[] = $readmeFile;

            if ((string) $draft->toArray()['storage']['driver'] === 'sqlite') {
                $sqliteFile = $this->absoluteFromProjectRelative((string) $draft->toArray()['dataSource']['localPath']);
                if (!is_file($sqliteFile) && @touch($sqliteFile) === false) {
                    throw new \RuntimeException('SQLite-Datei konnte nicht angelegt werden: ' . $sqliteFile);
                }
                $createdFiles[] = $sqliteFile;
            }

            return new ProjectProvisioningResult(true, 'Projektstruktur erfolgreich angelegt.', $projectPath, $createdDirs, array_values(array_unique($createdFiles)));
        } catch (\Throwable $e) {
            $this->rollback($createdFiles, $createdDirs);
            return new ProjectProvisioningResult(false, 'Projektanlage fehlgeschlagen: ' . $e->getMessage(), $projectPath, $createdDirs, $createdFiles);
        }
    }

    public function buildDataSourceSeed(ProjectDraft $draft): array
    {
        $d = $draft->toArray();
        $driver = (string) $d['storage']['driver'];
        $connection = [
            'host' => '127.0.0.1',
            'port' => $driver === 'oracle' ? 1521 : ($driver === 'pgsql' ? 5432 : ($driver === 'mssql' ? 1433 : 3306)),
            'database' => in_array($driver, ['mysql','pgsql','mssql'], true) ? (string) $d['dataSource']['databaseName'] : '',
            'schema' => $driver === 'pgsql' ? (string)($d['dataSource']['schemaName'] ?? 'public') : '',
            'username' => '',
            'passwordRef' => '',
            'charset' => $driver === 'oracle' ? 'AL32UTF8' : ($driver === 'pgsql' ? 'UTF8' : ($driver === 'mssql' ? 'UTF-8' : 'utf8mb4')),
            'path' => in_array($driver, ['sqlite', 'csv'], true) ? (string) $d['dataSource']['localPath'] : '',
            'service' => $driver === 'oracle' ? 'XEPDB1' : '',
            'delimiter' => '|',
            'header' => true,
        ];
        return [
            'profile' => ['name' => (string) $d['dataSource']['profileName'], 'driver' => $driver],
            'connection' => $connection,
            'test' => ['ok' => false, 'message' => 'Noch nicht getestet.', 'details' => [], 'warnings' => [], 'testedAt' => null],
            'discovery' => [],
            'selection' => ['sourceName' => '', 'sourceType' => ''],
        ];
    }

    private function absoluteFromProjectRelative(string $relative): string
    {
        return $this->enterpriseRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relative, '/\\'));
    }

    private function isInsideProjectsRoot(string $path): bool
    {
        $base = $this->enterpriseRoot . DIRECTORY_SEPARATOR . 'projects' . DIRECTORY_SEPARATOR;
        $normalizedBase = str_replace('\\', '/', $base);
        $normalizedPath = str_replace('\\', '/', $path . DIRECTORY_SEPARATOR);
        return str_starts_with($normalizedPath, $normalizedBase) && !str_contains($normalizedPath, '/../');
    }

    private function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || file_put_contents($file, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('JSON-Datei konnte nicht geschrieben werden: ' . $file);
        }
    }

    /** @param list<string> $files @param list<string> $dirs */
    private function rollback(array $files, array $dirs): void
    {
        foreach (array_reverse($files) as $file) {
            if (is_file($file)) { @unlink($file); }
        }
        usort($dirs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        foreach ($dirs as $dir) {
            if (is_dir($dir)) { @rmdir($dir); }
        }
    }
}
