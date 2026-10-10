<?php
declare(strict_types=1);
namespace DataForm5\Context\Core;
use InvalidArgumentException;
final class ProjectContext
{
    public function __construct(
        private readonly string $tenantId,
        private readonly string $projectId,
        private readonly string $projectName = '',
        private readonly array $metadata = []
    ) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $tenantId) || !preg_match('/^[A-Za-z0-9._-]+$/', $projectId)) {
            throw new InvalidArgumentException('Mandanten- und Projekt-ID dürfen nur Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.');
        }
    }
    public function tenantId(): string { return $this->tenantId; }
    public function projectId(): string { return $this->projectId; }
    public function projectName(): string { return $this->projectName; }
    public function metadata(?string $key = null, mixed $default = null): mixed { return $key === null ? $this->metadata : ($this->metadata[$key] ?? $default); }
    public function key(): string { return $this->tenantId . ':' . $this->projectId; }
    public function storageSegment(): string { return $this->tenantId . '/' . $this->projectId; }
    public function databaseConnection(): string { return (string)($this->metadata['database_connection'] ?? 'project'); }
    public function toArray(): array { return ['tenant_id'=>$this->tenantId,'project_id'=>$this->projectId,'project_name'=>$this->projectName,'metadata'=>$this->metadata]; }
}
