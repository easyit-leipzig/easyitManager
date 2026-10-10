<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Core;
final class WorkflowInstance
{
    public function __construct(
        public readonly string $id,
        public readonly string $workflow,
        private string $state,
        private array $data = [],
        private int $version = 1
    ) {}
    public function state(): string { return $this->state; }
    public function data(): array { return $this->data; }
    public function version(): int { return $this->version; }
    public function moveTo(string $state, array $data = []): void
    {
        $this->state = $state;
        $this->data = array_replace_recursive($this->data, $data);
        $this->version++;
    }
}
