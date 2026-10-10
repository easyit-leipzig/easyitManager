<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Providers;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Workflow\Contracts\WorkflowStoreInterface;
use DataForm5\Workflow\Core\WorkflowManager;
use DataForm5\Workflow\Stores\InMemoryWorkflowStore;
final class WorkflowServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(WorkflowStoreInterface::class, static fn(): InMemoryWorkflowStore => new InMemoryWorkflowStore());
        $container->singleton(WorkflowManager::class, static fn(ServiceContainer $c): WorkflowManager => new WorkflowManager($c->get(WorkflowStoreInterface::class), $c));
    }
    public function boot(ServiceContainer $container): void {}
}
