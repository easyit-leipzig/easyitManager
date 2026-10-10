<?php
declare(strict_types=1);
namespace DataForm5\Documentation\Providers;
use DataForm5\Core\Container\ServiceContainer;use DataForm5\Core\Contracts\ServiceProviderInterface;use DataForm5\Documentation\Core\{ComponentCatalog,DocumentationGenerator};
final class DocumentationServiceProvider implements ServiceProviderInterface
{
    public function register(ServiceContainer $c):void{$base=dirname(__DIR__,3);$c->singleton(ComponentCatalog::class,fn()=>new ComponentCatalog($base));$c->singleton(DocumentationGenerator::class,fn(ServiceContainer $c)=>new DocumentationGenerator($base,$c->get(ComponentCatalog::class)));}
    public function boot(ServiceContainer $c):void{}
}
