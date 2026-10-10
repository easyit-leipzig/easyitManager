<?php
declare(strict_types=1);
namespace DataForm5\Monitoring\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Kernel;
use DataForm5\Monitoring\Core\{AlertManager,HealthManager,MetricsCollector,MetricsStorage,WorkerHeartbeat};
final class MonitoringServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c): void
    {
        $paths=static function(ServiceContainer $c): array{
            $cfg=(array)$c->get(Config::class)->get('monitoring',[]);$base=$c->get(Kernel::class)->basePath();
            $abs=static fn(string $p)=>str_starts_with($p,'/')||preg_match('/^[A-Za-z]:[\\\\\/]/',$p)?$p:$base.'/'.ltrim($p,'/\\');
            return [$cfg,$abs((string)($cfg['snapshot_path']??'storage/framework/monitoring')),$abs((string)($cfg['log_path']??'storage/logs/monitoring'))];
        };
        $c->singleton(WorkerHeartbeat::class,fn(ServiceContainer $c)=>new WorkerHeartbeat($paths($c)[1].'/heartbeats'));
        $c->singleton(MetricsStorage::class,fn(ServiceContainer $c)=>new MetricsStorage($paths($c)[1]));
        $c->singleton(MetricsCollector::class);
        $c->singleton(AlertManager::class,function(ServiceContainer $c) use($paths){[$cfg,,$log]=$paths($c);return new AlertManager((array)($cfg['thresholds']??[]),$log.'/alerts.log');});
        $c->singleton(HealthManager::class);
    }
    public function boot(ServiceContainer $c): void {}
}
