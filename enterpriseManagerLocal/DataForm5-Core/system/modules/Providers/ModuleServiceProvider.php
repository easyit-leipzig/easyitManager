<?php
declare(strict_types=1);
namespace DataForm5\Modules\Providers;
use DataForm5\Modules\SDK\{ModuleQualityValidator,ModulePackager};
use DataForm5\Modules\SDK\{SdkGenerator,CrudScaffolder};
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Support\Path;
use DataForm5\Modules\Core\HookDispatcher;
use DataForm5\Modules\Core\ModuleManager;
use DataForm5\Modules\Core\ModuleRegistry;
use DataForm5\Modules\SDK\ModuleValidator;
use DataForm5\Modules\Packages\ModulePackageRegistry;
use DataForm5\Modules\Packages\ModuleArchiveExtractor;
use DataForm5\Modules\Packages\ModulePackageInstaller;
use DataForm5\Modules\Packages\ModulePackageBuilder;
use DataForm5\Core\Filesystem\Filesystem;
use DataForm5\Modules\Versioning\ModuleCompatibility;
use DataForm5\Modules\Routing\ModuleRouteRegistry;
use DataForm5\Modules\Routing\ModuleRouteDispatcher;
use DataForm5\Modules\Routing\ModuleApiRegistry;
use DataForm5\Modules\Routing\ModuleApiDispatcher;
use DataForm5\Api\Core\ApiResponse;
use DataForm5\Modules\Lifecycle\ModuleLifecycleManager;
use DataForm5\Modules\Lifecycle\ModuleLifecycleLogger;
use DataForm5\Events\Contracts\EventDispatcherInterface;
use DataForm5\Modules\Background\ModuleBackgroundRegistry;
use DataForm5\Modules\Background\ModuleBackgroundLogger;
use DataForm5\Modules\Background\ModuleBackgroundAdmin;
use DataForm5\Modules\Background\ModuleQueuedJob;
use DataForm5\Queue\Core\QueueDispatcher;
use DataForm5\Scheduler\Core\Scheduler;
use DataForm5\Scheduler\Core\ScheduleHistory;
use DataForm5\Queue\Core\QueueManager;
use DataForm5\Queue\Core\QueueWorker;
use DataForm5\Cache\Core\CacheManager;
use DataForm5\Cache\Core\CacheNamespace;
final class ModuleServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $container): void
    {
        $container->singleton(ModuleRegistry::class);
        $container->singleton(SdkGenerator::class,fn()=>new SdkGenerator(dirname(__DIR__,4)));
        $container->singleton(ModuleQualityValidator::class,fn()=>new ModuleQualityValidator(dirname(__DIR__,4)));
        $container->singleton(ModulePackager::class,fn(ServiceContainer $c)=>new ModulePackager(dirname(__DIR__,4),$c->get(ModuleQualityValidator::class)));
        $container->singleton(CrudScaffolder::class,fn()=>new CrudScaffolder(dirname(__DIR__,4)));
        $container->singleton(HookDispatcher::class);
        $container->singleton(ModuleManager::class, fn(ServiceContainer $c)=>new ModuleManager(
            $c,$c->get(ModuleRegistry::class),new CacheNamespace($c->get(CacheManager::class)->store(),'modules')
        ));
        $container->singleton(ModuleValidator::class);
        $container->singleton(ModuleLifecycleLogger::class, fn(ServiceContainer $c)=>new ModuleLifecycleLogger(dirname(__DIR__,4).'/storage/logs/modules'));
        $container->singleton(ModuleLifecycleManager::class, fn(ServiceContainer $c)=>new ModuleLifecycleManager($c,$c->get(EventDispatcherInterface::class),$c->get(ModuleLifecycleLogger::class)));
        $container->singleton(ModuleRouteRegistry::class, fn(ServiceContainer $c)=>new ModuleRouteRegistry($c->get(ModuleManager::class)));
        $container->singleton(ModuleRouteDispatcher::class, fn(ServiceContainer $c)=>new ModuleRouteDispatcher($c,$c->get(ModuleRouteRegistry::class),$c->get(ModuleLifecycleManager::class)));
        $container->singleton(ModuleApiRegistry::class, fn(ServiceContainer $c)=>new ModuleApiRegistry($c->get(ModuleManager::class)));
        $container->singleton(ModuleApiDispatcher::class, fn(ServiceContainer $c)=>new ModuleApiDispatcher($c,$c->get(ModuleApiRegistry::class),$c->build(ApiResponse::class),$c->get(ModuleLifecycleManager::class)));
        $container->singleton(ModuleArchiveExtractor::class, fn(ServiceContainer $c)=>new ModuleArchiveExtractor($c->get(Filesystem::class)));
        $container->singleton(ModuleCompatibility::class, fn(ServiceContainer $c)=>new ModuleCompatibility((string)@file_get_contents(dirname(__DIR__,4).'/VERSION') ?: '0.0.0'));
        $container->singleton(ModulePackageRegistry::class, function(ServiceContainer $c){
            $config=$c->get(Config::class); return new ModulePackageRegistry((string)$config->get('modules.package_registry',dirname(__DIR__,5).'/modules/.installed.json'),$c->get(Filesystem::class));
        });
        $container->singleton(ModulePackageInstaller::class, function(ServiceContainer $c){
            $config=$c->get(Config::class); return new ModulePackageInstaller((string)$config->get('modules.enterprise_install_path',dirname(__DIR__,5).'/modules'),(string)$config->get('modules.package_temp',dirname(__DIR__,4).'/storage/framework/module-packages'),$c->get(Filesystem::class),$c->get(ModuleValidator::class),$c->get(ModulePackageRegistry::class),$c->get(ModuleArchiveExtractor::class),null,$c->get(ModuleCompatibility::class),null,$c->get(ModuleLifecycleManager::class));
        });
        $container->singleton(ModulePackageBuilder::class, fn(ServiceContainer $c)=>new ModulePackageBuilder($c->get(ModuleValidator::class)));
        $container->singleton(ModuleBackgroundLogger::class, fn(ServiceContainer $c)=>new ModuleBackgroundLogger(dirname(__DIR__,4).'/storage/logs/modules/background.log'));
        $container->bind(ModuleQueuedJob::class);
        $container->singleton(ModuleBackgroundRegistry::class, fn(ServiceContainer $c)=>new ModuleBackgroundRegistry($c->get(ModuleManager::class),$c->get(QueueDispatcher::class),$c->get(ModuleBackgroundLogger::class)));
        $container->singleton(ModuleBackgroundAdmin::class, fn(ServiceContainer $c)=>new ModuleBackgroundAdmin($c->get(ModuleBackgroundRegistry::class),$c->get(QueueManager::class),$c->get(QueueWorker::class),$c->get(Scheduler::class),$c->get(ScheduleHistory::class)));
    }
    public function boot(ServiceContainer $container): void
    {
        $config=$container->get(Config::class); if(!(bool)$config->get('modules.enabled',true)) return;
        $path=$container->get(Path::class);
        $configured=$config->get('modules.paths',null);
        $paths=is_array($configured) ? array_values(array_filter($configured,'is_string')) : [];
        if($paths===[]) $paths=[(string)$config->get('modules.path',$path->base('modules'))];
        $manager=$container->get(ModuleManager::class);
        $manager->discoverMany($paths)->loadEnabled();
        $manager->bootLoaded();
        $container->get(ModuleBackgroundRegistry::class)->registerSchedules($container->get(Scheduler::class));
    }
}
