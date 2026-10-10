<?php
declare(strict_types=1);
namespace DataForm5\Context\Core;
use DataForm5\Context\Contracts\ContextManagerInterface;
use DataForm5\Context\Exceptions\ContextException;
final class ContextManager implements ContextManagerInterface
{
    private ?ProjectContext $current = null;
    public function current(): ?ProjectContext { return $this->current; }
    public function activate(ProjectContext $context): void { $this->current = $context; }
    public function clear(): void { $this->current = null; }
    public function requireCurrent(): ProjectContext { return $this->current ?? throw new ContextException('Kein Projektkontext ist aktiv.'); }
    public function run(ProjectContext $context, callable $callback): mixed
    {
        $previous = $this->current;
        $this->current = $context;
        try { return $callback($context); } finally { $this->current = $previous; }
    }
    public function assertMatches(string $tenantId, string $projectId): void
    {
        $active = $this->requireCurrent();
        if ($active->tenantId() !== $tenantId || $active->projectId() !== $projectId) {
            throw new ContextException('Zugriff außerhalb des aktiven Mandanten- oder Projektkontexts wurde blockiert.');
        }
    }
    public function scopedKey(string $key): string { return $this->requireCurrent()->key() . ':' . ltrim($key, ':'); }
    public function scopedPath(string $path = ''): string { return rtrim($this->requireCurrent()->storageSegment() . '/' . ltrim($path, '/'), '/'); }
}
