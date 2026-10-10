<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Contracts;
use DataForm5\Workflow\Core\WorkflowInstance;
interface WorkflowActionInterface
{
    public function execute(WorkflowInstance $instance, array $context = []): void;
}
