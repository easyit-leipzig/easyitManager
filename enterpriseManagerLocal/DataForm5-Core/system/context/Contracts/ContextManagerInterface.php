<?php
declare(strict_types=1);
namespace DataForm5\Context\Contracts;
use DataForm5\Context\Core\ProjectContext;
interface ContextManagerInterface
{
    public function current(): ?ProjectContext;
    public function activate(ProjectContext $context): void;
    public function clear(): void;
    public function requireCurrent(): ProjectContext;
    public function run(ProjectContext $context, callable $callback): mixed;
}
