<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Core;
use DataForm5\Workflow\Exceptions\WorkflowException;
final class WorkflowDefinition
{
    private array $transitions = [];
    public function __construct(
        public readonly string $name,
        public readonly string $initialState,
        public readonly array $states
    ) {
        if ($name === '' || $states === [] || !in_array($initialState, $states, true)) {
            throw new WorkflowException('Ungültige Workflow-Definition.');
        }
    }
    public function transition(Transition $transition): self
    {
        foreach ([...$transition->from, $transition->to] as $state) {
            if (!in_array($state, $this->states, true)) {
                throw new WorkflowException("Unbekannter Zustand '{$state}'.");
            }
        }
        $this->transitions[$transition->name] = $transition;
        return $this;
    }
    public function getTransition(string $name): Transition
    {
        return $this->transitions[$name] ?? throw new WorkflowException("Unbekannter Übergang '{$name}'.");
    }
    public function availableFrom(string $state): array
    {
        return array_values(array_filter($this->transitions, static fn(Transition $t): bool => $t->accepts($state)));
    }
}
