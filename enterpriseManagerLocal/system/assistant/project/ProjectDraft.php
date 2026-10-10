<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Project;

final class ProjectDraft implements \JsonSerializable
{
    public function __construct(private array $data = [])
    {
        $this->data = array_replace_recursive(self::defaults(), $data);
    }

    public static function defaults(): array
    {
        return [
            'identity' => [
                'name' => '',
                'slug' => '',
                'description' => '',
            ],
            'storage' => [
                'driver' => 'mysql',
                'projectsRoot' => 'projects',
                'projectPath' => '',
                'dataMode' => 'external',
            ],
            'dataSource' => [
                'profileName' => 'main',
                'driver' => 'mysql',
                'databaseName' => '',
                'schemaName' => 'public',
                'localPath' => '',
                'requiresConnectionConfiguration' => true,
            ],
            'structure' => [
                'configDir' => 'config',
                'dataDir' => 'data',
                'storageDir' => 'storage',
                'logsDir' => 'logs',
                'backupsDir' => 'backups',
            ],
            'provision' => [
                'confirmCreate' => false,
                'created' => false,
                'createdAt' => null,
                'projectAbsolutePath' => null,
                'createdDirectories' => [],
                'createdFiles' => [],
                'message' => 'Noch nicht angelegt.',
            ],
        ];
    }

    public function merge(array $changes): self
    {
        return new self(array_replace_recursive($this->data, $changes));
    }

    public function toArray(): array { return $this->data; }
    public function jsonSerialize(): array { return $this->data; }
}
