<?php
declare(strict_types=1);

namespace EasyIT\Assistant\Recovery;

final class ArchiveOperationResult implements \JsonSerializable
{
    /** @param list<string> $warnings */
    public function __construct(
        private bool $ok,
        private string $message,
        private ?string $path = null,
        private ?string $sha256 = null,
        private array $warnings = [],
        private array $details = []
    ) {}

    public function isOk(): bool { return $this->ok; }
    public function getMessage(): string { return $this->message; }
    public function getPath(): ?string { return $this->path; }
    public function getSha256(): ?string { return $this->sha256; }
    public function getWarnings(): array { return $this->warnings; }
    public function getDetails(): array { return $this->details; }

    public function jsonSerialize(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'path' => $this->path,
            'fileName' => $this->path !== null ? basename($this->path) : null,
            'sha256' => $this->sha256,
            'warnings' => $this->warnings,
            'details' => $this->details,
        ];
    }
}
