<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Stores;
use DataForm5\Workflow\Contracts\WorkflowStoreInterface;
use DataForm5\Workflow\Core\WorkflowInstance;
final class InMemoryWorkflowStore implements WorkflowStoreInterface
{
    private array $items = [];
    private array $history = [];
    public function save(WorkflowInstance $instance): void { $this->items[$instance->id] = $instance; }
    public function find(string $id): ?WorkflowInstance { return $this->items[$id] ?? null; }
    public function history(string $id): array { return $this->history[$id] ?? []; }
    public function appendHistory(string $id, array $entry): void { $this->history[$id][] = $entry; }
}
