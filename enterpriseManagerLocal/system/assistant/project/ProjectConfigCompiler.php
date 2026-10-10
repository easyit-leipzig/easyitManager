<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Project;

final class ProjectConfigCompiler
{
    public function compile(ProjectDraft $draft): array
    {
        $d = $draft->toArray();
        $driver = (string) $d['storage']['driver'];
        return [
            'schema' => 'easyit.project.assistant.v1',
            'project' => [
                'name' => (string) $d['identity']['name'],
                'id' => (string) $d['identity']['slug'],
                'description' => (string) $d['identity']['description'],
                'path' => (string) $d['storage']['projectPath'],
            ],
            'storage' => [
                'driver' => $driver,
                'mode' => (string) $d['storage']['dataMode'],
                'localPath' => (string) $d['dataSource']['localPath'],
            ],
            'dataSourceProfile' => [
                'name' => (string) $d['dataSource']['profileName'],
                'driver' => $driver,
                'databaseName' => (string) $d['dataSource']['databaseName'],
                'schemaName' => $driver === 'pgsql' ? (string)($d['dataSource']['schemaName'] ?? 'public') : '',
                'localPath' => (string) $d['dataSource']['localPath'],
                'requiresConnectionConfiguration' => (bool) $d['dataSource']['requiresConnectionConfiguration'],
                'plaintextPasswordStored' => false,
            ],
            'directories' => $d['structure'],
            'provisioning' => [
                'created' => (bool) $d['provision']['created'],
                'createdAt' => $d['provision']['createdAt'],
            ],
            'assistantFlow' => [
                'next' => 'datasource.configure',
                'then' => 'dataform.create',
            ],
        ];
    }
}
