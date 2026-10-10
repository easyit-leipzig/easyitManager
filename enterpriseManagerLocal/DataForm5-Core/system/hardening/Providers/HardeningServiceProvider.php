<?php
declare(strict_types=1);
namespace DataForm5\Hardening\Providers;
use DataForm5\Core\Config;use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Core\Support\Path;use DataForm5\Hardening\Contracts\ProductionGateInterface;use DataForm5\Hardening\Core\ProductionGate;use DataForm5\Hardening\Middleware\SecurityHeadersMiddleware;
final class HardeningServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$c->singleton(ProductionGate::class,fn(ServiceContainer $c)=>new ProductionGate($c->get(Config::class),$c->get(Path::class)));$c->singleton(ProductionGateInterface::class,fn(ServiceContainer $c)=>$c->get(ProductionGate::class));$c->singleton(SecurityHeadersMiddleware::class,function(ServiceContainer $c){$cfg=$c->get(Config::class);return new SecurityHeadersMiddleware((array)$cfg->get('hardening.headers',[]));});}
 public function boot(ServiceContainer $c):void{}
}
