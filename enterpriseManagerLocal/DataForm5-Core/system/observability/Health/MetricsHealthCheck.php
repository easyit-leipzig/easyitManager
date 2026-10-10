<?php
declare(strict_types=1);
namespace DataForm5\Observability\Health;
use DataForm5\Health\Contracts\HealthCheckInterface;
use DataForm5\Health\Core\{HealthResult,HealthStatus};
use DataForm5\Observability\Core\MetricsManager;
final class MetricsHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly MetricsManager $metrics){}
    public function name():string{return 'observability.metrics';}
    public function run():HealthResult{return new HealthResult($this->name(),$this->metrics->healthy()?HealthStatus::UP:HealthStatus::DOWN,$this->metrics->healthy()?'Metrikspeicher erreichbar.':'Metrikspeicher nicht erreichbar.',['store_healthy'=>$this->metrics->healthy()]);}
}
