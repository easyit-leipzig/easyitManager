<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Project;

final class ProjectProvisioningResult implements \JsonSerializable
{
    /** @param list<string> $directories @param list<string> $files */
    public function __construct(
        private bool $ok,
        private string $message,
        private ?string $projectAbsolutePath = null,
        private array $directories = [],
        private array $files = []
    ) {}

    public function isOk(): bool { return $this->ok; }
    public function getMessage(): string { return $this->message; }
    public function getProjectAbsolutePath(): ?string { return $this->projectAbsolutePath; }
    public function getDirectories(): array { return $this->directories; }
    public function getFiles(): array { return $this->files; }

    public function jsonSerialize(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'projectAbsolutePath' => $this->projectAbsolutePath,
            'directories' => $this->directories,
            'files' => $this->files,
        ];
    }
}
