<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Core;
use DataForm5\Workflow\Exceptions\WorkflowException;
final class Transition
{
    public function __construct(
        public readonly string $name,
        public readonly array $from,
        public readonly string $to,
        public readonly array $guards = [],
        public readonly array $actions = []
    ) {
        if ($name === '' || $to === '' || $from === []) {
            throw new WorkflowException('Übergang benötigt Name, Ausgangszustand und Zielzustand.');
        }
    }
    public function accepts(string $state): bool { return in_array($state, $this->from, true); }
}
