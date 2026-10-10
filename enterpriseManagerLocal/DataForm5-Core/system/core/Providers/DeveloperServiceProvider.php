<?php
declare(strict_types=1);

namespace DataForm5\Core\Providers;

use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Developer\DeveloperInspector;
use DataForm5\Core\Developer\DeveloperTrace;
use DataForm5\Core\Developer\ContainerInspector;
use DataForm5\Core\Developer\EventInspector;
use DataForm5\Core\Developer\HookInspector;
use DataForm5\Core\Developer\RequestProfiler;
use DataForm5\Modules\Core\ModuleManager;
use DataForm5\Events\Core\EventDispatcher;
use DataForm5\Modules\Background\ModuleBackgroundAdmin;
use DataForm5\Scheduler\Core\Scheduler;

final class DeveloperServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $c->singleton(RequestProfiler::class,fn(ServiceContainer $c)=>new RequestProfiler(
            (bool)$c->get(Config::class)->get('developer.enabled',false),
            (int)$c->get(Config::class)->get('developer.profiler_max_records',500)
        ));
        $c->singleton(DeveloperTrace::class,fn(ServiceContainer $c)=>new DeveloperTrace(
            (int)$c->get(Config::class)->get('developer.max_events',100)
        ));
        $c->singleton(ContainerInspector::class,fn(ServiceContainer $c)=>new ContainerInspector($c));
        $c->singleton(HookInspector::class,fn(ServiceContainer $c)=>new HookInspector(
            $c->get(ModuleManager::class),
            $c->get(DeveloperTrace::class)
        ));
        $c->singleton(EventInspector::class,fn(ServiceContainer $c)=>new EventInspector(
            enterprise_events(),
            enterprise_event_catalog(),
            $c->get(DeveloperTrace::class)
        ));
        $c->singleton(DeveloperInspector::class,fn(ServiceContainer $c)=>new DeveloperInspector(
            $c,
            $c->get(ModuleManager::class),
            $c->get(EventDispatcher::class),
            $c->get(ModuleBackgroundAdmin::class),
            $c->get(Scheduler::class),
            $c->get(DeveloperTrace::class)
        ));
    }

    public function boot(ServiceContainer $c): void { $c->get(RequestProfiler::class); }
}
