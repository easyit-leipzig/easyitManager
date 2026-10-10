<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Contracts;
use DataForm5\Workflow\Core\WorkflowInstance;
interface WorkflowStoreInterface
{
    public function save(WorkflowInstance $instance): void;
    public function find(string $id): ?WorkflowInstance;
    public function history(string $id): array;
    public function appendHistory(string $id, array $entry): void;
}
