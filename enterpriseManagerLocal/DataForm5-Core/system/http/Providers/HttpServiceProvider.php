<?php
declare(strict_types=1);
namespace DataForm5\Http\Providers;
use DataForm5\Core\Container\ServiceContainer; use DataForm5\Core\Contracts\ServiceProviderInterface; use DataForm5\Http\Core\HttpKernel; use DataForm5\Http\Core\Router;
final class HttpServiceProvider implements ServiceProviderInterface { public function register(ServiceContainer $c):void{$c->singleton(Router::class,fn(ServiceContainer $c)=>new Router($c));$c->singleton(HttpKernel::class,fn(ServiceContainer $c)=>new HttpKernel($c->get(Router::class)));} public function boot(ServiceContainer $c):void{} }
