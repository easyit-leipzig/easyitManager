<?php
declare(strict_types=1);
namespace DataForm5\Observability\Providers;
use DataForm5\Core\Config;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
use DataForm5\Core\Support\Path;
use DataForm5\Health\Core\HealthManager;
use DataForm5\Observability\Contracts\{MetricStoreInterface,MetricsInterface};
use DataForm5\Observability\Core\MetricsManager;
use DataForm5\Observability\Health\MetricsHealthCheck;
use DataForm5\Observability\Stores\{InMemoryMetricStore,JsonFileMetricStore};
final class ObservabilityServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void
    {
        $c->singleton(MetricStoreInterface::class,static function(ServiceContainer $c):MetricStoreInterface{$config=$c->get(Config::class);$driver=(string)$config->get('observability.driver','file');return match($driver){'memory'=>new InMemoryMetricStore(),'file'=>new JsonFileMetricStore($c->get(Path::class)->base((string)$config->get('observability.file','storage/observability/metrics.json'))),default=>throw new \DataForm5\Observability\Exceptions\ObservabilityException("Unbekannter Observability-Treiber '{$driver}'.")};});
        $c->singleton(MetricsManager::class,static fn(ServiceContainer $c)=>new MetricsManager($c->get(MetricStoreInterface::class),(array)$c->get(Config::class)->get('observability.default_tags',[])));
        $c->singleton(MetricsInterface::class,static fn(ServiceContainer $c)=>$c->get(MetricsManager::class));
    }
    public function boot(ServiceContainer $c):void
    {
        if($c->has(HealthManager::class)&&(bool)$c->get(Config::class)->get('observability.health_check',true))$c->get(HealthManager::class)->register(new MetricsHealthCheck($c->get(MetricsManager::class)),readiness:true,liveness:false);
    }
}
