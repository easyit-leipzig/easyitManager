<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Contracts;
use DataForm5\Workflow\Core\WorkflowInstance;
interface WorkflowGuardInterface
{
    public function allows(WorkflowInstance $instance, array $context = []): bool;
}
