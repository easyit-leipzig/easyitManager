<?php
declare(strict_types=1);
namespace DataForm5\Api\Providers;
use DataForm5\Api\Core\ApiResponse;
use DataForm5\Core\Container\ServiceContainer;
use DataForm5\Core\Contracts\ServiceProviderInterface;
final class ApiServiceProvider implements ServiceProviderInterface { public function register(ServiceContainer $c):void{$c->singleton(ApiResponse::class,fn()=>new ApiResponse());} public function boot(ServiceContainer $c):void{} }
