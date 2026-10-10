<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Core;
final class TransitionResult
{
    public function __construct(
        public readonly WorkflowInstance $instance,
        public readonly string $transition,
        public readonly string $from,
        public readonly string $to
    ) {}
}
