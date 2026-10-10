<?php
declare(strict_types=1);
namespace DataForm5\Performance\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Config;use DataForm5\Performance\Contracts\ProfilerInterface;use DataForm5\Performance\Core\PerformanceReportWriter;use DataForm5\Performance\Core\PerformanceThresholds;use DataForm5\Performance\Core\Profiler;
final class PerformanceServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void
 {
  $c->singleton(PerformanceThresholds::class,function(ServiceContainer $c){$cfg=$c->get(Config::class);return new PerformanceThresholds((array)$cfg->get('performance.thresholds',[]));});
  $c->singleton(Profiler::class,function(ServiceContainer $c){$cfg=$c->get(Config::class);return new Profiler($c->get(PerformanceThresholds::class),(bool)$cfg->get('performance.enabled',true),(int)$cfg->get('performance.max_entries',1000));});
  $c->singleton(ProfilerInterface::class,fn(ServiceContainer $c)=>$c->get(Profiler::class));
  $c->singleton(PerformanceReportWriter::class,fn(ServiceContainer $c)=>new PerformanceReportWriter($c->get(ProfilerInterface::class)));
 }
 public function boot(ServiceContainer $c):void{}
}
