<?php
declare(strict_types=1);
namespace DataForm5\OpenApi\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\OpenApi\Contracts\OpenApiGeneratorInterface;use DataForm5\OpenApi\Core\OpenApiExporter;use DataForm5\OpenApi\Core\OpenApiGenerator;
final class OpenApiServiceProvider implements ServiceProviderInterface
{
 public function register(ServiceContainer $c):void{$c->singleton(OpenApiGenerator::class,fn()=>new OpenApiGenerator());$c->singleton(OpenApiGeneratorInterface::class,fn(ServiceContainer $c)=>$c->get(OpenApiGenerator::class));$c->singleton(OpenApiExporter::class,fn(ServiceContainer $c)=>new OpenApiExporter($c->get(OpenApiGenerator::class)));}
 public function boot(ServiceContainer $c):void{}
}
