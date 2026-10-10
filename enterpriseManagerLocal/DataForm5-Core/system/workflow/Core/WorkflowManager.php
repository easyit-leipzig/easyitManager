<?php
declare(strict_types=1);
namespace DataForm5\Workflow\Core;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Workflow\Contracts\{WorkflowActionInterface,WorkflowGuardInterface,WorkflowStoreInterface};
use DataForm5\Workflow\Exceptions\WorkflowException;
use Throwable;
final class WorkflowManager
{
    private array $definitions = [];
    public function __construct(private WorkflowStoreInterface $store, private ServiceContainer $container) {}
    public function register(WorkflowDefinition $definition): self { $this->definitions[$definition->name] = $definition; return $this; }
    public function start(string $workflow, string $id, array $data = []): WorkflowInstance
    {
        if ($this->store->find($id) !== null) throw new WorkflowException("Workflow-Instanz '{$id}' existiert bereits.");
        $definition = $this->definition($workflow);
        $instance = new WorkflowInstance($id, $workflow, $definition->initialState, $data);
        $this->store->save($instance);
        $this->store->appendHistory($id, ['type'=>'started','state'=>$instance->state(),'at'=>gmdate(DATE_ATOM),'version'=>$instance->version()]);
        return $instance;
    }
    public function find(string $id): ?WorkflowInstance { return $this->store->find($id); }
    public function history(string $id): array { return $this->store->history($id); }
    public function available(string $id): array
    {
        $instance = $this->requireInstance($id);
        return array_map(static fn(Transition $t): string => $t->name, $this->definition($instance->workflow)->availableFrom($instance->state()));
    }
    public function can(string $id, string $transitionName, array $context = []): bool
    {
        try {
            $instance = $this->requireInstance($id);
            $transition = $this->definition($instance->workflow)->getTransition($transitionName);
            return $transition->accepts($instance->state()) && $this->guardsAllow($transition, $instance, $context);
        } catch (WorkflowException) { return false; }
    }
    public function apply(string $id, string $transitionName, array $context = []): TransitionResult
    {
        $instance = $this->requireInstance($id);
        $transition = $this->definition($instance->workflow)->getTransition($transitionName);
        $from = $instance->state();
        if (!$transition->accepts($from)) throw new WorkflowException("Übergang '{$transitionName}' ist aus Zustand '{$from}' nicht möglich.");
        if (!$this->guardsAllow($transition, $instance, $context)) throw new WorkflowException("Guard blockiert Übergang '{$transitionName}'.");
        try {
            foreach ($transition->actions as $action) $this->executeAction($action, $instance, $context);
            $instance->moveTo($transition->to, $context['data'] ?? []);
            $this->store->save($instance);
            $this->store->appendHistory($id, ['type'=>'transition','transition'=>$transitionName,'from'=>$from,'to'=>$transition->to,'at'=>gmdate(DATE_ATOM),'version'=>$instance->version(),'context'=>$context]);
        } catch (Throwable $e) {
            $this->store->appendHistory($id, ['type'=>'failed','transition'=>$transitionName,'from'=>$from,'at'=>gmdate(DATE_ATOM),'error'=>$e->getMessage()]);
            throw $e;
        }
        return new TransitionResult($instance, $transitionName, $from, $transition->to);
    }
    private function definition(string $name): WorkflowDefinition { return $this->definitions[$name] ?? throw new WorkflowException("Workflow '{$name}' ist nicht registriert."); }
    private function requireInstance(string $id): WorkflowInstance { return $this->store->find($id) ?? throw new WorkflowException("Workflow-Instanz '{$id}' wurde nicht gefunden."); }
    private function guardsAllow(Transition $transition, WorkflowInstance $instance, array $context): bool
    {
        foreach ($transition->guards as $guard) {
            $resolved = is_string($guard) ? $this->container->get($guard) : $guard;
            $allowed = $resolved instanceof WorkflowGuardInterface ? $resolved->allows($instance, $context) : (is_callable($resolved) ? $resolved($instance, $context, $this->container) : false);
            if (!$allowed) return false;
        }
        return true;
    }
    private function executeAction(mixed $action, WorkflowInstance $instance, array $context): void
    {
        $resolved = is_string($action) ? $this->container->get($action) : $action;
        if ($resolved instanceof WorkflowActionInterface) { $resolved->execute($instance, $context); return; }
        if (is_callable($resolved)) { $resolved($instance, $context, $this->container); return; }
        throw new WorkflowException('Workflow-Aktion ist nicht aufrufbar.');
    }
}
